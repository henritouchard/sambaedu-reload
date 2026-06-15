package shared

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

// Extension du Store 24.5 pour le sous-système compagnon (Story 24.6) —
// chemins CONTRATS 24.3/24.4 conservés tels quels (le serveur et la doc QA
// les connaissent) :
//
//	C:\ProgramData\SambaEdu\Agent\
//	├── cache\sessions\<SID>\{state.json, etag.txt}   ← cache per-user (24.3)
//	├── assets\<filename>                              ← cache d'assets (24.4)
//	└── reports\sessions\<SID>\session-report.json     ← drop per-SID (24.4)
//
//	%LOCALAPPDATA%\SambaEdu\Agent\                      ← racine PER-USER
//	├── applied-state.json   (dernier-appliqué §5, per-user)
//	├── overlay.json         (handler overlay)
//	└── companion.log        (shared.Logger, racine paramétrée)
//
// ACL — posées À LA CRÉATION des répertoires, les fichiers HÉRITENT (jamais
// de ré-ACL des tmp : un icacls explicite SYSTEM+Admins retirerait le R/M du
// user — acquis 24.3). Injectées par le binaire Windows, nil = no-op (tests
// hôte Linux) :
//   - cache\sessions\<SID>\ : SYSTEM F, Administrators F, <SID>:(OI)(CI)R ;
//   - assets\               : SYSTEM F, Administrators F, Users:(OI)(CI)R ;
//   - reports\sessions\<SID>\ : SYSTEM F, Administrators F, <SID>:(OI)(CI)M.
const (
	sessionsDirName       = "sessions"
	assetsDirName         = "assets"
	iconsDirName          = "icons"
	reportsDirName        = "reports"
	updateDirName         = "update"
	sessionReportFileName = "session-report.json"
	overlayFileName       = "overlay.json"
	companionLogFileName  = "companion.log"

	// SessionReportMaxBytes : garde-fou de collecte — un drop user est une
	// entrée NON fiable (le user peut forger le sien), taille plafonnée
	// AVANT parse (frontière de confiance 24.4).
	SessionReportMaxBytes = 262144 // 256 KiB
)

// SessionACL : ACL d'un répertoire per-SID (cache R ou drop M), injectée par
// le binaire Windows. nil = no-op (tests hôte).
type SessionACL func(path, sid string) error

// --- Chemins (contrats 24.3/24.4) -------------------------------------------------

func (s *Store) SessionsCacheRoot() string {
	return filepath.Join(s.CacheDir(), sessionsDirName)
}

func (s *Store) SessionCacheDir(sid string) string {
	return filepath.Join(s.SessionsCacheRoot(), sid)
}

func (s *Store) SessionStatePath(sid string) string {
	return filepath.Join(s.SessionCacheDir(sid), stateCacheFile)
}

func (s *Store) SessionEtagPath(sid string) string {
	return filepath.Join(s.SessionCacheDir(sid), etagCacheFile)
}

func (s *Store) AssetsDir() string {
	return filepath.Join(s.root(), assetsDirName)
}

func (s *Store) AssetPath(filename string) string {
	return filepath.Join(s.AssetsDir(), filename)
}

// IconsDir : cache des icônes UPLOADÉES de raccourcis content-addressed
// (Story 27.7) — C:\ProgramData\SambaEdu\Agent\icons\<sha>.ico. Distinct du
// cache d'assets wallpaper (transport différent : GET HTTP statique sans
// token vs Client token'd) — mais MÊME ACL (Users:R, un .ico n'est pas un
// secret et le compagnon doit pointer l'IconLocation dessus).
func (s *Store) IconsDir() string {
	return filepath.Join(s.root(), iconsDirName)
}

// IconPath : chemin local d'une icône raccourci content-addressed. Le
// filename est validé STRICTEMENT en amont (ValidShortcutIconFilename) — un
// payload serveur reste une entrée externe, jamais de traversal depuis le
// cache d'icônes.
func (s *Store) IconPath(filename string) string {
	return filepath.Join(s.IconsDir(), filename)
}

// EnsureIconsDir crée icons\ avec son ACL (Users:R — setAssetsACL réutilisé :
// un .ico de raccourci n'est pas un secret et le compagnon doit l'afficher).
// acl nil = no-op (tests hôte). Idempotent.
func (s *Store) EnsureIconsDir(acl func(path string) error) error {
	dir := s.IconsDir()
	if _, err := os.Stat(dir); err == nil {
		return nil
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return fmt.Errorf("création de %s : %w", dir, err)
	}
	if acl != nil {
		return acl(dir)
	}

	return nil
}

// UpdateDir : répertoire de staging des binaires d'auto-update (Story 25.2,
// décision n° 5) — C:\ProgramData\SambaEdu\Agent\update\. Le téléchargement
// ET les deux vérifications (SHA-256, Authenticode) s'y font ; Program Files
// n'est touché qu'à l'instant du rename final du swap. ACL SYSTEM (pas de
// Users:R, contrairement aux assets : un binaire stagé n'est pas affiché).
func (s *Store) UpdateDir() string {
	return filepath.Join(s.root(), updateDirName)
}

// UpdateStagePath : chemin du binaire stagé pour une version donnée. Le
// filename n'est PAS user-controlled (validé en amont par SelfUpdate :
// extrait de l'url manifest, pattern strict sambaedu-agent-<version>.exe).
func (s *Store) UpdateStagePath(filename string) string {
	return filepath.Join(s.UpdateDir(), filename)
}

// EnsureUpdateDir crée update\ avec son ACL SYSTEM (SYSTEM F, Admins F — pas
// de Users:R : le staging d'un binaire n'est pas un asset affiché par la
// session). acl nil = no-op (tests hôte). Idempotent.
func (s *Store) EnsureUpdateDir(acl func(path string) error) error {
	dir := s.UpdateDir()
	if _, err := os.Stat(dir); err == nil {
		return nil
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return fmt.Errorf("création de %s : %w", dir, err)
	}
	if acl != nil {
		return acl(dir)
	}

	return nil
}

func (s *Store) ReportsRoot() string {
	return filepath.Join(s.root(), reportsDirName)
}

func (s *Store) SessionReportsRoot() string {
	return filepath.Join(s.ReportsRoot(), sessionsDirName)
}

func (s *Store) SessionReportDir(sid string) string {
	return filepath.Join(s.SessionReportsRoot(), sid)
}

func (s *Store) SessionReportPath(sid string) string {
	return filepath.Join(s.SessionReportDir(sid), sessionReportFileName)
}

// --- Cache de session per-SID (24.3) ----------------------------------------------

// EnsureSessionCacheDir crée le répertoire de cache du SID avec son ACL
// (SYSTEM F, Admins F, <SID>:R) — les parents (cache\, sessions\) restent
// SYSTEM+Admins (le user n'énumère pas l'arborescence mais ouvre son fichier
// par chemin complet, bypass traverse checking). Idempotent.
func (s *Store) EnsureSessionCacheDir(sid string, acl SessionACL) error {
	if err := s.ensureDir(s.CacheDir()); err != nil {
		return err
	}
	if err := s.ensureDir(s.SessionsCacheRoot()); err != nil {
		return err
	}

	return s.ensureSidDir(s.SessionCacheDir(sid), sid, acl)
}

// ReadSessionEtag retourne l'ETag DU contexte (poste, user) — un etag.txt
// PAR répertoire de session (réutiliser l'ETag machine casserait la
// revalidation). VERBATIM, guillemets RFC 7232 inclus. Vide si absent.
func (s *Store) ReadSessionEtag(sid string) string {
	raw, err := os.ReadFile(s.SessionEtagPath(sid))
	if err != nil {
		return ""
	}

	return string(raw)
}

// WriteSessionStateCache persiste l'enveloppe BRUTE + l'ETag verbatim du
// contexte user. Écritures atomiques tmp+PID SANS ré-ACL : les fichiers
// naissent DANS le répertoire per-SID et héritent de son ACL (acquis 24.3 —
// un icacls explicite retirerait le R du user).
func (s *Store) WriteSessionStateCache(sid string, state []byte, etag string, acl SessionACL) error {
	if err := s.EnsureSessionCacheDir(sid, acl); err != nil {
		return err
	}
	if err := WriteFileAtomic(s.SessionStatePath(sid), state); err != nil {
		return err
	}

	return WriteFileAtomic(s.SessionEtagPath(sid), []byte(etag))
}

// ReadSessionStateCache retourne la dernière enveloppe état du contexte user
// (brute), ou une erreur si aucun cache n'existe.
func (s *Store) ReadSessionStateCache(sid string) ([]byte, error) {
	return os.ReadFile(s.SessionStatePath(sid))
}

// --- Cache d'assets (24.4) ----------------------------------------------------------

// EnsureAssetsDir crée assets\ avec son ACL (SYSTEM F, Admins F, Users R —
// un wallpaper n'est pas un secret et la session doit l'afficher). acl nil =
// no-op (tests hôte). Idempotent.
func (s *Store) EnsureAssetsDir(acl func(path string) error) error {
	dir := s.AssetsDir()
	if _, err := os.Stat(dir); err == nil {
		return nil
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return fmt.Errorf("création de %s : %w", dir, err)
	}
	if acl != nil {
		return acl(dir)
	}

	return nil
}

// --- Drop per-SID (24.4) -------------------------------------------------------------

// EnsureSessionReportDir crée le répertoire de drop du SID avec son ACL
// (<SID>:M — le user ÉCRIT son session-report.json, ne lit pas les drops des
// autres). Créé par SYSTEM AVANT toute passe compagnon (le user ne peut pas
// le créer lui-même sous ProgramData). Idempotent.
func (s *Store) EnsureSessionReportDir(sid string, acl SessionACL) error {
	if err := s.ensureDir(s.ReportsRoot()); err != nil {
		return err
	}
	if err := s.ensureDir(s.SessionReportsRoot()); err != nil {
		return err
	}

	return s.ensureSidDir(s.SessionReportDir(sid), sid, acl)
}

// ensureSidDir : création + ACL per-SID à la création seulement.
func (s *Store) ensureSidDir(dir, sid string, acl SessionACL) error {
	if _, err := os.Stat(dir); err == nil {
		return nil
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return fmt.Errorf("création de %s : %w", dir, err)
	}
	if acl != nil {
		return acl(dir, sid)
	}

	return nil
}

// --- Applied-state per-user (mode default §5, Story 24.4 décision n° 5) -------------

// ReadAppliedState charge un dernier-appliqué (map type → {hash, applied_at})
// depuis path. Fichier absent ou corrompu = map vide + corrupted=true pour le
// log de l'appelant (premier passage §5 : jamais interprété comme une dérive
// humaine).
func ReadAppliedState(path string) (state AppliedState, corrupted bool) {
	state = AppliedState{}
	raw, err := os.ReadFile(path)
	if err != nil {
		return state, false
	}
	if err := json.Unmarshal(raw, &state); err != nil {
		return AppliedState{}, true
	}

	return state, false
}

// WriteAppliedState persiste le dernier-appliqué (écriture atomique tmp+PID
// — profil user : aucune ACL à poser).
func WriteAppliedState(path string, state AppliedState) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return fmt.Errorf("création de %s : %w", filepath.Dir(path), err)
	}
	raw, err := json.Marshal(state)
	if err != nil {
		return err
	}

	return WriteFileAtomic(path, raw)
}

// --- Drop session-report (écrit par le compagnon) ------------------------------------

// sessionReportDrop est le format du drop per-SID 24.4 :
// {generated_at, items: [{type, status, hash, detail?}]}.
type sessionReportDrop struct {
	GeneratedAt string       `json:"generated_at"`
	Items       []ReportItem `json:"items"`
}

// BuildSessionReportDrop sérialise un drop (items jamais null — slice vide).
func BuildSessionReportDrop(generatedAt string, items []ReportItem) ([]byte, error) {
	if items == nil {
		items = []ReportItem{}
	}

	return marshalCompactJSON(sessionReportDrop{GeneratedAt: generatedAt, Items: items})
}

// WriteFileAtomic : écriture atomique générique — fichier temporaire suffixé
// PID + rename (convention TOCTOU 24.3 conservée PARTOUT), SANS pose d'ACL
// (le fichier hérite de l'ACL du répertoire cible — cache/drop per-SID,
// assets, profil user).
func WriteFileAtomic(path string, data []byte) error {
	tmp := fmt.Sprintf("%s.%d.tmp", path, os.Getpid())
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return fmt.Errorf("écriture de %s : %w", tmp, err)
	}
	if err := os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)

		return fmt.Errorf("rename %s → %s : %w", tmp, path, err)
	}

	return nil
}

// --- Racine per-user (%LOCALAPPDATA%\SambaEdu\Agent) ---------------------------------

// UserStore : chemins du profil user du compagnon. Racine paramétrable
// (testabilité hôte) ; en production Windows = %LOCALAPPDATA%\SambaEdu\Agent.
// Aucune ACL : le profil user appartient au user.
type UserStore struct {
	Root string
}

func (u *UserStore) AppliedStatePath() string {
	return filepath.Join(u.Root, appliedStateFile)
}

func (u *UserStore) OverlayPath() string {
	return filepath.Join(u.Root, overlayFileName)
}

func (u *UserStore) CompanionLogFile() string { return companionLogFileName }

// EnsureRoot crée la racine per-user si absente.
func (u *UserStore) EnsureRoot() error {
	if _, err := os.Stat(u.Root); err == nil {
		return nil
	}
	if err := os.MkdirAll(u.Root, 0o700); err != nil && !errors.Is(err, os.ErrExist) {
		return fmt.Errorf("création de %s : %w", u.Root, err)
	}

	return nil
}
