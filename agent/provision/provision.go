// Package provision est le moteur GÉNÉRIQUE et OS-AGNOSTIQUE de mise à
// disposition de RESSOURCES de support sur le poste (Story 27.20).
//
// Séparation des responsabilités (modèle desired-state du projet, leçon
// applications/wpkg « impératif vs déclaratif ») :
//
//   - Le SERVEUR est DÉCLARATIF : il énumère le QUOI (quelles ressources, d'où
//     les télécharger, quel hash attendu) dans un `manifest.json`.
//   - L'AGENT est IMPÉRATIF : il résout la cible selon l'OS, COMPARE par hash,
//     télécharge/pose/applique les perms UNIQUEMENT en cas de dérive, puis
//     rapporte un Outcome par ressource.
//
// Aucune notion « WPKG » ici : un `7za.exe` est une Resource `kind:"wpkg-tool"`,
// un futur AppImage serait `kind:"appimage"` — MÊME moteur. Le COMMENT (où
// atterrit chaque ressource) est délégué à un TargetResolver dépendant de l'OS
// (un adaptateur Windows est fourni ; un resolver Linux s'ajoute sans toucher
// Reconcile). GARDE-LE SIMPLE : mainteneurs non-devs.
//
// Ce package ne dépend QUE de la bibliothèque standard : entièrement testable
// sur l'hôte Linux (`go test ./agent/provision`). Le seul spécifique OS vit
// dans provision_windows.go (build tag).
package provision

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Resource décrit UNE ressource à mettre à disposition sur le poste. Champs
// alignés sur le `manifest.json` généré côté serveur (id, kind, relpath, sha256,
// executable) ; l'agent compose l'URL absolue depuis une base (BaseURL) + RelPath
// — le manifeste ne porte PAS d'URL absolue (le serveur ignore le FQDN du poste).
type Resource struct {
	ID         string // identifiant lisible (diagnostic/log), ex. "7za.exe".
	Kind       string // ex. "wpkg-tool" — sélectionne le placement via le resolver.
	RelPath    string // chemin relatif sous la racine du kind (sous-arbo préservée, ex. "tooltip/wpkg-msg.exe").
	URL        string // URL absolue de téléchargement (composée par l'appelant : base + RelPath).
	SHA256     string // hash hex attendu (minuscules) — clé d'idempotence.
	Executable bool   // pose le bit exécutable après dépôt (no-op significatif sur Windows).
}

// TargetResolver résout, pour une Resource, le chemin ABSOLU local où elle doit
// atterrir, en GARANTISSANT l'existence du dossier parent (MkdirAll, + toute
// matérialisation OS-spécifique, ex. reparse point SMB pendouillant sur Windows).
// Interface = point d'extension : un resolver Linux s'ajoute sans toucher
// Reconcile (aucune implémentation Linux n'est livrée — agent Linux post-MVP).
type TargetResolver interface {
	Resolve(r Resource) (absPath string, err error)
}

// Status est l'issue de la réconciliation d'une ressource.
type Status string

const (
	// StatusSkipped : fichier déjà présent ET sha256 == attendu (idempotence
	// VRAIE par hash) — aucune écriture.
	StatusSkipped Status = "skipped"
	// StatusApplied : dérive détectée (absent ou hash divergent) → téléchargé,
	// posé atomiquement, perms appliquées.
	StatusApplied Status = "applied"
	// StatusFailed : résolution/téléchargement/écriture en échec — la ressource
	// n'est PAS garantie présente. L'appelant décide (fail-soft pour un outil
	// optionnel).
	StatusFailed Status = "failed"
)

// Outcome est le rapport par ressource (log/diagnostic).
type Outcome struct {
	ResourceID string
	Status     Status
	AbsPath    string // chemin résolu (vide si la résolution a échoué).
	Err        error  // non nil ssi Status == StatusFailed.
}

// httpGet est injectable pour les tests (sinon : http.Get avec timeout).
var httpGet = func(url string) (*http.Response, error) {
	client := &http.Client{Timeout: 60 * time.Second}

	return client.Get(url) //nolint:noctx // GET statique simple, timeout porté par le client.
}

// Reconcile met chaque ressource en conformité (level-triggered, idempotent par
// hash) et renvoie un Outcome par ressource — JAMAIS d'erreur globale : une
// ressource en échec n'empêche pas les suivantes (l'appelant agrège/loggue et
// décide du fail-soft). Pour chaque ressource :
//
//  1. Resolve(r) → chemin absolu + garantie du dossier parent ;
//  2. TEST : le fichier existe ET son sha256 == attendu ? → StatusSkipped ;
//  3. APPLY : télécharger (vérif hash du flux), écrire ATOMIQUEMENT (tmp + rename),
//     poser le bit exécutable si demandé → StatusApplied.
func Reconcile(resources []Resource, tgt TargetResolver) []Outcome {
	outcomes := make([]Outcome, 0, len(resources))
	for _, r := range resources {
		outcomes = append(outcomes, reconcileOne(r, tgt))
	}

	return outcomes
}

func reconcileOne(r Resource, tgt TargetResolver) Outcome {
	want := strings.ToLower(strings.TrimSpace(r.SHA256))

	// SÉCURITÉ (defense-in-depth) : le manifeste est servi en HTTP sans TLS/auth
	// (LAN, v1) — un manifeste forgé (MITM) ne doit JAMAIS faire écrire l'agent
	// hors de la racine du kind. On rejette tout RelPath absolu ou contenant une
	// remontée `..` AVANT de le passer au resolver (protège tous les resolvers,
	// OS-agnostique). Le sha256 reste la garantie d'intégrité du contenu.
	if err := validateRelPath(r.RelPath); err != nil {
		return Outcome{ResourceID: r.ID, Status: StatusFailed, Err: fmt.Errorf("relpath refusé : %w", err)}
	}

	absPath, err := tgt.Resolve(r)
	if err != nil {
		return Outcome{ResourceID: r.ID, Status: StatusFailed, Err: fmt.Errorf("résolution de la cible : %w", err)}
	}

	// TEST — idempotence VRAIE par hash : présent ET à jour → skip (aucune
	// écriture, aucune charge réseau). Un hash attendu vide force toujours l'APPLY
	// (manifeste sans hash = on ne peut pas attester la conformité).
	if want != "" {
		if got, err := fileSHA256(absPath); err == nil && got == want {
			return Outcome{ResourceID: r.ID, Status: StatusSkipped, AbsPath: absPath}
		}
	}

	// APPLY — télécharger en vérifiant le hash, puis écrire atomiquement.
	if err := download(r, absPath, want); err != nil {
		return Outcome{ResourceID: r.ID, Status: StatusFailed, AbsPath: absPath, Err: err}
	}
	if r.Executable {
		// chmod +x : significatif sur Linux ; sur Windows os.Chmod est en grande
		// partie no-op (l'exécutabilité dépend de l'extension), non bloquant.
		_ = os.Chmod(absPath, 0o755)
	}

	return Outcome{ResourceID: r.ID, Status: StatusApplied, AbsPath: absPath}
}

// download récupère r.URL, VÉRIFIE le sha256 du flux (si attendu non vide) AVANT
// de publier, puis écrit ATOMIQUEMENT au chemin résolu (tmp dans le même dossier
// + rename — le consommateur ne voit jamais un fichier à demi écrit ni un fichier
// au mauvais hash). Le tmp est nettoyé sur tout échec.
func download(r Resource, absPath, wantHash string) error {
	resp, err := httpGet(r.URL)
	if err != nil {
		return fmt.Errorf("téléchargement de %s : %w", r.URL, err)
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("téléchargement de %s : statut HTTP %d", r.URL, resp.StatusCode)
	}

	// tmp à nom UNIQUE (os.CreateTemp) dans le dossier cible : pas de collision si
	// deux ressources partageaient un absPath (manifeste à doublons) et pas de
	// blocage par un tmp périmé d'un run tué (un nom PID-based pourrait survivre).
	f, err := os.CreateTemp(filepath.Dir(absPath), "."+filepath.Base(absPath)+".*.tmp")
	if err != nil {
		return fmt.Errorf("création du fichier temporaire pour %s : %w", absPath, err)
	}
	tmp := f.Name()
	renamed := false
	defer func() {
		if !renamed {
			_ = os.Remove(tmp)
		}
	}()
	hasher := sha256.New()
	if _, err := io.Copy(io.MultiWriter(f, hasher), resp.Body); err != nil {
		_ = f.Close()

		return fmt.Errorf("écriture de %s : %w", tmp, err)
	}
	if err := f.Close(); err != nil {
		return fmt.Errorf("fermeture de %s : %w", tmp, err)
	}

	if wantHash != "" {
		got := hex.EncodeToString(hasher.Sum(nil))
		if got != wantHash {
			return fmt.Errorf("hash divergent pour %s : attendu %s, obtenu %s", r.ID, wantHash, got)
		}
	}

	if err := os.Rename(tmp, absPath); err != nil {
		return fmt.Errorf("rename %s → %s : %w", tmp, absPath, err)
	}
	renamed = true

	return nil
}

// fileSHA256 calcule le sha256 hex (minuscules) d'un fichier. Erreur si absent
// ou illisible (l'appelant traite « absent » comme une dérive → APPLY).
func fileSHA256(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer func() { _ = f.Close() }()

	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}

	return hex.EncodeToString(h.Sum(nil)), nil
}

// validateRelPath rejette un RelPath dangereux (absolu, volume Windows, ou
// remontée `..`) AVANT toute résolution de chemin — garde-fou contre un manifeste
// forgé qui ferait écrire l'agent hors de la racine du kind. OS-agnostique :
// normalise les DEUX séparateurs (`\` et `/`) car le manifeste peut venir d'un OS
// distant (un `..\..` doit être attrapé même sur un agent Linux).
func validateRelPath(rel string) error {
	rel = strings.TrimSpace(rel)
	if rel == "" {
		return fmt.Errorf("relpath vide")
	}
	if filepath.IsAbs(rel) || strings.ContainsRune(rel, ':') {
		return fmt.Errorf("relpath absolu ou avec volume interdit : %q", rel)
	}

	normalized := strings.ReplaceAll(rel, `\`, "/")
	if strings.HasPrefix(normalized, "/") {
		return fmt.Errorf("relpath absolu interdit : %q", rel)
	}
	for _, seg := range strings.Split(normalized, "/") {
		if seg == ".." {
			return fmt.Errorf("relpath contient une remontée (..) interdite : %q", rel)
		}
	}

	return nil
}

// ensureParentDir garantit le dossier parent de path (helper pour les resolvers).
func ensureParentDir(path string) error {
	return os.MkdirAll(filepath.Dir(path), 0o755)
}
