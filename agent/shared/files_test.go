package shared

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func newTestStore(t *testing.T) *Store {
	t.Helper()

	return &Store{Root: t.TempDir()}
}

func writeToken(t *testing.T, s *Store, token string) {
	t.Helper()
	if err := os.WriteFile(s.TokenPath(), []byte(token), 0o600); err != nil {
		t.Fatal(err)
	}
}

const validToken = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"

func TestReadTokenValidatesFormat(t *testing.T) {
	s := newTestStore(t)

	if _, err := s.ReadToken(); err == nil || !strings.Contains(err.Error(), "non enrôlé") {
		t.Errorf("token absent : erreur 'non enrôlé' attendue, got %v", err)
	}

	writeToken(t, s, "pas-un-token")
	if _, err := s.ReadToken(); err == nil {
		t.Error("token malformé : erreur attendue")
	}

	writeToken(t, s, strings.ToUpper(validToken))
	if _, err := s.ReadToken(); err == nil {
		t.Error("hex majuscule : erreur attendue (contrat = 64 hex minuscule)")
	}

	// Newline parasite trimée (iso-24.2).
	writeToken(t, s, validToken+"\n")
	got, err := s.ReadToken()
	if err != nil || got != validToken {
		t.Errorf("token avec newline : got %q, %v", got, err)
	}
}

func TestWriteTokenAtomicWithACL(t *testing.T) {
	s := newTestStore(t)
	aclCalls := []string{}
	s.SetACL = func(path string) error {
		aclCalls = append(aclCalls, path)

		return nil
	}

	if err := s.WriteToken(validToken); err != nil {
		t.Fatalf("WriteToken : %v", err)
	}

	raw, err := os.ReadFile(s.TokenPath())
	if err != nil || string(raw) != validToken {
		t.Errorf("token écrit : got %q, %v (attendu : 64 hex SANS newline)", raw, err)
	}
	if len(aclCalls) != 1 || !strings.HasSuffix(aclCalls[0], ".tmp") {
		t.Errorf("ACL posée sur le fichier TEMPORAIRE avant rename attendu, got %v", aclCalls)
	}

	// Aucun .tmp résiduel.
	entries, _ := os.ReadDir(s.Root)
	for _, e := range entries {
		if strings.Contains(e.Name(), ".tmp") {
			t.Errorf("fichier temporaire résiduel : %s", e.Name())
		}
	}

	if err := s.WriteToken("court"); err == nil {
		t.Error("refus d'écrire un token malformé attendu")
	}
}

func TestEtagStoredVerbatim(t *testing.T) {
	s := newTestStore(t)

	if got := s.ReadEtag(); got != "" {
		t.Errorf("ETag absent : chaîne vide attendue, got %q", got)
	}

	// VERBATIM : guillemets RFC 7232 INCLUS — tout trim/déquotage brise le 304.
	etag := `"` + frozenStateHash + `"`
	if err := s.WriteStateCache([]byte(`{"schema":"se5.desired-state/v1"}`), etag); err != nil {
		t.Fatalf("WriteStateCache : %v", err)
	}
	if got := s.ReadEtag(); got != etag {
		t.Errorf("ETag verbatim : got %q, want %q", got, etag)
	}

	state, err := s.ReadStateCache()
	if err != nil || string(state) != `{"schema":"se5.desired-state/v1"}` {
		t.Errorf("cache état : got %q, %v", state, err)
	}
}

func TestEnsureLayoutCreatesEmptyAppliedState(t *testing.T) {
	s := newTestStore(t)

	if err := s.EnsureLayout(); err != nil {
		t.Fatalf("EnsureLayout : %v", err)
	}

	// applied-state.json créé VIDE — infra du mode `default` (gap 1) pour 24.6.
	raw, err := os.ReadFile(s.AppliedStatePath())
	if err != nil || string(raw) != "{}" {
		t.Errorf("applied-state.json : got %q, %v (attendu {})", raw, err)
	}
	if _, err := os.Stat(s.CacheDir()); err != nil {
		t.Errorf("cache\\ doit exister : %v", err)
	}

	// Idempotent : un applied-state EXISTANT n'est jamais écrasé (préservé).
	if err := os.WriteFile(s.AppliedStatePath(), []byte(`{"wallpaper":"x"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := s.EnsureLayout(); err != nil {
		t.Fatalf("EnsureLayout (2e) : %v", err)
	}
	raw, _ = os.ReadFile(s.AppliedStatePath())
	if string(raw) != `{"wallpaper":"x"}` {
		t.Errorf("applied-state existant écrasé : %q", raw)
	}
}

func TestReadConfig(t *testing.T) {
	s := newTestStore(t)

	if _, err := s.ReadConfig(); err == nil {
		t.Error("config absente : erreur attendue")
	}

	write := func(content string) {
		if err := os.WriteFile(s.ConfigPath(), []byte(content), 0o600); err != nil {
			t.Fatal(err)
		}
	}

	write(`{"server_url":"http://se5.example.lan/","interval_seconds":600}`)
	cfg, err := s.ReadConfig()
	if err != nil {
		t.Fatalf("ReadConfig : %v", err)
	}
	if cfg.ServerURL != "http://se5.example.lan" {
		t.Errorf("server_url (trailing slash trimé) : got %q", cfg.ServerURL)
	}
	if cfg.IntervalSeconds != 600 {
		t.Errorf("interval_seconds : got %d", cfg.IntervalSeconds)
	}

	// interval absent ou invalide → défaut 3600 (D7).
	write(`{"server_url":"http://se5"}`)
	cfg, _ = s.ReadConfig()
	if cfg.IntervalSeconds != DefaultIntervalSeconds {
		t.Errorf("interval par défaut : got %d, want %d", cfg.IntervalSeconds, DefaultIntervalSeconds)
	}

	write(`{"interval_seconds":600}`)
	if _, err := s.ReadConfig(); err == nil {
		t.Error("server_url manquant : erreur attendue")
	}

	write(`{broken`)
	if _, err := s.ReadConfig(); err == nil {
		t.Error("JSON invalide : erreur attendue")
	}
}

func TestWriteConfigRoundTrip(t *testing.T) {
	s := &Store{Root: filepath.Join(t.TempDir(), "sous", "dossier")}

	if err := s.WriteConfig(Config{ServerURL: "http://se5", IntervalSeconds: 1200}); err != nil {
		t.Fatalf("WriteConfig : %v", err)
	}
	cfg, err := s.ReadConfig()
	if err != nil || cfg.ServerURL != "http://se5" || cfg.IntervalSeconds != 1200 {
		t.Errorf("round-trip config : %+v, %v", cfg, err)
	}
}

func TestDefaultRootIsTheFrozenContract(t *testing.T) {
	s := &Store{}
	if s.TokenPath() != `C:\ProgramData\SambaEdu\Agent\token` && os.PathSeparator == '\\' {
		t.Errorf("chemin token = CONTRAT FIGÉ 23.3, got %q", s.TokenPath())
	}
	if DefaultAgentRoot != `C:\ProgramData\SambaEdu\Agent` {
		t.Errorf("racine agent = CONTRAT FIGÉ, got %q", DefaultAgentRoot)
	}
}
