package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
)

// Orchestration du provisioning Rainmeter côté SERVICE SYSTEM (Story 27.1bis,
// volets 1 & 3). Calquée sur SyncWallpaperAssets (assets.go) : download
// authentifié → vérif SHA-256 AVANT extraction → extraction portable dans un
// dossier ACL → pose de la skin (UTF-16 LE + BOM) + Rainmeter.ini durci →
// ACL Users:R sur tout l'arbre. Install-if-absent IDEMPOTENT : Rainmeter déjà
// posé et conforme → no-op. Un échec ne casse JAMAIS le cycle machine
// (rattrapage au prochain passage — comme le sync wallpaper). Appelé au
// BOOTSTRAP du cycle SYSTEM (RunCycle), JAMAIS depuis un handler runtime
// (contrainte « handler jamais installeur » — D3).
//
// NFR7 : aucune dépendance AD ici (download via le Client bearer, comme tout
// le canal SYSTEM).

// SyncRainmeterTool pose Rainmeter portable + sa config verrouillée si absent
// ou non conforme. Trois étapes idempotentes :
//
//  1. PROVISIONING du portable : si Rainmeter.exe absent du dossier app ET un
//     checksum attendu est figé (RainmeterToolChecksum non vide) → download de
//     l'artefact dédié (route /tools), vérif SHA-256 AVANT extraction, extraction
//     ACL. Checksum vide = provisioning DÉSACTIVÉ (no-op gracieux — l'artefact
//     réel n'est pas encore figé ; overlay reste écrit, Rainmeter absent reste
//     gracieux, invariant 24.4/24.6).
//  2. CONFIG verrouillée : skin (UTF-16 LE + BOM) + Rainmeter.ini durci posés
//     si divergents (idempotence par contenu), TOUJOURS — même sans le portable
//     (la config peut précéder l'artefact ; à l'arrivée du portable, tout est
//     prêt).
//  3. ACL Users:R, SYSTEM/Admins full sur tout l'arbre Rainmeter (RainmeterACL).
func (a *Agent) SyncRainmeterTool(cfg Config) {
	if a.quarantined {
		a.Log.Debugf("Quarantaine active : provisioning Rainmeter sauté.")

		return
	}
	if a.Rainmeter == nil {
		// Plateforme/instance sans store Rainmeter injecté (tests, !windows) :
		// no-op silencieux.
		return
	}

	// Étape 1 — portable (install-if-absent, vérif hash avant extraction).
	a.provisionRainmeterPortable(cfg)

	// Étape 2 — config verrouillée (skin UTF-16 LE + BOM + Rainmeter.ini durci).
	a.ensureRainmeterConfig()
}

// provisionRainmeterPortable : download + vérif hash + extraction, seulement si
// Rainmeter.exe est absent. Checksum attendu vide = provisioning désactivé.
func (a *Agent) provisionRainmeterPortable(cfg Config) {
	if a.Rainmeter.RainmeterInstalled() {
		return // déjà posé (sentinelle Rainmeter.exe) — no-op idempotent
	}
	if RainmeterToolChecksum == "" {
		a.Log.Debugf("Provisioning Rainmeter désactivé (aucun checksum d'artefact figé) : Rainmeter absent reste gracieux.")

		return
	}

	token, err := a.Store.ReadToken()
	if err != nil {
		a.Log.Errorf("Provisioning Rainmeter impossible : %v", err)

		return
	}
	a.Client.SetToken(token)

	toolURL := cfg.ServerURL + "/api/v1/agent/tools/" + RainmeterToolFilename
	resp, err := a.Client.Get(toolURL, "")
	if err != nil {
		a.Log.Warningf("Serveur injoignable sur GET tool %s : %v — skip (rattrapage au prochain cycle).", RainmeterToolFilename, err)

		return
	}

	switch resp.StatusCode {
	case 200:
		sum := sha256.Sum256(resp.Body)
		actual := hex.EncodeToString(sum[:])
		if actual != RainmeterToolChecksum {
			// Vérif AVANT extraction : jamais un artefact corrompu posé. Retry
			// au prochain passage.
			a.Log.Warningf("Artefact Rainmeter : SHA-256 téléchargé (%s) != attendu — rejeté, retry au prochain cycle.", actual)

			return
		}
		// Extraction vers un dossier TEMPORAIRE puis rename atomique du dossier
		// en place (#10) : une extraction interrompue ne pollue jamais la racine
		// définitive — soit l'arbre complet apparaît d'un coup, soit rien.
		if err := a.extractRainmeterAtomic(resp.Body); err != nil {
			a.Log.Warningf("Extraction du portable Rainmeter en échec : %v — retry au prochain cycle.", err)

			return
		}
		// ACL Users:R sur tout l'arbre Rainmeter APRÈS extraction (les fichiers
		// déjà posés héritent à la pose suivante ; ici on ACL la racine pour
		// couvrir l'arbre extrait — (OI)(CI) propage). nil = no-op (tests hôte).
		if a.RainmeterACL != nil {
			if err := a.RainmeterACL(a.Rainmeter.AppDir()); err != nil {
				a.Log.Warningf("Pose de l'ACL du portable Rainmeter en échec : %v", err)
			}
		}
		// Marqueur d'extraction complète écrit en DERNIER, APRÈS extraction +
		// ACL (#10) : c'est lui — pas Rainmeter.exe — qui fait foi de
		// l'idempotence (RainmeterInstalled). Sa présence garantit qu'on n'a pas
		// affaire à une extraction partielle.
		if err := WriteFileAtomic(a.Rainmeter.InstalledMarkerPath(), []byte(RainmeterToolChecksum)); err != nil {
			a.Log.Warningf("Pose du marqueur d'installation Rainmeter en échec : %v — retry au prochain cycle.", err)

			return
		}
		a.Log.Infof("Rainmeter portable posé et vérifié (SHA-256 ok) sous %s.", a.Rainmeter.AppDir())
	case 401:
		a.Log.Errorf("401 irrécupérable sur le download de l'outil Rainmeter : provisioning interrompu — re-enrôlement MANUEL requis.")
	case 403:
		a.enterQuarantine("GET /tools")
	case 404:
		a.Log.Warningf("Artefact Rainmeter %s inconnu du serveur (404) : non déposé sur la VM ? Provisioning sauté (Rainmeter absent reste gracieux).", RainmeterToolFilename)
	default:
		a.Log.Warningf("GET tool %s -> %d inattendu : skip (rattrapage au prochain cycle).", RainmeterToolFilename, resp.StatusCode)
	}
}

// extractRainmeterAtomic extrait le portable vers un dossier TEMPORAIRE sibling
// puis bascule son contenu dans la racine définitive (#10). Une extraction
// interrompue reste confinée au temporaire (purgé) — la racine n'accueille
// jamais un arbre partiel. Le merge top-level préserve la config éventuellement
// déjà posée (Skins/SambaEduOverlay, Rainmeter.ini durci) : on ne déplace que
// les entrées du portable, le marqueur écrit ensuite scelle l'install.
func (a *Agent) extractRainmeterAtomic(archive []byte) error {
	root := a.Rainmeter.AppDir()
	if err := os.MkdirAll(root, 0o700); err != nil {
		return fmt.Errorf("création de %s : %w", root, err)
	}

	tmp, err := os.MkdirTemp(root, ".rainmeter-extract-*")
	if err != nil {
		return fmt.Errorf("dossier temporaire d'extraction : %w", err)
	}
	// Purge du temporaire quoi qu'il arrive (extraction interrompue = rien ne
	// fuit dans la racine).
	defer os.RemoveAll(tmp)

	if err := ExtractPortableZip(archive, tmp); err != nil {
		return err
	}

	// Bascule des entrées top-level du portable dans la racine. Rename atomique
	// par entrée (même volume — tmp est sous root) ; on remplace une entrée
	// portable pré-existante, jamais la config (les noms diffèrent).
	entries, err := os.ReadDir(tmp)
	if err != nil {
		return fmt.Errorf("lecture du temporaire d'extraction : %w", err)
	}
	for _, e := range entries {
		src := filepath.Join(tmp, e.Name())
		dst := filepath.Join(root, e.Name())
		if err := os.RemoveAll(dst); err != nil {
			return fmt.Errorf("nettoyage de %s : %w", dst, err)
		}
		if err := os.Rename(src, dst); err != nil {
			return fmt.Errorf("bascule de %s : %w", dst, err)
		}
	}

	return nil
}

// ensureRainmeterConfig : pose la skin (UTF-16 LE + BOM) + le Rainmeter.ini
// durci, idempotent par contenu (ne réécrit que si divergence). Toujours
// appelée (la config peut précéder le portable). ACL Users:R sur la racine.
func (a *Agent) ensureRainmeterConfig() {
	// Skin (UTF-16 LE + BOM) — conversion à la pose depuis la source UTF-8
	// embarquée (D6).
	skin, err := ToUTF16LEWithBOM(RainmeterSkinSource())
	if err != nil {
		a.Log.Errorf("Conversion UTF-16 de la skin Rainmeter en échec : %v", err)

		return
	}
	// Rainmeter.ini durci, posé AUSSI en UTF-16 LE + BOM (homogénéité Rainmeter).
	ini, err := ToUTF16LEWithBOM(BuildHardenedRainmeterIni())
	if err != nil {
		a.Log.Errorf("Conversion UTF-16 du Rainmeter.ini en échec : %v", err)

		return
	}

	if !fileMatchesContent(a.Rainmeter.SkinPath(), skin) {
		if err := os.MkdirAll(a.Rainmeter.SkinDir(), 0o700); err != nil {
			a.Log.Warningf("Création du dossier de skin Rainmeter en échec : %v", err)

			return
		}
		if err := WriteFileAtomic(a.Rainmeter.SkinPath(), skin); err != nil {
			a.Log.Warningf("Pose de la skin Rainmeter en échec : %v", err)

			return
		}
		a.Log.Infof("Skin Rainmeter posée (UTF-16 LE + BOM) : %s.", a.Rainmeter.SkinPath())
	}

	if !fileMatchesContent(a.Rainmeter.SettingsPath(), ini) {
		if err := os.MkdirAll(a.Rainmeter.SettingsDir(), 0o700); err != nil {
			a.Log.Warningf("Création du dossier de config Rainmeter en échec : %v", err)

			return
		}
		if err := WriteFileAtomic(a.Rainmeter.SettingsPath(), ini); err != nil {
			a.Log.Warningf("Pose du Rainmeter.ini durci en échec : %v", err)

			return
		}
		a.Log.Infof("Rainmeter.ini durci posé (TrayIcon=0, Draggable=0, ClickThrough=1, KeepOnScreen=1) : %s.", a.Rainmeter.SettingsPath())
	}

	// ACL Users:R sur la racine Rainmeter (couvre skin + Rainmeter.ini —
	// (OI)(CI) propage). Posée à CHAQUE passage, pas seulement si le contenu a
	// changé (#3) : icacls est idempotent, et un drift d'ACL introduit hors de
	// notre écriture (élève admin local, restauration, GPO) est ainsi recorrigé
	// même quand skin et Rainmeter.ini sont déjà conformes. nil = no-op (tests
	// hôte).
	if a.RainmeterACL != nil {
		if err := a.RainmeterACL(a.Rainmeter.RootDir()); err != nil {
			a.Log.Warningf("Pose de l'ACL de la config Rainmeter en échec : %v", err)
		}
	}
}
