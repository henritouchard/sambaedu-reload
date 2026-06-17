package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"sambaedu/agent/shared"
)

// Câblage Windows du handler `app_config` (Story 27.4) — pose un `policies.json`
// au chemin natif d'install de l'app (Firefox/Thunderbird) en ÉCRITURE ATOMIQUE
// (fichier temporaire + rename), EN GO NATIF (pas de shell-out). UN SEUL
// mécanisme : le `policies.json` enterprise natif — pas de registre, pas de
// Chrome/Edge, pas de redirection de profil (recadrage 2026-06-17).
//
// Exécuté par le SERVICE SYSTEM (scope MACHINE, correctif post-review
// 2026-06-17 review #1) : `policies.json` est machine-wide (sous Program Files,
// admin-write) → SYSTEM peut l'écrire. Le compagnon aux droits user prenait
// ACCESS_DENIED à chaque logon (défaut de conception corrigé). La résolution
// serveur est PAR PARC (niveaux 1-4 : template + auto + défaut étab + WG) ; le
// par-user de Firefox = le PROFIL (mécanisme B / roaming, hors 27.4), PAS
// `policies.json`. Si le dossier d'install est absent (app non installée) →
// Write échoue → {status: error, detail} pour le SEUL type `app_config`, les
// autres types convergent (isolation AC4). L'installation des apps (27.5) reste
// hors 27.4 (couplage = limite connue).
//
// MARQUEUR de périmètre (shared.AppConfigManagedMarker) : l'agent ajoute une clé
// d'extension `_sambaedu_managed: true` au document écrit. Firefox/Thunderbird
// ignorent les clés racines inconnues (le marqueur est inerte côté app). Inspect
// le relit pour distinguer un `policies.json` GÉRÉ d'un fichier posé hors
// SambaEdu (autre outil/admin) — jamais écrasé ni supprimé.

// appConfigOps : impl AppConfigOps de production (Windows, fichier natif).
type appConfigOps struct {
	log *shared.Logger
}

// PolicyPath résout le chemin natif du `policies.json` de l'app.
//
//	Firefox     : %ProgramFiles%\Mozilla Firefox\distribution\policies.json
//	Thunderbird : %ProgramFiles%\Mozilla Thunderbird\distribution\policies.json
//
// (Mozilla lit `distribution\policies.json` relatif au dossier de l'exécutable —
// chemin d'enterprise policy documenté.) `%ProgramFiles%` est résolu via
// l'environnement ; à défaut, `C:\Program Files`. app_kind inconnu = erreur
// (l'item devient error, les autres apps continuent).
func (o *appConfigOps) PolicyPath(appKind string) (string, error) {
	subdir, ok := appInstallSubdir(appKind)
	if !ok {
		return "", fmt.Errorf("app_config : app_kind non gérée %q (mécanisme policies.json absent)", appKind)
	}

	programFiles := os.Getenv("ProgramFiles")
	if programFiles == "" {
		programFiles = `C:\Program Files`
	}

	return filepath.Join(programFiles, subdir, "distribution", "policies.json"), nil
}

// appInstallSubdir : dossier d'install (sous Program Files) par app_kind. Ajouter
// une app `policies.json` future = une entrée ici (data serveur émettra l'item).
func appInstallSubdir(appKind string) (string, bool) {
	switch strings.ToLower(appKind) {
	case "firefox":
		return "Mozilla Firefox", true
	case "thunderbird":
		return "Mozilla Thunderbird", true
	default:
		return "", false
	}
}

// Inspect : le `policies.json` à `path` existe-t-il, et porte-t-il le marqueur
// de gestion SambaEdu ? Fichier absent → (false, false, nil). Présent + marqueur
// → (true, true, nil). Présent SANS marqueur (hors périmètre) → (true, false,
// nil). Fichier présent mais JSON illisible → (true, false, err) : on remonte
// l'erreur (le moteur rend error pour le type) plutôt que de risquer d'écraser
// un fichier qu'on ne comprend pas.
func (o *appConfigOps) Inspect(path string) (bool, bool, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return false, false, nil
		}

		return false, false, fmt.Errorf("lecture de %s : %w", path, err)
	}

	managed, err := documentIsManaged(raw)
	if err != nil {
		// Présent mais illisible (JSON corrompu) : on remonte l'erreur. Ne JAMAIS
		// l'écraser silencieusement (prudence — un fichier d'un autre outil
		// pourrait être mal formé temporairement).
		return true, false, fmt.Errorf("policies.json illisible (%s) : %w", path, err)
	}

	return true, managed, nil
}

// Matches : le `policies.json` GÉRÉ à `path` a-t-il EXACTEMENT le contenu cible
// (marqueur exclu de la comparaison) ? Appelée uniquement sur un chemin déjà
// inspecté géré. Absent/divergent → (false, nil) ; conforme → (true, nil) ;
// illisible → (false, err).
func (o *appConfigOps) Matches(path string, spec shared.AppConfigSpec) (bool, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return false, nil
		}

		return false, fmt.Errorf("lecture de %s : %w", path, err)
	}

	// Contenu réel SANS le marqueur, re-canonicalisé → comparable octet-à-octet
	// à la cible canonique (qui n'inclut pas le marqueur). Découple le marqueur
	// (détail de gestion) du contenu de policy (ce que l'admin a réglé).
	actualCanonical, _, err := canonicalWithoutMarker(raw)
	if err != nil {
		return false, fmt.Errorf("policies.json illisible (%s) : %w", path, err)
	}

	return string(actualCanonical) == string(spec.Canonical), nil
}

// Write : écrit (ou réécrit) le `policies.json` à `path` ATOMIQUEMENT, marqueur
// de gestion injecté. Crée le dossier `distribution\` si absent (install
// présente mais policy jamais posée). err = chemin verrouillé / dossier parent
// non créable (app absente) → {status: error} pour le type, les autres apps
// convergent.
func (o *appConfigOps) Write(path string, spec shared.AppConfigSpec) error {
	doc, err := documentWithMarker(spec.Policies)
	if err != nil {
		return fmt.Errorf("sérialisation policies.json (%s) : %w", spec.AppKind, err)
	}

	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return fmt.Errorf("création de %s : %w", filepath.Dir(path), err)
	}

	tmp := fmt.Sprintf("%s.%d.tmp", path, os.Getpid())

	// Nettoyage du `.tmp` résiduel sur TOUT chemin d'échec (review #8 : disque
	// plein en cours de WriteFile, rename refusé). `renamed` annule le defer
	// après un Rename réussi (le tmp n'existe plus, il EST devenu `path`).
	renamed := false
	defer func() {
		if !renamed {
			_ = os.Remove(tmp)
		}
	}()

	if err := os.WriteFile(tmp, doc, 0o644); err != nil {
		return fmt.Errorf("écriture de %s : %w", tmp, err)
	}
	if err := os.Rename(tmp, path); err != nil {
		return fmt.Errorf("rename %s → %s : %w", tmp, path, err)
	}
	renamed = true

	return nil
}

// Remove : supprime un `policies.json` GÉRÉ (app sortie des règles). Absent =
// pas d'erreur (idempotent). Appelée uniquement sur un chemin inspecté géré.
func (o *appConfigOps) Remove(path string) error {
	err := os.Remove(path)
	if os.IsNotExist(err) {
		return nil
	}

	return err
}

// --- Sérialisation du document policies.json (marqueur de gestion) ----------

// documentWithMarker construit les octets du `policies.json` à écrire : les
// policies cibles + la clé d'extension `_sambaedu_managed: true`, clés triées
// récursivement (déterminisme/idempotence), indentation 2 espaces.
func documentWithMarker(policies map[string]any) ([]byte, error) {
	doc := map[string]any{}
	for k, v := range policies {
		doc[k] = v
	}
	doc[shared.AppConfigManagedMarker] = true

	return shared.CanonicalJSON(doc)
}

// decodeJSONObject : décode un objet JSON en `map[string]any` avec UseNumber
// (les nombres restent des json.Number, JAMAIS des float64) — MÊME convention
// que le parsing de l'état (shared.ParseState/DecodeJSON), pour que le contenu
// réel re-sérialisé soit comparable octet-à-octet à la cible canonique.
func decodeJSONObject(raw []byte) (map[string]any, error) {
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	var doc map[string]any
	if err := dec.Decode(&doc); err != nil {
		return nil, err
	}

	return doc, nil
}

// documentIsManaged : le document porte-t-il le marqueur `_sambaedu_managed`
// (true) à la racine ?
func documentIsManaged(raw []byte) (bool, error) {
	doc, err := decodeJSONObject(raw)
	if err != nil {
		return false, err
	}
	marker, ok := doc[shared.AppConfigManagedMarker].(bool)

	return ok && marker, nil
}

// canonicalWithoutMarker : re-canonicalise le document RÉEL après retrait du
// marqueur de gestion — comparable à la cible canonique (qui n'inclut pas le
// marqueur). Retourne aussi `managed` (le marqueur était-il présent) pour usage
// éventuel.
func canonicalWithoutMarker(raw []byte) ([]byte, bool, error) {
	doc, err := decodeJSONObject(raw)
	if err != nil {
		return nil, false, err
	}
	marker, managed := doc[shared.AppConfigManagedMarker].(bool)
	delete(doc, shared.AppConfigManagedMarker)

	// MÊME fonction de canonicalisation que la forme CIBLE (shared.CanonicalJSON)
	// → le `spec.Canonical` (cible) et le contenu réel relu (sans marqueur) sont
	// comparables octet-à-octet, y compris quand les nombres sont des
	// json.Number (UseNumber des deux côtés). Review #3 : plus de second
	// canonicalizer divergent.
	canonical, err := shared.CanonicalJSON(doc)
	if err != nil {
		return nil, false, err
	}

	return canonical, marker && managed, nil
}
