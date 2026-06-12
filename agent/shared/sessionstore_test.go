package shared

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const testSID = "S-1-5-21-1111111111-2222222222-3333333333-1001"

func TestSessionPathsFollowContracts(t *testing.T) {
	// Chemins = CONTRATS 24.3/24.4 (le serveur et la doc QA les connaissent).
	s := &Store{Root: "ROOT"}

	cases := map[string]string{
		s.SessionStatePath(testSID):  filepath.Join("ROOT", "cache", "sessions", testSID, "state.json"),
		s.SessionEtagPath(testSID):   filepath.Join("ROOT", "cache", "sessions", testSID, "etag.txt"),
		s.AssetPath("abc.jpg"):       filepath.Join("ROOT", "assets", "abc.jpg"),
		s.SessionReportPath(testSID): filepath.Join("ROOT", "reports", "sessions", testSID, "session-report.json"),
	}
	for got, want := range cases {
		if got != want {
			t.Errorf("chemin contrat : got %q, want %q", got, want)
		}
	}

	u := &UserStore{Root: "USER"}
	if u.AppliedStatePath() != filepath.Join("USER", "applied-state.json") ||
		u.OverlayPath() != filepath.Join("USER", "overlay.json") {
		t.Error("chemins per-user")
	}
}

func TestSessionCacheEtagPerContextVerbatim(t *testing.T) {
	s := newTestStore(t)

	// ETag machine et ETag de session sont des CONTEXTES distincts.
	if err := s.WriteStateCache([]byte(`{"m":1}`), `"etag-machine"`); err != nil {
		t.Fatal(err)
	}
	if got := s.ReadSessionEtag(testSID); got != "" {
		t.Errorf("aucun ETag de session attendu, got %q", got)
	}

	if err := s.WriteSessionStateCache(testSID, []byte(`{"s":1}`), `"etag-session"`, nil); err != nil {
		t.Fatal(err)
	}
	// VERBATIM, guillemets RFC 7232 inclus.
	if got := s.ReadSessionEtag(testSID); got != `"etag-session"` {
		t.Errorf("ETag de session verbatim : got %q", got)
	}
	if got := s.ReadEtag(); got != `"etag-machine"` {
		t.Errorf("ETag machine inchangé : got %q", got)
	}
	if raw, err := s.ReadSessionStateCache(testSID); err != nil || string(raw) != `{"s":1}` {
		t.Errorf("cache de session : %q %v", raw, err)
	}

	// Deux SID = deux contextes.
	other := "S-1-5-21-1111111111-2222222222-3333333333-1002"
	if got := s.ReadSessionEtag(other); got != "" {
		t.Errorf("contextes isolés par SID, got %q", got)
	}
}

func TestSessionCacheACLPostedAtCreationOnly(t *testing.T) {
	s := newTestStore(t)
	aclCalls := []string{}
	acl := func(path, sid string) error {
		aclCalls = append(aclCalls, sid+"|"+path)

		return nil
	}

	if err := s.WriteSessionStateCache(testSID, []byte(`{}`), `"e1"`, acl); err != nil {
		t.Fatal(err)
	}
	if len(aclCalls) != 1 || !strings.Contains(aclCalls[0], testSID) {
		t.Fatalf("ACL posée UNE fois à la création du répertoire per-SID : %v", aclCalls)
	}

	// Réécriture : ACL jamais reposée (les fichiers héritent).
	if err := s.WriteSessionStateCache(testSID, []byte(`{}`), `"e2"`, acl); err != nil {
		t.Fatal(err)
	}
	if len(aclCalls) != 1 {
		t.Errorf("pas de ré-ACL à la réécriture : %v", aclCalls)
	}

	// Aucun tmp résiduel.
	entries, _ := os.ReadDir(s.SessionCacheDir(testSID))
	for _, e := range entries {
		if strings.Contains(e.Name(), ".tmp") {
			t.Errorf("tmp résiduel : %s", e.Name())
		}
	}
}

func TestEnsureSessionReportDirACL(t *testing.T) {
	s := newTestStore(t)
	aclCalls := 0
	acl := func(path, sid string) error {
		aclCalls++
		if sid != testSID || path != s.SessionReportDir(testSID) {
			t.Errorf("ACL drop : %q %q", path, sid)
		}

		return nil
	}

	if err := s.EnsureSessionReportDir(testSID, acl); err != nil {
		t.Fatal(err)
	}
	if err := s.EnsureSessionReportDir(testSID, acl); err != nil {
		t.Fatal(err)
	}
	if aclCalls != 1 {
		t.Errorf("ACL à la création seulement : %d appels", aclCalls)
	}
}

func TestEnsureAssetsDirACL(t *testing.T) {
	s := newTestStore(t)
	aclCalls := 0

	if err := s.EnsureAssetsDir(func(path string) error { aclCalls++; return nil }); err != nil {
		t.Fatal(err)
	}
	if err := s.EnsureAssetsDir(func(path string) error { aclCalls++; return nil }); err != nil {
		t.Fatal(err)
	}
	if aclCalls != 1 {
		t.Errorf("ACL assets à la création seulement : %d", aclCalls)
	}
}

func TestAppliedStateRoundTripAndCorruption(t *testing.T) {
	path := filepath.Join(t.TempDir(), "applied-state.json")

	// Absent = map vide, pas corrompu (premier passage).
	state, corrupted := ReadAppliedState(path)
	if corrupted || len(state) != 0 {
		t.Errorf("absent : %v %v", state, corrupted)
	}

	state["wallpaper"] = AppliedEntry{Hash: "aaa", AppliedAt: "2026-06-12T10:00:00Z"}
	if err := WriteAppliedState(path, state); err != nil {
		t.Fatal(err)
	}
	got, corrupted := ReadAppliedState(path)
	if corrupted || got["wallpaper"].Hash != "aaa" || got["wallpaper"].AppliedAt != "2026-06-12T10:00:00Z" {
		t.Errorf("round-trip : %+v %v", got, corrupted)
	}

	// Corrompu = map vide + corrupted (repart sans mémoire, §5).
	if err := os.WriteFile(path, []byte("{broken"), 0o600); err != nil {
		t.Fatal(err)
	}
	got, corrupted = ReadAppliedState(path)
	if !corrupted || len(got) != 0 {
		t.Errorf("corrompu : %+v %v", got, corrupted)
	}
}

func TestBuildSessionReportDropFormat(t *testing.T) {
	raw, err := BuildSessionReportDrop("2026-06-12T10:00:00Z", []ReportItem{
		{Type: "wallpaper", Status: "compliant", Hash: strings.Repeat("a", 64)},
	})
	if err != nil {
		t.Fatal(err)
	}
	want := `{"generated_at":"2026-06-12T10:00:00Z","items":[{"type":"wallpaper","status":"compliant","hash":"` + strings.Repeat("a", 64) + `"}]}`
	if string(raw) != want {
		t.Errorf("drop : got %s, want %s", raw, want)
	}

	// items nil → [] (jamais null).
	raw, _ = BuildSessionReportDrop("2026-06-12T10:00:00Z", nil)
	if !strings.Contains(string(raw), `"items":[]`) {
		t.Errorf("items vides : %s", raw)
	}
}

func TestWriteFileAtomicNoTmpResidue(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "f.json")

	if err := WriteFileAtomic(path, []byte("v1")); err != nil {
		t.Fatal(err)
	}
	if err := WriteFileAtomic(path, []byte("v2")); err != nil {
		t.Fatal(err)
	}
	raw, _ := os.ReadFile(path)
	if string(raw) != "v2" {
		t.Errorf("contenu : %q", raw)
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 1 {
		t.Errorf("tmp résiduel : %v", entries)
	}
}

func TestLoggerParametrizedFileName(t *testing.T) {
	dir := t.TempDir()
	log := &Logger{Dir: dir, FileName: "companion.log"}
	log.Infof("ligne compagnon")

	raw, err := os.ReadFile(filepath.Join(dir, "companion.log"))
	if err != nil || !strings.Contains(string(raw), "ligne compagnon") {
		t.Errorf("companion.log : %q %v", raw, err)
	}
}
