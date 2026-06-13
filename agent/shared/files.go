package shared

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// Contrats locaux du poste (FIGÉS 23.3 / 24.2 — docs/agent/enrollment.md §3,
// agent/README.md). La racine est paramétrable (testabilité hôte Linux) mais
// vaut TOUJOURS C:\ProgramData\SambaEdu\Agent en production Windows.
const (
	// DefaultAgentRoot est la racine de données de l'agent sur le poste —
	// le chemin du token y est un CONTRAT (purge sysprep obligatoire).
	DefaultAgentRoot = `C:\ProgramData\SambaEdu\Agent`

	tokenFile        = "token"
	configFile       = "config.json"
	cacheDirName     = "cache"
	stateCacheFile   = "state.json"
	etagCacheFile    = "etag.txt"
	appliedStateFile = "applied-state.json"
	logsDirName      = "logs"

	// DefaultIntervalSeconds : cadence par défaut (D7), iso ttl_seconds
	// conseillé par le serveur.
	DefaultIntervalSeconds = 3600
)

var tokenPattern = regexp.MustCompile(`^[0-9a-f]{64}$`)

// Store encapsule tous les accès disque de l'agent : écritures ATOMIQUES
// (fichier temporaire suffixé PID + rename — deux écrivains SYSTEM possibles,
// décision 24.3 conservée) et ACL posée à la création via SetACL.
//
// SetACL est injecté par le binaire Windows (shell-out icacls.exe, iso-24.2 :
// *S-1-5-18 SYSTEM + *S-1-5-32-544 Administrators, héritage retiré) ; nil =
// no-op (tests hôte Linux).
type Store struct {
	Root   string
	SetACL func(path string) error
}

func (s *Store) root() string {
	if s.Root == "" {
		return DefaultAgentRoot
	}

	return s.Root
}

func (s *Store) setACL(path string) error {
	if s.SetACL == nil {
		return nil
	}

	return s.SetACL(path)
}

// TokenPath : chemin du token — CONTRAT FIGÉ 23.3, ne jamais en dériver.
func (s *Store) TokenPath() string  { return filepath.Join(s.root(), tokenFile) }
func (s *Store) ConfigPath() string { return filepath.Join(s.root(), configFile) }
func (s *Store) CacheDir() string   { return filepath.Join(s.root(), cacheDirName) }
func (s *Store) StateCachePath() string {
	return filepath.Join(s.CacheDir(), stateCacheFile)
}
func (s *Store) EtagCachePath() string {
	return filepath.Join(s.CacheDir(), etagCacheFile)
}
func (s *Store) AppliedStatePath() string {
	return filepath.Join(s.root(), appliedStateFile)
}
func (s *Store) LogsDir() string { return filepath.Join(s.root(), logsDirName) }

// ReadToken relit le token sur disque — appelé À CHAQUE cycle (la rotation
// peut changer le fichier entre deux cycles, et un autre acteur SYSTEM peut
// l'avoir écrit). 64 hex sans newline (contrat 23.3) ; les espaces/newline
// parasites sont trimés avant validation (iso-24.2).
func (s *Store) ReadToken() (string, error) {
	raw, err := os.ReadFile(s.TokenPath())
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return "", fmt.Errorf("token introuvable : %s (poste non enrôlé ?)", s.TokenPath())
		}

		return "", fmt.Errorf("lecture du token : %w", err)
	}

	token := strings.TrimSpace(string(raw))
	if !tokenPattern.MatchString(token) {
		return "", fmt.Errorf("token malformé dans %s (attendu : 64 hex sans newline)", s.TokenPath())
	}

	return token, nil
}

// TokenExists indique si le fichier token est présent sur disque — sans le
// valider (un token malformé « existe » au sens de ce prédicat). Story 25.4 :
// l'auto-enroll (porte 2) ne se déclenche que sur l'ABSENCE du fichier ; un
// token présent mais corrompu reste un échec de cycle (backoff), jamais une
// raison de re-poster une demande d'enrôlement (un poste enrôlé ne se
// ré-enrôle JAMAIS automatiquement — FR22).
func (s *Store) TokenExists() bool {
	_, err := os.Stat(s.TokenPath())

	return err == nil
}

// WriteToken écrit le nouveau token ATOMIQUEMENT (rotation D5) : 64 hex sans
// newline, ACL posée sur le fichier temporaire AVANT le rename.
func (s *Store) WriteToken(token string) error {
	if !tokenPattern.MatchString(token) {
		return fmt.Errorf("refus d'écrire un token malformé (attendu : 64 hex)")
	}

	return s.writeAtomic(s.TokenPath(), []byte(token))
}

// EnsureLayout garantit cache\ (ACL à la création) et applied-state.json créé
// VIDE (`{}`) s'il n'existe pas — infrastructure du mode `default` (gap 1)
// préservée pour les handlers 24.6.
func (s *Store) EnsureLayout() error {
	if err := s.ensureDir(s.CacheDir()); err != nil {
		return err
	}
	if _, err := os.Stat(s.AppliedStatePath()); errors.Is(err, os.ErrNotExist) {
		return s.writeAtomic(s.AppliedStatePath(), []byte("{}"))
	}

	return nil
}

// ReadEtag retourne l'ETag du cache, VERBATIM (guillemets RFC 7232 inclus —
// tout trim/déquotage brise le 304). Chaîne vide si absent.
func (s *Store) ReadEtag() string {
	raw, err := os.ReadFile(s.EtagCachePath())
	if err != nil {
		return ""
	}

	return string(raw)
}

// WriteStateCache persiste l'enveloppe état BRUTE + l'ETag verbatim
// (écritures atomiques, ACL SYSTEM).
func (s *Store) WriteStateCache(state []byte, etag string) error {
	if err := s.ensureDir(s.CacheDir()); err != nil {
		return err
	}
	if err := s.writeAtomic(s.StateCachePath(), state); err != nil {
		return err
	}

	return s.writeAtomic(s.EtagCachePath(), []byte(etag))
}

// ReadStateCache retourne la dernière enveloppe état connue (brute), ou une
// erreur si aucun cache n'existe.
func (s *Store) ReadStateCache() ([]byte, error) {
	return os.ReadFile(s.StateCachePath())
}

// Config locale du poste — C:\ProgramData\SambaEdu\Agent\config.json,
// format 24.2 CONSERVÉ : {"server_url": "...", "interval_seconds": 3600}.
type Config struct {
	ServerURL       string `json:"server_url"`
	IntervalSeconds int    `json:"interval_seconds"`
}

// ReadConfig lit et valide la configuration locale. server_url obligatoire ;
// interval_seconds optionnel (défaut 3600 s, D7).
func (s *Store) ReadConfig() (Config, error) {
	raw, err := os.ReadFile(s.ConfigPath())
	if err != nil {
		return Config{}, fmt.Errorf("configuration introuvable : %s (relancer agent.exe install)", s.ConfigPath())
	}

	var cfg Config
	if err := json.Unmarshal(raw, &cfg); err != nil {
		return Config{}, fmt.Errorf("configuration invalide (%s) : %w", s.ConfigPath(), err)
	}
	cfg.ServerURL = strings.TrimRight(strings.TrimSpace(cfg.ServerURL), "/")
	if cfg.ServerURL == "" {
		return Config{}, fmt.Errorf("configuration invalide : champ server_url absent ou vide dans %s", s.ConfigPath())
	}
	if cfg.IntervalSeconds <= 0 {
		cfg.IntervalSeconds = DefaultIntervalSeconds
	}

	return cfg, nil
}

// WriteConfig pose la configuration locale (utilisé par agent.exe install).
func (s *Store) WriteConfig(cfg Config) error {
	if err := s.ensureDir(s.root()); err != nil {
		return err
	}
	raw, err := json.MarshalIndent(cfg, "", "    ")
	if err != nil {
		return err
	}

	return s.writeAtomic(s.ConfigPath(), raw)
}

func (s *Store) ensureDir(dir string) error {
	if _, err := os.Stat(dir); err == nil {
		return nil
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return fmt.Errorf("création de %s : %w", dir, err)
	}

	return s.setACL(dir)
}

// writeAtomic : fichier temporaire suffixé PID (deux écrivains SYSTEM
// possibles — TOCTOU, review 24.3 #3 conservée) + ACL + rename.
func (s *Store) writeAtomic(path string, data []byte) error {
	tmp := fmt.Sprintf("%s.%d.tmp", path, os.Getpid())
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return fmt.Errorf("écriture de %s : %w", tmp, err)
	}
	if err := s.setACL(tmp); err != nil {
		_ = os.Remove(tmp)

		return fmt.Errorf("ACL sur %s : %w", tmp, err)
	}
	if err := os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)

		return fmt.Errorf("rename %s → %s : %w", tmp, path, err)
	}

	return nil
}
