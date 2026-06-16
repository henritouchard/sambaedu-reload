package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
)

// Orchestration du provisioning Rainmeter côté SERVICE SYSTEM (Story 27.1bis,
// volets 1 & 3 ; Story 25.6 — embed→servi + checksum→manifest). Calquée sur
// SyncWallpaperAssets (assets.go) : manifest → download authentifié → vérif
// SHA-256 AVANT extraction/écriture → extraction portable dans un dossier ACL →
// pose de la skin (UTF-16 LE + BOM) + Rainmeter.ini durci → ACL Users:R sur
// tout l'arbre. Install-if-absent IDEMPOTENT : Rainmeter déjà posé et conforme →
// no-op. Un échec ne casse JAMAIS le cycle machine (rattrapage au prochain
// passage — comme le sync wallpaper). Appelé au BOOTSTRAP du cycle SYSTEM
// (RunCycle), JAMAIS depuis un handler runtime (contrainte « handler jamais
// installeur » — D3).
//
// Story 25.6 : l'autorité du hash du portable ET la skin viennent désormais du
// SERVEUR (manifest tool/skin + serving skin authentifié), plus d'une constante
// figée ni d'un embed go:embed. Outil désactivé/absent du manifest → no-op
// gracieux (D4) ; skin introuvable serveur → skin non (re)posée, le reste
// converge (Rainmeter.ini durci + ACL restent posés).
//
// NFR7 : aucune dépendance AD ici (download via le Client bearer, comme tout le
// canal SYSTEM).

// SyncRainmeterTool pose Rainmeter portable + sa config verrouillée selon le
// manifest tool/skin servi. Quatre étapes idempotentes :
//
//  1. MANIFEST : GET /api/v1/agent/tools-manifest (token'd). Serveur injoignable
//     ou réponse non exploitable → no-op gracieux (rattrapage au prochain
//     cycle ; Rainmeter absent reste gracieux, invariant 24.4/24.6).
//  2. PORTABLE (install-if-absent) : si l'outil est ACTIF dans le manifest
//     (`tool != nil`) ET Rainmeter pas encore installé → download de l'artefact
//     dédié (route /tools/{filename}), vérif SHA-256 = `tool.sha256` AVANT
//     extraction, extraction ACL. Outil absent/désactivé → provisioning sauté
//     (D4 : on ne désinstalle jamais l'existant).
//  3. SKIN : download de la skin (route /overlay-skin), vérif SHA-256 =
//     `skin.sha256` AVANT écriture, conversion UTF-16 LE + BOM, pose si
//     divergente (idempotence par contenu).
//  4. CONFIG durcie + ACL : Rainmeter.ini durci posé si divergent ; ACL Users:R
//     sur tout l'arbre Rainmeter (RainmeterACL), à chaque passage.
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

	// Étape 1 — manifest tool/skin (autorité serveur du hash + de l'activation).
	manifest, ok := a.fetchRainmeterManifest(cfg)
	if !ok {
		return // serveur injoignable/illisible → rattrapage au prochain cycle
	}

	// Étape 2 — portable (install-if-absent, vérif hash avant extraction).
	a.provisionRainmeterPortable(cfg, manifest.Tool)

	// Étapes 3 & 4 — skin téléchargée + config verrouillée + ACL.
	a.ensureRainmeterConfig(cfg, manifest.Skin)
}

// fetchRainmeterManifest récupère + parse le manifest tool/skin (token'd).
// Retourne (manifest, true) sur 200 exploitable ; (nil, false) sur réseau
// down, status inattendu ou corps illisible (no-op gracieux par l'appelant).
func (a *Agent) fetchRainmeterManifest(cfg Config) (*rainmeterManifest, bool) {
	token, err := a.Store.ReadToken()
	if err != nil {
		a.Log.Errorf("Provisioning Rainmeter impossible : lecture du token en échec : %v", err)

		return nil, false
	}
	a.Client.SetToken(token)

	url := cfg.ServerURL + RainmeterToolsManifestRoute
	resp, err := a.Client.Get(url, "")
	if err != nil {
		a.Log.Warningf("Serveur injoignable sur GET manifest tool/skin : %v — skip (rattrapage au prochain cycle).", err)

		return nil, false
	}

	switch resp.StatusCode {
	case 200:
		manifest, err := ParseRainmeterManifest(resp.Body)
		if err != nil {
			a.Log.Warningf("Manifest tool/skin illisible : %v — skip (rattrapage au prochain cycle).", err)

			return nil, false
		}

		return manifest, true
	case 401:
		a.Log.Errorf("401 irrécupérable sur le manifest tool/skin : provisioning interrompu — re-enrôlement MANUEL requis.")

		return nil, false
	case 403:
		a.enterQuarantine("GET /tools-manifest")

		return nil, false
	default:
		a.Log.Warningf("GET manifest tool/skin -> %d inattendu : skip (rattrapage au prochain cycle).", resp.StatusCode)

		return nil, false
	}
}

// provisionRainmeterPortable : download + vérif hash + extraction, seulement si
// l'outil est ACTIF dans le manifest (tool != nil) et Rainmeter pas encore
// installé. Outil absent/désactivé = provisioning désactivé (no-op gracieux,
// D4 — on ne désinstalle jamais l'existant).
func (a *Agent) provisionRainmeterPortable(cfg Config, tool *rainmeterToolEntry) {
	if a.Rainmeter.RainmeterInstalled() {
		return // déjà posé (marqueur d'extraction complète) — no-op idempotent
	}
	if tool == nil {
		a.Log.Debugf("Outil Rainmeter absent ou désactivé du manifest : provisioning sauté (Rainmeter absent reste gracieux, D4).")

		return
	}

	toolURL := cfg.ServerURL + RainmeterToolsRoute + tool.Filename
	resp, err := a.Client.Get(toolURL, "")
	if err != nil {
		a.Log.Warningf("Serveur injoignable sur GET tool %s : %v — skip (rattrapage au prochain cycle).", tool.Filename, err)

		return
	}

	switch resp.StatusCode {
	case 200:
		sum := sha256.Sum256(resp.Body)
		actual := hex.EncodeToString(sum[:])
		if actual != tool.SHA256 {
			// Vérif AVANT extraction : jamais un artefact corrompu posé. Retry
			// au prochain passage.
			a.Log.Warningf("Artefact Rainmeter : SHA-256 téléchargé (%s) != attendu (%s) — rejeté, retry au prochain cycle.", actual, tool.SHA256)

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
		// l'idempotence (RainmeterInstalled). On y inscrit le SHA-256 servi
		// (autorité serveur, D6) pour la traçabilité de la version posée.
		if err := WriteFileAtomic(a.Rainmeter.InstalledMarkerPath(), []byte(tool.SHA256)); err != nil {
			a.Log.Warningf("Pose du marqueur d'installation Rainmeter en échec : %v — retry au prochain cycle.", err)

			return
		}
		a.Log.Infof("Rainmeter portable posé et vérifié (SHA-256 ok) sous %s.", a.Rainmeter.AppDir())
	case 401:
		a.Log.Errorf("401 irrécupérable sur le download de l'outil Rainmeter : provisioning interrompu — re-enrôlement MANUEL requis.")
	case 403:
		a.enterQuarantine("GET /tools")
	case 404:
		a.Log.Warningf("Artefact Rainmeter %s inconnu du serveur (404) : non déposé sur la VM ? Provisioning sauté (Rainmeter absent reste gracieux).", tool.Filename)
	default:
		a.Log.Warningf("GET tool %s -> %d inattendu : skip (rattrapage au prochain cycle).", tool.Filename, resp.StatusCode)
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

	// MODE INSTALLÉ (Story 27.1ter) : on retire le Rainmeter.ini embarqué par le
	// portable DANS LE TEMPORAIRE, AVANT la bascule — ainsi l'arbre définitif ne
	// reçoit JAMAIS de Rainmeter.ini racine, même transitoirement. Sinon il
	// existerait une fenêtre entre la bascule et la suppression d'ensureRainmeterConfig
	// où un Rainmeter.exe lancé verrait son Rainmeter.ini voisin → MODE PORTABLE →
	// modales « not writable »/« Safe Start » (review 27.1ter F3). Seul le .ini AUX
	// CÔTÉS de l'exe (racine) force le portable — c'est le seul à éliminer.
	// ensureRainmeterConfig garde sa suppression idempotente pour le cas « upgrade
	// d'une install 27.1bis antérieure » (.ini déjà présent dans la racine, hors
	// extraction).
	if err := os.Remove(filepath.Join(tmp, rainmeterSettingsFile)); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("retrait du Rainmeter.ini portable (temporaire) : %w", err)
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

// ensureRainmeterConfig : télécharge la skin (vérif SHA-256 AVANT écriture,
// conversion UTF-16 LE + BOM à la pose — Story 25.6, plus d'embed) + GARANTIT
// l'absence de tout Rainmeter.ini sous ProgramData (Story 27.1ter — mode
// installé : les settings partent en %APPDATA%, posés par le compagnon).
// Toujours appelée. Skin absente/illisible serveur (skin == nil) ou download en
// échec → skin non (re)posée, MAIS la suppression du .ini résiduel + l'ACL
// restent appliquées (le verrouillage ne dépend pas de la skin). ACL Users:R sur
// la racine.
func (a *Agent) ensureRainmeterConfig(cfg Config, skinEntry *rainmeterSkinEntry) {
	// Skin (UTF-16 LE + BOM) — téléchargée et vérifiée (D1) au lieu d'embarquée.
	if skinEntry == nil {
		a.Log.Debugf("Skin d'overlay absente du manifest serveur : pose de skin sautée (Rainmeter.ini durci + ACL restent posés).")
	} else if skinUTF8, ok := a.fetchOverlaySkin(cfg, skinEntry); ok {
		skin, err := ToUTF16LEWithBOM(string(skinUTF8))
		if err != nil {
			a.Log.Errorf("Conversion UTF-16 de la skin Rainmeter en échec : %v", err)
		} else if !fileMatchesContent(a.Rainmeter.SkinPath(), skin) {
			if err := os.MkdirAll(a.Rainmeter.SkinDir(), 0o700); err != nil {
				a.Log.Warningf("Création du dossier de skin Rainmeter en échec : %v", err)
			} else if err := WriteFileAtomic(a.Rainmeter.SkinPath(), skin); err != nil {
				a.Log.Warningf("Pose de la skin Rainmeter en échec : %v", err)
			} else {
				a.Log.Infof("Skin Rainmeter posée (UTF-16 LE + BOM) : %s.", a.Rainmeter.SkinPath())
			}
		}
	}

	// MODE INSTALLÉ (Story 27.1ter) : on N'ÉCRIT PLUS le Rainmeter.ini durci sous
	// ProgramData. Les settings partent en %APPDATA%\Rainmeter\ (writable, posés
	// par le COMPAGNON en droits user) — la présence d'un Rainmeter.ini AUX CÔTÉS
	// de Rainmeter.exe forcerait le MODE PORTABLE et ramènerait les modales « not
	// writable » / « Safe Start » sur un user standard (ProgramData en RX).
	// On SUPPRIME donc, de façon IDEMPOTENTE, tout Rainmeter.ini résiduel du
	// dossier racine ProgramData : celui embarqué par le zip portable ET un ancien
	// durci posé par une install 27.1bis antérieure. Son absence garantit le mode
	// installé → Rainmeter lit %APPDATA%\Rainmeter\Rainmeter.ini (writable) et
	// charge la skin verrouillée via SkinPath.
	residualIni := a.Rainmeter.SettingsPath()
	if err := os.Remove(residualIni); err != nil && !os.IsNotExist(err) {
		a.Log.Warningf("Suppression du Rainmeter.ini résiduel sous ProgramData en échec : %v (le mode portable pourrait persister).", err)
	} else if err == nil {
		a.Log.Infof("Rainmeter.ini résiduel supprimé de l'arbre ProgramData (mode installé, settings en %%APPDATA%%) : %s.", residualIni)
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

// fetchOverlaySkin télécharge la skin d'overlay (UTF-8) par la route agent
// authentifiée et VÉRIFIE le SHA-256 = skin.sha256 AVANT toute écriture
// (pattern SyncWallpaperAssets : un contenu divergent n'entre JAMAIS dans le
// dossier cible). Retourne (corpsUTF8, true) si vérifié ; (nil, false) sur
// réseau down, status inattendu ou hash divergent (no-op gracieux).
func (a *Agent) fetchOverlaySkin(cfg Config, skinEntry *rainmeterSkinEntry) ([]byte, bool) {
	skinURL := cfg.ServerURL + RainmeterOverlaySkinRoute
	resp, err := a.Client.Get(skinURL, "")
	if err != nil {
		a.Log.Warningf("Serveur injoignable sur GET skin d'overlay : %v — skip (rattrapage au prochain cycle).", err)

		return nil, false
	}

	switch resp.StatusCode {
	case 200:
		sum := sha256.Sum256(resp.Body)
		actual := hex.EncodeToString(sum[:])
		if actual != skinEntry.SHA256 {
			a.Log.Warningf("Skin d'overlay : SHA-256 téléchargé (%s) != attendu (%s) — rejetée, retry au prochain cycle.", actual, skinEntry.SHA256)

			return nil, false
		}

		return resp.Body, true
	case 401:
		a.Log.Errorf("401 irrécupérable sur le download de la skin d'overlay : provisioning interrompu — re-enrôlement MANUEL requis.")

		return nil, false
	case 403:
		a.enterQuarantine("GET /overlay-skin")

		return nil, false
	case 404:
		a.Log.Warningf("Skin d'overlay inconnue du serveur (404) : non provisionnée ? Pose de skin sautée (Rainmeter.ini durci + ACL restent posés).")

		return nil, false
	default:
		a.Log.Warningf("GET skin d'overlay -> %d inattendu : skip (rattrapage au prochain cycle).", resp.StatusCode)

		return nil, false
	}
}
