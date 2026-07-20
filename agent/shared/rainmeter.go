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
	// Story 25.6 (D6/D8) — l'intégrité ET le nom du portable viennent désormais
	// du MANIFEST tool/skin servi (GET /api/v1/agent/tools-manifest), plus d'une
	// constante figée dans le binaire (27.1bis `RainmeterToolFilename` /
	// `RainmeterToolChecksum` RETIRÉES). Le catalogue serveur (table
	// `agent_tools`) est l'autorité : l'agent lit `tool.{filename, sha256}` et
	// vérifie le SHA-256 du téléchargement AVANT extraction. Outil absent ou
	// désactivé du manifest → no-op gracieux (Rainmeter absent reste gracieux,
	// invariant 24.4/24.6 — D4). La skin suit le même chemin : téléchargée
	// (vérif SHA-256) depuis GET /api/v1/agent/overlay-skin, plus d'embed
	// go:embed (D1).

	// RainmeterToolsManifestRoute : endpoint du manifest tool/skin DÉDIÉ (D8b),
	// servi sur le canal agent token'd (chaîne iso /state).
	RainmeterToolsManifestRoute = "/api/v1/agent/tools-manifest"

	// RainmeterToolsRoute : préfixe du serving binaire du portable (le filename
	// vient du manifest). Route existante 27.1bis, réutilisée.
	RainmeterToolsRoute = "/api/v1/agent/tools/"

	// RainmeterOverlaySkinRoute : serving de la skin d'overlay par la ROUTE
	// AGENT authentifiée (D7 — PAS un alias Apache public ; la skin est
	// consommée par l'agent token'd, pas client-facing comme SYSVOL).
	RainmeterOverlaySkinRoute = "/api/v1/agent/overlay-skin"

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

// InstalledSHA256 : SHA-256 de l'artefact RÉELLEMENT posé, tel qu'inscrit dans
// le marqueur par provisionRainmeterPortable (autorité serveur, D6). C'est la
// version installée sur le poste — à comparer au SHA du manifest pour décider
// d'un remplacement.
//
// Retourne ("", false) si le marqueur est absent, illisible, ou d'une longueur
// autre que 64 caractères hexadécimaux : un marqueur douteux vaut « version
// inconnue » et déclenche un (re)provisioning, plutôt que de faire confiance à
// un contenu qu'on ne sait pas interpréter. Normalisé en minuscules — le hex du
// serveur l'est (hex.EncodeToString), mais la comparaison ne doit pas dépendre
// de la casse (piège checksum-lowercase déjà rencontré sur le canal amont).
func (r *RainmeterStore) InstalledSHA256() (string, bool) {
	raw, err := os.ReadFile(r.InstalledMarkerPath())
	if err != nil {
		return "", false
	}

	sha := strings.ToLower(strings.TrimSpace(string(raw)))
	if len(sha) != sha256HexLen {
		return "", false
	}

	return sha, true
}

// hardenedSkinPath : chemin de la racine des skins (verrouillées RX sous
// ProgramData) que le Rainmeter.ini durci déclare via SkinPath (Story 27.1ter).
// Composé des constantes canoniques de PRODUCTION Windows (DefaultRainmeterRoot
// + rainmeterSkinsDirName) avec séparateur Windows et un backslash terminal
// (convention SkinPath). Pur/déterministe : BuildHardenedRainmeterIni reste sans
// accès au store, mais ce chemin reste cohérent avec RainmeterStore.SkinsDir()
// en production (Root vide → DefaultRainmeterRoot).
func hardenedSkinPath() string {
	return DefaultRainmeterRoot + `\` + rainmeterSkinsDirName + `\`
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
		// MODE INSTALLÉ (Story 27.1ter) : ce Rainmeter.ini vit désormais en
		// %APPDATA%\Rainmeter\ (writable, posé par le compagnon en droits user —
		// plus de modale « not writable » ni « Safe Start »). Comme les settings
		// ne sont plus AUX CÔTÉS de Rainmeter.exe, on doit dire EXPLICITEMENT à
		// Rainmeter où trouver ses skins : SkinPath pointe l'arbre RX verrouillé
		// sous ProgramData. La section [SambaEduOverlay] ci-dessous est résolue
		// RELATIVEMENT à ce SkinPath (→ <SkinPath>\SambaEduOverlay\SambaEduOverlay.ini).
		// Chemin dérivé des constantes du store (DefaultRainmeterRoot +
		// rainmeterSkinsDirName), terminé par un séparateur (convention SkinPath
		// Rainmeter). La fonction est pure (pas d'accès au store) : on compose le
		// chemin de production canonique à partir des mêmes constantes.
		"SkinPath=" + hardenedSkinPath(),
		"",
		// Section d'INSTANCE de la skin — verrouillage dur. La section Rainmeter.ini
		// = le CHEMIN du dossier de config relatif à Skins\ (ici `SambaEduOverlay`,
		// le dossier qui contient SambaEduOverlay.ini) ; `Active=1` sélectionne le
		// 1er .ini de ce dossier. PAS `[SambaEduOverlay\SambaEduOverlay]` : cette
		// forme à deux niveaux ferait chercher un dossier
		// Skins\SambaEduOverlay\SambaEduOverlay\ inexistant → aucune skin activée →
		// écran vide (le .ini est un FICHIER dans le dossier de config, jamais un
		// sous-dossier).
		"[" + rainmeterSkinName + "]",
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
		// Niveau BUREAU (-2 = « On Desktop ») : l'overlay reste en ARRIÈRE-PLAN,
		// jamais au-dessus des applications de l'élève — il se comporte comme un
		// widget de bureau (visible sur le bureau, masqué par une fenêtre au
		// premier plan). Réglage demandé 2026-06-16 (avant : 1 = topmost).
		"AlwaysOnTop=-2",
		// Pas de snap au bord, pas de fondu au survol.
		"SnapEdges=0",
		"",
	}

	return strings.Join(lines, "\r\n")
}

// BuildUserRainmeterIniBytes : contenu OCTET du Rainmeter.ini per-user
// (%APPDATA%\Rainmeter\Rainmeter.ini) en UTF-16 LE + BOM, prêt à écrire (Story
// 27.1ter, mode installé). Pivot PUR/déterministe testé sur l'hôte : c'est le
// contenu durci (BuildHardenedRainmeterIni, avec SkinPath) que le compagnon
// réimpose à chaque logon. Le chemin %APPDATA% (résolution + écriture atomique)
// reste hors d'ici (agent/windows, injecté) — seule la fabrication du contenu
// est partagée. UTF-16 LE + BOM pour homogénéité Rainmeter (iso 27.1bis).
func BuildUserRainmeterIniBytes() ([]byte, error) {
	return ToUTF16LEWithBOM(BuildHardenedRainmeterIni())
}

// WriteUserRainmeterIni écrit le Rainmeter.ini per-user durci à path
// (%APPDATA%\Rainmeter\Rainmeter.ini), de façon ATOMIQUE et IDEMPOTENTE — il ne
// réécrit que si le fichier est absent ou divergent du durci attendu (hash
// octet, UTF-16 LE + BOM sensible). AUCUNE ACL n'est posée : le fichier doit
// rester WRITABLE (c'est tout l'objet de la story 27.1ter — propriété naturelle
// de l'user dans son %APPDATA%). Crée le dossier parent si besoin. Pur (os.*
// portable) : la SEULE part Windows est la résolution de %APPDATA% par
// l'appelant (agent/windows). Retourne (true, nil) si une écriture a eu lieu,
// (false, nil) si déjà conforme (no-op idempotent).
//
// ⚠️ Le no-op idempotent ne vaut QUE tant que Rainmeter n'a pas encore consommé
// le fichier. En régime établi, Rainmeter écrit SES propres settings (positions,
// @Backup, marqueur d'arrêt) dans ce même Rainmeter.ini dès qu'il tourne → au
// logon SUIVANT le fichier diverge du durci pur et la réécriture A LIEU. C'est
// VOULU (D5 : réimposition du durci à chaque logon, l'élève ne fige pas une
// config altérée), PAS une optimisation « zéro écriture en régime stable ».
// L'écriture précède le lancement de Rainmeter (compagnon : avant le watchdog),
// donc jamais de conflit avec une instance vivante.
func WriteUserRainmeterIni(path string) (bool, error) {
	want, err := BuildUserRainmeterIniBytes()
	if err != nil {
		return false, fmt.Errorf("contenu du Rainmeter.ini per-user : %w", err)
	}
	if fileMatchesContent(path, want) {
		return false, nil // déjà conforme — idempotent
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return false, fmt.Errorf("création du dossier %s : %w", filepath.Dir(path), err)
	}
	if err := WriteFileAtomic(path, want); err != nil {
		return false, err
	}

	return true, nil
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
