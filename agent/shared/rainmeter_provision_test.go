package shared

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"
)

// Story 25.6 — provisioning Rainmeter PILOTÉ PAR LE MANIFEST servi (embed
// retiré, checksum lu de l'état). On valide :
//   - manifest → download portable + vérif SHA-256 AVANT extraction ;
//   - skin téléchargée (vérif SHA-256 AVANT écriture) + conversion UTF-16 LE+BOM ;
//   - tool absent/désactivé (tool: null) → no-op gracieux, JAMAIS d'erreur ni
//     de désinstallation (D4) ;
//   - hash divergent (portable ou skin) → rejet, rien n'est posé/extrait.
//
// Serveur de test DÉDIÉ : il route les trois endpoints token'd du canal
// Rainmeter (/tools-manifest, /tools/<filename>, /overlay-skin). Le token n'est
// pas vérifié (writeToken suffit à faire passer ReadToken côté agent).

type fakeRainmeterServer struct {
	mu sync.Mutex

	manifestCode int
	manifestBody string

	toolBody  map[string][]byte
	toolCalls []string

	skinBody  []byte
	skinCode  int // 0 → 200 si skinBody non nil, sinon 404
	skinCalls int

	server *httptest.Server
}

func newFakeRainmeterServer(t *testing.T) *fakeRainmeterServer {
	t.Helper()
	f := &fakeRainmeterServer{
		manifestCode: 200,
		toolBody:     map[string][]byte{},
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/api/v1/agent/tools-manifest", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		if f.manifestCode != 200 {
			w.WriteHeader(f.manifestCode)

			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(200)
		_, _ = w.Write([]byte(f.manifestBody))
	})
	mux.HandleFunc("/api/v1/agent/tools/", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		filename := strings.TrimPrefix(r.URL.Path, "/api/v1/agent/tools/")
		f.toolCalls = append(f.toolCalls, filename)
		body, ok := f.toolBody[filename]
		if !ok {
			w.WriteHeader(404)

			return
		}
		w.WriteHeader(200)
		_, _ = w.Write(body)
	})
	mux.HandleFunc("/api/v1/agent/overlay-skin", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.skinCalls++
		if f.skinBody == nil || f.skinCode == 404 {
			w.WriteHeader(404)

			return
		}
		w.WriteHeader(200)
		_, _ = w.Write(f.skinBody)
	})

	f.server = httptest.NewServer(mux)
	t.Cleanup(f.server.Close)

	return f
}

func newRainmeterAgent(t *testing.T, f *fakeRainmeterServer) (*Agent, *RainmeterStore, Config) {
	t.Helper()
	store := newTestStore(t)
	writeToken(t, store, validToken)
	log := &Logger{}
	rmStore := &RainmeterStore{Root: t.TempDir()}
	agent := &Agent{
		Store:     store,
		Client:    NewClient(store, log, "SALLE101-PC03"),
		Log:       log,
		Hostname:  "SALLE101-PC03",
		Rainmeter: rmStore,
	}

	return agent, rmStore, Config{ServerURL: f.server.URL, IntervalSeconds: 3600}
}

// portableFixture fabrique un ZIP portable VALIDE (Rainmeter.exe + Skins/) et
// retourne (filename, sha256hex, archive).
func portableFixture(t *testing.T, version string) (filename, checksum string, archive []byte) {
	t.Helper()
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	for name, content := range map[string]string{
		"Rainmeter.exe":    "MZ-fake-portable",
		"Skins/readme.txt": "skins",
		// Le portable Rainmeter RÉEL embarque un Rainmeter.ini à la racine (aux
		// côtés de l'exe) → forcerait le MODE PORTABLE. La fixture le reproduit
		// pour que les tests d'extraction PROUVENT sa suppression (Story 27.1ter,
		// F2/F3) : après SyncRainmeterTool, store.SettingsPath() doit être absent.
		"Rainmeter.ini": "[Rainmeter]\r\nportable=default\r\n",
	} {
		w, err := zw.Create(name)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := w.Write([]byte(content)); err != nil {
			t.Fatal(err)
		}
	}
	if err := zw.Close(); err != nil {
		t.Fatal(err)
	}
	archive = buf.Bytes()
	sum := sha256.Sum256(archive)
	checksum = hex.EncodeToString(sum[:])

	return "sambaedu-rainmeter-" + version + ".zip", checksum, archive
}

func skinFixture() (body []byte, checksum string) {
	body = []byte("[Variables]\nPanelLabel=Salle B-12 · élève\n")
	sum := sha256.Sum256(body)

	return body, hex.EncodeToString(sum[:])
}

// manifestJSON assemble le corps du manifest (tool et/ou skin optionnels).
func manifestJSON(tool *rainmeterToolEntry, skin *rainmeterSkinEntry) string {
	m := map[string]any{"success": true}
	if tool != nil {
		m["tool"] = tool
	} else {
		m["tool"] = nil
	}
	if skin != nil {
		m["skin"] = skin
	} else {
		m["skin"] = nil
	}
	b, _ := json.Marshal(m)

	return string(b)
}

func TestSyncRainmeterTool_ActiveDownloadsVerifiesExtractsAndPosesSkin(t *testing.T) {
	f := newFakeRainmeterServer(t)
	filename, toolHash, archive := portableFixture(t, "4.5.18")
	skinBody, skinHash := skinFixture()
	f.toolBody[filename] = archive
	f.skinBody = skinBody
	f.manifestBody = manifestJSON(
		&rainmeterToolEntry{Key: "rainmeter", Filename: filename, SHA256: toolHash, Size: int64(len(archive))},
		&rainmeterSkinEntry{Filename: "SambaEduOverlay.ini", SHA256: skinHash},
	)

	agent, store, cfg := newRainmeterAgent(t, f)
	aclCalls := 0
	agent.RainmeterACL = func(string) error { aclCalls++; return nil }

	agent.SyncRainmeterTool(cfg)

	// Portable extrait + marqueur posé (idempotence).
	if !store.RainmeterInstalled() {
		t.Fatal("marqueur d'installation attendu après extraction complète")
	}
	if got, err := os.ReadFile(store.ExePath()); err != nil || string(got) != "MZ-fake-portable" {
		t.Fatalf("Rainmeter.exe extrait attendu : %v %q", err, got)
	}
	// Skin posée en UTF-16 LE + BOM (FF FE en tête, pas le contenu UTF-8 brut).
	raw, err := os.ReadFile(store.SkinPath())
	if err != nil {
		t.Fatalf("skin posée attendue : %v", err)
	}
	if len(raw) < 2 || raw[0] != 0xFF || raw[1] != 0xFE {
		t.Fatalf("skin doit être en UTF-16 LE + BOM : % x", raw[:min(2, len(raw))])
	}
	if bytes.Equal(raw, skinBody) {
		t.Fatal("la skin posée doit être convertie (UTF-16), pas l'UTF-8 brut servi")
	}
	// MODE INSTALLÉ (Story 27.1ter) : AUCUN Rainmeter.ini ne doit subsister sous
	// ProgramData (sa présence forcerait le mode portable → modales). Les settings
	// partent en %APPDATA%, posés par le compagnon.
	if _, err := os.Stat(store.SettingsPath()); err == nil {
		t.Fatal("aucun Rainmeter.ini ne doit être posé sous ProgramData (mode installé, Story 27.1ter)")
	}
	if aclCalls == 0 {
		t.Error("ACL Rainmeter attendue")
	}

	// Idempotence : un 2e passage ne re-télécharge PAS le portable (marqueur).
	calls := len(f.toolCalls)
	agent.SyncRainmeterTool(cfg)
	if len(f.toolCalls) != calls {
		t.Errorf("portable re-téléchargé alors qu'installé : %v", f.toolCalls)
	}
}

func TestSyncRainmeterTool_NilToolNoOpGraceful(t *testing.T) {
	f := newFakeRainmeterServer(t)
	skinBody, skinHash := skinFixture()
	f.skinBody = skinBody
	// tool: null (absent ou désactivé serveur) → provisioning portable sauté,
	// MAIS la skin + Rainmeter.ini durci convergent quand même.
	f.manifestBody = manifestJSON(nil, &rainmeterSkinEntry{Filename: "SambaEduOverlay.ini", SHA256: skinHash})

	agent, store, cfg := newRainmeterAgent(t, f)
	agent.RainmeterACL = func(string) error { return nil }

	agent.SyncRainmeterTool(cfg)

	if store.RainmeterInstalled() {
		t.Fatal("aucun portable ne doit être posé quand tool: null (D4 no-op)")
	}
	if len(f.toolCalls) != 0 {
		t.Errorf("aucun download de portable attendu (tool: null) : %v", f.toolCalls)
	}
	// Mode installé : aucun Rainmeter.ini sous ProgramData (le verrouillage durci
	// part en %APPDATA% via le compagnon).
	if _, err := os.Stat(store.SettingsPath()); err == nil {
		t.Error("aucun Rainmeter.ini ne doit être posé sous ProgramData (mode installé)")
	}
}

func TestSyncRainmeterTool_ToolHashMismatchNotExtracted(t *testing.T) {
	f := newFakeRainmeterServer(t)
	filename, _, archive := portableFixture(t, "4.5.18")
	f.toolBody[filename] = archive
	// SHA-256 annoncé ≠ contenu réel → rejeté AVANT extraction.
	f.manifestBody = manifestJSON(
		&rainmeterToolEntry{Key: "rainmeter", Filename: filename, SHA256: strings.Repeat("0", 64), Size: int64(len(archive))},
		nil,
	)

	agent, store, cfg := newRainmeterAgent(t, f)
	agent.RainmeterACL = func(string) error { return nil }

	agent.SyncRainmeterTool(cfg)

	if store.RainmeterInstalled() {
		t.Fatal("un portable au SHA-256 divergent ne doit JAMAIS être extrait/marqué")
	}
	if _, err := os.Stat(store.ExePath()); err == nil {
		t.Fatal("Rainmeter.exe ne doit pas être posé sur hash divergent")
	}
}

func TestSyncRainmeterTool_SkinHashMismatchNotPosed(t *testing.T) {
	f := newFakeRainmeterServer(t)
	skinBody, _ := skinFixture()
	f.skinBody = skinBody
	// Hash de skin annoncé ≠ contenu → skin rejetée, jamais posée.
	f.manifestBody = manifestJSON(nil, &rainmeterSkinEntry{Filename: "SambaEduOverlay.ini", SHA256: strings.Repeat("0", 64)})

	agent, store, cfg := newRainmeterAgent(t, f)
	agent.RainmeterACL = func(string) error { return nil }

	agent.SyncRainmeterTool(cfg)

	if _, err := os.Stat(store.SkinPath()); err == nil {
		t.Fatal("une skin au SHA-256 divergent ne doit JAMAIS être posée")
	}
	// Mode installé : aucun Rainmeter.ini sous ProgramData, même sans skin.
	if _, err := os.Stat(store.SettingsPath()); err == nil {
		t.Error("aucun Rainmeter.ini ne doit être posé sous ProgramData (mode installé)")
	}
}

func TestSyncRainmeterTool_NilSkinSkippedConfigStillPosed(t *testing.T) {
	f := newFakeRainmeterServer(t)
	// skin: null serveur → pose de skin sautée, mais Rainmeter.ini durci posé.
	f.manifestBody = manifestJSON(nil, nil)

	agent, store, cfg := newRainmeterAgent(t, f)
	agent.RainmeterACL = func(string) error { return nil }

	agent.SyncRainmeterTool(cfg)

	if f.skinCalls != 0 {
		t.Errorf("aucun download de skin attendu (skin: null) : %d", f.skinCalls)
	}
	if _, err := os.Stat(store.SkinPath()); err == nil {
		t.Fatal("aucune skin ne doit être posée quand skin: null")
	}
	// Mode installé : aucun Rainmeter.ini sous ProgramData.
	if _, err := os.Stat(store.SettingsPath()); err == nil {
		t.Error("aucun Rainmeter.ini ne doit être posé sous ProgramData (mode installé)")
	}
}

// TestSyncRainmeterTool_ResidualProgramDataIniRemoved : un Rainmeter.ini résiduel
// dans l'arbre ProgramData (embarqué par le zip portable OU ancien durci 27.1bis)
// est SUPPRIMÉ de façon idempotente (Story 27.1ter — sa présence forcerait le
// mode portable et ramènerait les modales). Re-passage = idempotent (no-op).
func TestSyncRainmeterTool_ResidualProgramDataIniRemoved(t *testing.T) {
	f := newFakeRainmeterServer(t)
	f.manifestBody = manifestJSON(nil, nil)

	agent, store, cfg := newRainmeterAgent(t, f)
	agent.RainmeterACL = func(string) error { return nil }

	// Simule un Rainmeter.ini résiduel posé sous ProgramData (portable embarqué).
	if err := os.MkdirAll(store.RootDir(), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(store.SettingsPath(), []byte("[Rainmeter]\nstale"), 0o600); err != nil {
		t.Fatal(err)
	}

	agent.SyncRainmeterTool(cfg)

	if _, err := os.Stat(store.SettingsPath()); err == nil {
		t.Fatal("le Rainmeter.ini résiduel sous ProgramData doit être supprimé (mode installé)")
	}

	// Idempotence : un 2e passage sans .ini ne plante pas (os.Remove ErrNotExist
	// ignoré).
	agent.SyncRainmeterTool(cfg)
	if _, err := os.Stat(store.SettingsPath()); err == nil {
		t.Fatal("aucun Rainmeter.ini ne doit réapparaître sous ProgramData")
	}
}

func TestSyncRainmeterTool_ServerUnreachableNoOp(t *testing.T) {
	f := newFakeRainmeterServer(t)
	f.manifestCode = 500 // status inattendu → no-op gracieux

	agent, store, cfg := newRainmeterAgent(t, f)
	agent.RainmeterACL = func(string) error { return nil }

	agent.SyncRainmeterTool(cfg)

	if store.RainmeterInstalled() {
		t.Fatal("rien ne doit être posé si le manifest est inexploitable")
	}
	if _, err := os.Stat(store.SettingsPath()); err == nil {
		t.Fatal("aucune convergence sans manifest exploitable")
	}
}

func TestSyncRainmeterTool_NilStoreNoOp(t *testing.T) {
	f := newFakeRainmeterServer(t)
	f.manifestBody = manifestJSON(nil, nil)

	agent, _, cfg := newRainmeterAgent(t, f)
	agent.Rainmeter = nil // plateforme sans outil de rendu (tests, !windows)

	// Ne doit ni paniquer ni appeler le serveur.
	agent.SyncRainmeterTool(cfg)
	if f.skinCalls != 0 || len(f.toolCalls) != 0 {
		t.Error("aucun appel serveur attendu sans store Rainmeter")
	}
}

func TestSyncRainmeterTool_QuarantineSkips(t *testing.T) {
	f := newFakeRainmeterServer(t)
	f.manifestBody = manifestJSON(nil, nil)

	agent, _, cfg := newRainmeterAgent(t, f)
	agent.quarantined = true

	agent.SyncRainmeterTool(cfg)
	if f.skinCalls != 0 || len(f.toolCalls) != 0 {
		t.Error("provisioning sauté en quarantaine")
	}
}

// --- ParseRainmeterManifest : validation stricte des entrées ----------------

func TestParseRainmeterManifest_RejectsBadFilenameAndHash(t *testing.T) {
	// Filename hors pattern → tool traité comme absent (nil), jamais une URL
	// dérivée d'une valeur non validée.
	body := manifestJSON(
		&rainmeterToolEntry{Key: "rainmeter", Filename: "../evil.zip", SHA256: strings.Repeat("a", 64), Size: 1},
		&rainmeterSkinEntry{Filename: "SambaEduOverlay.ini", SHA256: "not-hex"},
	)
	m, err := ParseRainmeterManifest([]byte(body))
	if err != nil {
		t.Fatal(err)
	}
	if m.Tool != nil {
		t.Error("filename hors pattern → tool nil attendu")
	}
	if m.Skin != nil {
		t.Error("hash non-hex → skin nil attendu")
	}
}

func TestParseRainmeterManifest_AcceptsValid(t *testing.T) {
	body := manifestJSON(
		&rainmeterToolEntry{Key: "rainmeter", Filename: "sambaedu-rainmeter-4.5.18.zip", SHA256: strings.Repeat("a", 64), Size: 42},
		&rainmeterSkinEntry{Filename: "SambaEduOverlay.ini", SHA256: strings.Repeat("b", 64)},
	)
	m, err := ParseRainmeterManifest([]byte(body))
	if err != nil {
		t.Fatal(err)
	}
	if m.Tool == nil || m.Tool.Filename != "sambaedu-rainmeter-4.5.18.zip" {
		t.Errorf("tool valide attendu : %+v", m.Tool)
	}
	if m.Skin == nil || m.Skin.SHA256 != strings.Repeat("b", 64) {
		t.Errorf("skin valide attendue : %+v", m.Skin)
	}
}

func TestParseRainmeterManifest_IllegibleBodyErrors(t *testing.T) {
	if _, err := ParseRainmeterManifest([]byte("{not json")); err == nil {
		t.Fatal("un corps illisible doit retourner une erreur (no-op gracieux côté appelant)")
	}
}
