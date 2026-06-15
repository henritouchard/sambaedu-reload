package shared

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"golang.org/x/text/encoding/unicode"
	"golang.org/x/text/transform"
)

// Provisioning + verrouillage de l'outil de rendu Rainmeter (Story 27.1bis —
// volets 1 & 3). TOUTE la logique pure (décision provisioning install-if-absent,
// conversion UTF-16 LE + BOM, génération du Rainmeter.ini durci, vérification
// d'idempotence par hash) vit ICI, OS-agnostique et testée sur l'hôte ; le
// spécifique Windows (download authentifié, ACL icacls, lancement de process)
// reste injecté ou dans agent/windows.
//
// Contrainte forte projet « handler jamais installeur » (mémoire
// project_rainmeter_provisioning_direction) : le provisioning est appelé au
// BOOTSTRAP du cycle SYSTEM, JAMAIS depuis un handler runtime. Rainmeter est
// extrait en mode PORTABLE (zéro registre, zéro MSI/NSIS/winget — D3).
//
// NFR7 (critère Keycloak) : aucune dépendance AD/LDAP/APCu ici — pose d'un
// asset vérifié + maintien, rien d'autre (anti-couteau-suisse NFR9/NFR12).

const (
	// RainmeterToolFilename : nom figé de l'artefact portable servi par la
	// route dédiée /api/v1/agent/tools/{filename} (D8). Le serveur valide ce
	// même pattern `sambaedu-rainmeter-…\.zip`. Mono-version (Q3) : un seul
	// artefact pour tout le parc — Rainmeter ne bouge quasi jamais.
	RainmeterToolFilename = "sambaedu-rainmeter-4.5.18-portable.zip"

	// RainmeterToolChecksum : SHA-256 ATTENDU de l'artefact portable, vérifié
	// AVANT extraction (pattern SyncWallpaperAssets : un contenu divergent
	// n'entre JAMAIS dans le dossier cible). Bakée dans le binaire (comme le
	// hash figé du contrat) — le déposant de l'artefact sur la VM met à jour
	// cette constante en regard du `.zip` réellement servi. La chaîne vide
	// DÉSACTIVE le provisioning (no-op gracieux) : tant que l'artefact réel
	// n'est pas figé, l'agent ne télécharge/extrait rien — overlay.json reste
	// écrit, Rainmeter absent reste gracieux (invariant 24.4/24.6).
	RainmeterToolChecksum = ""

	// rainmeterExeName : sentinelle de présence/extraction. Le portable réel
	// (option « Portable » de l'installeur Rainmeter) pose Rainmeter.exe À LA
	// RACINE du dossier portable, AUX CÔTÉS de Rainmeter.ini, Skins/, Plugins/,
	// Languages/, Runtime/ — pas dans un sous-dossier app/.
	rainmeterExeName = "Rainmeter.exe"

	// rainmeterSkinName : nom du dossier ET du fichier de la skin (Rainmeter
	// exige skin\<Config>\<Skin>.ini ; ici Config = Skin = SambaEduOverlay).
	rainmeterSkinName = "SambaEduOverlay"

	rainmeterSettingsFile = "Rainmeter.ini"
	rainmeterSkinFile     = "SambaEduOverlay.ini"
	rainmeterSkinsDirName = "Skins"

	// rainmeterInstalledMarker : marqueur d'extraction COMPLÈTE (#10). Écrit en
	// DERNIER, après extraction intégrale + ACL, sous la racine portable. Sa
	// présence — PAS celle de Rainmeter.exe — fait foi de l'idempotence du
	// provisioning : une extraction interrompue laisse Rainmeter.exe posé mais
	// PAS ce marqueur → le cycle suivant ré-extrait au lieu de compter
	// « installé » à tort.
	rainmeterInstalledMarker = "rainmeter.installed"

	// rainmeterUnzipMaxBytes : garde-fou anti zip-bomb à la décompression d'un
	// fichier de l'archive (un portable Rainmeter pèse ~quelques Mio par
	// fichier). Borne LARGE mais finie — jamais une décompression non bornée.
	rainmeterUnzipMaxBytes = 64 << 20 // 64 Mio par entrée

	// rainmeterUnzipMaxTotalBytes : borne TOTALE cumulée de la décompression de
	// l'archive entière (#9) — défense zip-bomb complémentaire de la borne par
	// entrée (mille petites entrées ne saturent pas non plus le disque). Un
	// portable Rainmeter complet pèse ~quelques dizaines de Mio.
	rainmeterUnzipMaxTotalBytes = 500 << 20 // 500 Mio cumulés
)

// RainmeterStore : chemins de l'outil de rendu, FRÈRES de la racine Agent
// (C:\ProgramData\SambaEdu\Rainmeter\, pas sous ...\Agent\ — la config sous
// ProgramData ACL est le point de verrouillage, D4). Racine paramétrable
// (testabilité hôte) ; vaut C:\ProgramData\SambaEdu\Rainmeter en production.
//
// Structure du portable RÉEL (option « Portable » de l'installeur Rainmeter) :
// Rainmeter.exe, Rainmeter.ini et Skins/ sont TOUS au même niveau, à la racine
// du dossier portable. En mode portable Rainmeter lit son Rainmeter.ini dans le
// dossier de Rainmeter.exe et cherche Skins/ au même niveau — d'où l'alignement
// ci-dessous (corrige #7/M1 : plus de sous-dossier app/).
//
//	<RainmeterRoot>\
//	├── Rainmeter.exe ...            ← portable extrait à la racine
//	├── Rainmeter.ini                ← settings DURCIS (TrayIcon=0 + section skin)
//	├── rainmeter.installed          ← marqueur d'extraction complète (#10)
//	└── Skins\SambaEduOverlay\SambaEduOverlay.ini  ← skin UTF-16 LE + BOM
//
// Tout l'arbre est posé en ACL Users:R, SYSTEM/Admins full (setAssetsACL) —
// l'élève non-admin ne reconfigure rien durablement.
type RainmeterStore struct {
	// Root : C:\ProgramData\SambaEdu\Rainmeter en production. Vide = défaut.
	Root string
}

// DefaultRainmeterRoot : racine de l'outil de rendu en production Windows.
const DefaultRainmeterRoot = `C:\ProgramData\SambaEdu\Rainmeter`

func (r *RainmeterStore) root() string {
	if r.Root == "" {
		return DefaultRainmeterRoot
	}

	return r.Root
}

// RootDir expose la racine résolue (C:\ProgramData\SambaEdu\Rainmeter en prod)
// — cible de l'ACL Users:R de tout l'arbre.
func (r *RainmeterStore) RootDir() string { return r.root() }

// SettingsDir : dossier du Rainmeter.ini (la racine — en mode portable
// Rainmeter lit Rainmeter.ini dans le dossier de Rainmeter.exe, ici la racine
// de l'arbre ACLé).
func (r *RainmeterStore) SettingsDir() string { return r.root() }

// AppDir : dossier d'extraction du portable. En portable RÉEL, Rainmeter.exe
// est à la RACINE (pas de sous-dossier app/) — AppDir == root.
func (r *RainmeterStore) AppDir() string { return r.root() }

// ExePath : Rainmeter.exe extrait à la racine du portable — cible du
// lancement par le watchdog.
func (r *RainmeterStore) ExePath() string { return filepath.Join(r.root(), rainmeterExeName) }

// InstalledMarkerPath : marqueur d'extraction complète (#10), écrit en dernier.
func (r *RainmeterStore) InstalledMarkerPath() string {
	return filepath.Join(r.root(), rainmeterInstalledMarker)
}

// SettingsPath : Rainmeter.ini durci (settings d'instance — TrayIcon=0,
// Draggable=0/ClickThrough=1/KeepOnScreen=1 sur la section skin).
func (r *RainmeterStore) SettingsPath() string {
	return filepath.Join(r.root(), rainmeterSettingsFile)
}

// SkinsDir : racine des skins (Rainmeter exige Skins\<Config>\).
func (r *RainmeterStore) SkinsDir() string { return filepath.Join(r.root(), rainmeterSkinsDirName) }

// SkinDir : dossier de la skin SambaEduOverlay.
func (r *RainmeterStore) SkinDir() string {
	return filepath.Join(r.SkinsDir(), rainmeterSkinName)
}

// SkinPath : SambaEduOverlay.ini posée (UTF-16 LE + BOM).
func (r *RainmeterStore) SkinPath() string {
	return filepath.Join(r.SkinDir(), rainmeterSkinFile)
}

// RainmeterInstalled : le marqueur d'extraction COMPLÈTE est-il présent ?
// (#10) Sentinelle d'idempotence du provisioning (install-if-absent). On se
// base sur le marqueur — écrit en DERNIER après extraction intégrale + ACL —
// et NON sur la présence de Rainmeter.exe : une extraction interrompue laisse
// l'exe posé mais pas le marqueur, donc le cycle suivant ré-extrait au lieu de
// compter « installé » à tort.
func (r *RainmeterStore) RainmeterInstalled() bool {
	_, err := os.Stat(r.InstalledMarkerPath())

	return err == nil
}

// --- Conversion UTF-16 LE + BOM (D6) -----------------------------------------

// ToUTF16LEWithBOM convertit une source UTF-8 en UTF-16 LE avec BOM (FF FE) —
// l'encodage attendu par Rainmeter pour les caractères non-ASCII (sinon
// mojibake `Â·`, mémoire project_overlay_rainmeter_lockdown_direction). Pure,
// déterministe (round-trip stable) : c'est le pivot testé sur l'hôte.
func ToUTF16LEWithBOM(utf8Source string) ([]byte, error) {
	enc := unicode.UTF16(unicode.LittleEndian, unicode.UseBOM).NewEncoder()
	out, _, err := transform.Bytes(enc, []byte(utf8Source))
	if err != nil {
		return nil, fmt.Errorf("conversion UTF-16 LE : %w", err)
	}

	return out, nil
}

// hashBytes : SHA-256 hex d'un contenu — sert l'idempotence (ne réécrire un
// fichier que si sa cible diverge, pattern test/apply).
func hashBytes(b []byte) string {
	sum := sha256.Sum256(b)

	return hex.EncodeToString(sum[:])
}

// fileMatchesContent : le fichier à path porte-t-il EXACTEMENT le contenu
// attendu ? (octet pour octet — l'UTF-16 LE + BOM est sensible à l'octet).
// Absent ou divergent = false → l'appelant (ré)écrit.
func fileMatchesContent(path string, want []byte) bool {
	got, err := os.ReadFile(path)
	if err != nil {
		return false
	}

	return bytes.Equal(got, want)
}

// --- Rainmeter.ini durci (D4) ------------------------------------------------

// BuildHardenedRainmeterIni génère le Rainmeter.ini DURCI (settings
// d'instance) — c'est LUI qui verrouille réellement (la skin seule ne suffit
// pas, piège n° 6). Contenu déterministe (pas de champ volatil) → idempotence
// par hash. Servi tel quel (ASCII pur : pas de conversion UTF-16 nécessaire,
// mais on le pose aussi en UTF-16 LE + BOM pour homogénéité Rainmeter — voir
// EnsureRainmeterConfig).
//
//   - [Rainmeter] TrayIcon=0 / Debug=0 : aucune icône de tray pilotable par
//     l'élève, démarrage silencieux ;
//   - [SambaEduOverlay\SambaEduOverlay] : la section d'INSTANCE (settings) de
//     la skin active porte Draggable=0 / ClickThrough=1 / KeepOnScreen=1 + une
//     position d'AMORÇAGE (Active=1, l'instance est chargée au démarrage de
//     Rainmeter).
//
// IMPORTANT (#18/M2) : WindowX/WindowY dans le Rainmeter.ini de settings sont
// des ENTIERS PIXELS BRUTS — Rainmeter n'y résout NI variable (#WORKAREAWIDTH#)
// NI formule. Le positionnement fin (haut-droite, recalé au redimensionnement)
// est délégué à l'OnRefreshAction=!Move de la SKIN
// (#SCREENAREAWIDTH#-#PanelW#-24). Ces valeurs ne sont donc qu'une amorce avant
// le premier refresh de la skin.
func BuildHardenedRainmeterIni() string {
	lines := []string{
		"[Rainmeter]",
		// Pas d'icône de tray : l'élève ne pilote/masque rien depuis le tray.
		"TrayIcon=0",
		// Démarrage silencieux, pas de fenêtre de bienvenue/notif.
		"Debug=0",
		"",
		// Section d'INSTANCE de la skin (Config\Skin) — verrouillage dur.
		"[" + rainmeterSkinName + "\\" + rainmeterSkinName + "]",
		"Active=1",
		// Non déplaçable (l'élève ne peut pas la traîner hors de l'écran).
		"Draggable=0",
		// Clics traversent l'overlay (ni focus, ni menu contextuel skin).
		"ClickThrough=1",
		// Épinglée à l'écran (jamais hors zone visible).
		"KeepOnScreen=1",
		// Position d'AMORÇAGE en ENTIERS pixels (pas de variable/formule ici —
		// non résolues dans le .ini de settings). Le placement haut-droite
		// définitif est recalé par l'OnRefreshAction=!Move de la skin.
		"WindowX=0",
		"WindowY=0",
		// Toujours au-dessus (l'overlay ne disparaît pas derrière une fenêtre).
		"AlwaysOnTop=1",
		// Pas de snap au bord, pas de fondu au survol.
		"SnapEdges=0",
		"",
	}

	return strings.Join(lines, "\r\n")
}

// --- Extraction portable (logique pure, archive/zip cross-platform) ----------

// ExtractPortableZip extrait une archive ZIP (portable Rainmeter) dans destDir,
// avec défense anti zip-slip (jamais d'écriture hors destDir) et borne par
// entrée. Cross-platform (archive/zip) : testable sur l'hôte. NE pose PAS
// d'ACL — c'est l'appelant Windows qui ACL le dossier cible APRÈS extraction
// (les fichiers héritent). Idempotent par recouvrement (un re-extract réécrit
// les mêmes octets).
func ExtractPortableZip(archive []byte, destDir string) error {
	zr, err := zip.NewReader(bytes.NewReader(archive), int64(len(archive)))
	if err != nil {
		return fmt.Errorf("lecture de l'archive : %w", err)
	}
	if err := os.MkdirAll(destDir, 0o700); err != nil {
		return fmt.Errorf("création de %s : %w", destDir, err)
	}

	cleanDest := filepath.Clean(destDir)
	// Compteur de bytes TOTAUX décompressés (#9) : borne cumulée en plus de la
	// borne par entrée — défense zip-bomb complète.
	var totalBytes int64
	for _, f := range zr.File {
		// Défense zip-slip : le chemin cible doit RESTER sous destDir (jamais
		// un `..` ni un chemin absolu n'échappe).
		target := filepath.Join(cleanDest, filepath.FromSlash(f.Name))
		if target != cleanDest && !strings.HasPrefix(target, cleanDest+string(os.PathSeparator)) {
			return fmt.Errorf("entrée d'archive hors du dossier cible (zip-slip) : %q", f.Name)
		}

		if f.FileInfo().IsDir() {
			if err := os.MkdirAll(target, 0o700); err != nil {
				return fmt.Errorf("création de %s : %w", target, err)
			}

			continue
		}

		n, err := extractZipEntry(f, target)
		if err != nil {
			return err
		}
		totalBytes += n
		if totalBytes > rainmeterUnzipMaxTotalBytes {
			return fmt.Errorf("archive dépasse la borne totale de décompression (%d Mio cumulés)", rainmeterUnzipMaxTotalBytes>>20)
		}
	}

	return nil
}

// extractZipEntry décompresse une entrée vers target et retourne le nombre de
// bytes écrits (pour la comptabilité de la borne totale, #9).
func extractZipEntry(f *zip.File, target string) (int64, error) {
	if err := os.MkdirAll(filepath.Dir(target), 0o700); err != nil {
		return 0, fmt.Errorf("création de %s : %w", filepath.Dir(target), err)
	}

	rc, err := f.Open()
	if err != nil {
		return 0, fmt.Errorf("ouverture de l'entrée %q : %w", f.Name, err)
	}
	defer rc.Close()

	// Borne anti zip-bomb par ENTRÉE : on lit au plus rainmeterUnzipMaxBytes+1
	// et on rejette si l'entrée dépasse (jamais une décompression non bornée).
	data, err := io.ReadAll(io.LimitReader(rc, rainmeterUnzipMaxBytes+1))
	if err != nil {
		return 0, fmt.Errorf("décompression de l'entrée %q : %w", f.Name, err)
	}
	if len(data) > rainmeterUnzipMaxBytes {
		return 0, fmt.Errorf("entrée %q dépasse la borne de décompression (%d Mio)", f.Name, rainmeterUnzipMaxBytes>>20)
	}

	// Écriture atomique dans le dossier (tmp PID + rename) — hérite de l'ACL
	// du dossier (Users:R) une fois l'arbre ACLé.
	if err := WriteFileAtomic(target, data); err != nil {
		return 0, err
	}

	return int64(len(data)), nil
}
