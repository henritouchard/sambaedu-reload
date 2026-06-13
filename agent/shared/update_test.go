package shared

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"
	"time"
	"unicode/utf8"
)

// LE chemin le plus testé de l'agent (NFR8, AC6). La matrice obligatoire de la
// story tourne ICI, sur l'hôte Linux : fake serveur HTTP (manifest + binaire),
// primitives Windows (VerifyAuthenticode / SwapAndRestart) STUBÉES par
// injection (décision n° 2). Cas couverts : nominal, 404 no_release, version
// égale, hash KO, signature KO, download interrompu, download tronqué (>16 Mio),
// swap KO rapporté (orchestration), 401 arrêt, 403 release = update sauté SANS
// quarantaine (M4 — y compris un cycle complet où le report part quand même),
// report d'échec agent_update, version dans le rapport, un seul download par
// cycle, survie token après swap.
//
// Le CŒUR anti-brique (copie-atomique→re-hash→rename→ROLLBACK) est testé
// directement sur shared.PerformSwap dans swap_test.go (AC3, #6/M6) : swap
// nominal + triggerRestart appelé, staged absent (aucune mutation), hash .new
// divergent (M2, abort), rename final KO (rollback de l'ancien binaire). Ces
// tests vérifient que triggerRestart (= os.Exit côté Windows) n'est JAMAIS
// appelé si le swap échoue.

// fakeReleaseServer : serveur SE5 minimal pour l'auto-update (manifest +
// download binaire). Versions/codes/corps configurables ; compte les appels.
type fakeReleaseServer struct {
	mu sync.Mutex

	manifestCode int    // code HTTP du GET /release
	version      string // version annoncée
	hash         string // hash annoncé (défaut = SHA-256 du binaire)
	urlOverride  string // url manifest (défaut = url absolue du download)

	binaryCode int    // code HTTP du GET /releases/<filename>
	binaryBody []byte // corps servi

	manifestCalls int
	binaryCalls   int

	server *httptest.Server
}

const fakeReleaseFilename = "sambaedu-agent-9.9.9.exe"

func newFakeReleaseServer(t *testing.T) *fakeReleaseServer {
	t.Helper()
	body := []byte("MZ-fake-pe-binary-vNplus1")
	sum := sha256.Sum256(body)
	f := &fakeReleaseServer{
		manifestCode: 200,
		version:      "9.9.9",
		hash:         hex.EncodeToString(sum[:]),
		binaryCode:   200,
		binaryBody:   body,
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/api/v1/agent/release", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.manifestCalls++
		if f.manifestCode != 200 {
			w.WriteHeader(f.manifestCode)
			if f.manifestCode == 404 {
				_, _ = w.Write([]byte(`{"error":"no_release","message":"aucune release"}`))
			}

			return
		}
		dlURL := f.urlOverride
		if dlURL == "" {
			dlURL = f.server.URL + "/api/v1/agent/releases/" + fakeReleaseFilename
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(200)
		_ = json.NewEncoder(w).Encode(map[string]any{
			"success": true,
			"version": f.version,
			"hash":    f.hash,
			"url":     dlURL,
		})
	})
	mux.HandleFunc("/api/v1/agent/releases/", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.binaryCalls++
		if f.binaryCode != 200 {
			w.WriteHeader(f.binaryCode)

			return
		}
		w.WriteHeader(200)
		_, _ = w.Write(f.binaryBody)
	})

	f.server = httptest.NewServer(mux)
	t.Cleanup(f.server.Close)

	return f
}

// updateStubs : compteurs/erreurs injectables pour les primitives Windows.
type updateStubs struct {
	verifyCalls int
	swapCalls   int
	lastStaged  string
	lastVersion string
	lastHash    string

	verifyErr error
	swapErr   error
}

// newUpdateAgent : agent câblé sur le fake serveur, primitives stubées.
func newUpdateAgent(t *testing.T, f *fakeReleaseServer, stubs *updateStubs) (*Agent, *Store, Config) {
	t.Helper()
	store := newTestStore(t)
	writeToken(t, store, validToken)
	log := &Logger{}
	agent := &Agent{
		Store:    store,
		Client:   NewClient(store, log, "SALLE101-PC03"),
		Log:      log,
		Hostname: "SALLE101-PC03",
		UUID:     func() string { return "F1D2C3B4-A5E6-4789-9ABC-0123456789AB" },
		VerifyAuthenticode: func(path string) error {
			stubs.verifyCalls++
			stubs.lastStaged = path

			return stubs.verifyErr
		},
		SwapAndRestart: func(stagedPath, version, expectedHash string) error {
			stubs.swapCalls++
			stubs.lastStaged = stagedPath
			stubs.lastVersion = version
			stubs.lastHash = expectedHash

			return stubs.swapErr
		},
	}
	cfg := Config{ServerURL: f.server.URL, IntervalSeconds: 3600}

	return agent, store, cfg
}

func stagedExists(store *Store) bool {
	_, err := os.Stat(store.UpdateStagePath(fakeReleaseFilename))
	return err == nil
}

// ── Cas nominal ──────────────────────────────────────────────────────────────

func TestSelfUpdateNominalDownloadsVerifiesSwaps(t *testing.T) {
	f := newFakeReleaseServer(t)
	stubs := &updateStubs{}
	agent, store, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	if f.manifestCalls != 1 {
		t.Errorf("1 GET manifest attendu, got %d", f.manifestCalls)
	}
	if f.binaryCalls != 1 {
		t.Errorf("1 GET binaire attendu, got %d", f.binaryCalls)
	}
	if stubs.verifyCalls != 1 {
		t.Errorf("signature vérifiée une fois attendu, got %d", stubs.verifyCalls)
	}
	if stubs.swapCalls != 1 {
		t.Errorf("swap appelé une fois attendu, got %d", stubs.swapCalls)
	}
	if stubs.lastVersion != "9.9.9" {
		t.Errorf("version cible 9.9.9 attendue au swap, got %q", stubs.lastVersion)
	}
	// M2 : le hash manifest est transmis au swap pour la re-vérification du
	// binaire réellement mis en place (.new à sa position finale).
	if stubs.lastHash != f.hash {
		t.Errorf("hash manifest transmis au swap attendu (%q), got %q", f.hash, stubs.lastHash)
	}
	if !stagedExists(store) {
		t.Error("binaire stagé écrit attendu (hash OK)")
	}
	if agent.pendingUpdateError != "" {
		t.Errorf("aucun échec rapporté attendu sur cas nominal, got %q", agent.pendingUpdateError)
	}
	// L'ordre des portes : hash AVANT écriture, signature AVANT swap. Le staged
	// vérifié = le staged swappé.
	if stubs.lastStaged != store.UpdateStagePath(fakeReleaseFilename) {
		t.Errorf("la vérif/le swap portent sur le fichier stagé : got %q", stubs.lastStaged)
	}
}

// ── No-op : 404 no_release ───────────────────────────────────────────────────

func TestSelfUpdate404NoReleaseIsNoop(t *testing.T) {
	f := newFakeReleaseServer(t)
	f.manifestCode = 404
	stubs := &updateStubs{}
	agent, store, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	if f.binaryCalls != 0 {
		t.Errorf("aucun download sur 404 no_release, got %d", f.binaryCalls)
	}
	if stubs.swapCalls != 0 || stubs.verifyCalls != 0 {
		t.Error("ni swap ni vérif sur 404 no_release")
	}
	if stagedExists(store) {
		t.Error("rien d'écrit sur 404")
	}
	if agent.pendingUpdateError != "" {
		t.Errorf("404 = rien à faire, jamais un échec rapporté, got %q", agent.pendingUpdateError)
	}
}

// ── No-op : version cible == courante ────────────────────────────────────────

func TestSelfUpdateSameVersionIsNoop(t *testing.T) {
	f := newFakeReleaseServer(t)
	f.version = Version // le manifest annonce la version COURANTE
	sum := sha256.Sum256(f.binaryBody)
	f.hash = hex.EncodeToString(sum[:])
	stubs := &updateStubs{}
	agent, _, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	if f.binaryCalls != 0 {
		t.Errorf("aucun download quand version == courante, got %d", f.binaryCalls)
	}
	if stubs.swapCalls != 0 {
		t.Error("aucun swap quand version == courante")
	}
}

// ── Hash KO : jamais d'écriture, jamais de swap ──────────────────────────────

func TestSelfUpdateHashMismatchNeverWritesNeverSwaps(t *testing.T) {
	f := newFakeReleaseServer(t)
	// Le manifest annonce un hash qui ne correspond pas au binaire servi.
	f.hash = strings.Repeat("a", 64)
	stubs := &updateStubs{}
	agent, store, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	if f.binaryCalls != 1 {
		t.Errorf("le binaire est bien téléchargé avant la vérif hash, got %d", f.binaryCalls)
	}
	if stagedExists(store) {
		t.Fatal("AUCUNE écriture quand le hash diverge (porte 1)")
	}
	if stubs.verifyCalls != 0 {
		t.Error("la signature n'est JAMAIS vérifiée si le hash diverge")
	}
	if stubs.swapCalls != 0 {
		t.Error("le swap n'est JAMAIS appelé si le hash diverge")
	}
	if agent.pendingUpdateError == "" || !strings.Contains(agent.pendingUpdateError, "SHA-256") {
		t.Errorf("échec hash rapporté attendu, got %q", agent.pendingUpdateError)
	}
}

// ── Signature KO : binaire stagé mais jamais swappé ──────────────────────────

func TestSelfUpdateSignatureInvalidNeverSwaps(t *testing.T) {
	f := newFakeReleaseServer(t)
	stubs := &updateStubs{verifyErr: fmt.Errorf("chaîne non remontée à une CA de confiance")}
	agent, store, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	if stubs.verifyCalls != 1 {
		t.Errorf("la signature est vérifiée (et rejetée), got %d", stubs.verifyCalls)
	}
	if stubs.swapCalls != 0 {
		t.Error("AUCUN swap quand la signature est invalide (porte 2)")
	}
	// Le binaire a été stagé (hash OK) puis JETÉ logiquement : il reste sur
	// disque sous update\ mais ne sera jamais swappé (un staged re-vérifié au
	// prochain cycle est idempotent).
	if !stagedExists(store) {
		t.Error("binaire stagé écrit (hash OK) attendu avant la vérif signature")
	}
	if agent.pendingUpdateError == "" || !strings.Contains(agent.pendingUpdateError, "Authenticode") {
		t.Errorf("échec signature rapporté attendu, got %q", agent.pendingUpdateError)
	}
}

// ── Download : serveur injoignable → skip propre ─────────────────────────────

func TestSelfUpdateServerUnreachableSkips(t *testing.T) {
	f := newFakeReleaseServer(t)
	stubs := &updateStubs{}
	agent, store, cfg := newUpdateAgent(t, f, stubs)
	f.server.Close() // serveur injoignable

	agent.SelfUpdate(cfg) // ne panique pas, ne crashe pas

	if stagedExists(store) {
		t.Error("rien écrit quand le serveur est injoignable")
	}
	if stubs.swapCalls != 0 {
		t.Error("aucun swap quand le serveur est injoignable")
	}
	// Serveur injoignable sur le manifest = skip silencieux (retry au cycle),
	// pas un item d'échec (iso résilience : un serveur muet n'est pas un échec
	// d'update).
	if agent.pendingUpdateError != "" {
		t.Errorf("manifest injoignable = skip silencieux, got %q", agent.pendingUpdateError)
	}
}

// ── Download tronqué (>16 Mio) → hash divergent → jeté ───────────────────────

func TestSelfUpdateTruncatedDownloadIsRejectedByHash(t *testing.T) {
	f := newFakeReleaseServer(t)
	// Corps de 17 Mio : le Client le borne à 16 Mio (LimitReader) → tronqué →
	// SHA-256 du corps reçu ≠ hash annoncé (calculé sur le corps COMPLET).
	full := make([]byte, 17<<20)
	for i := range full {
		full[i] = byte(i)
	}
	sum := sha256.Sum256(full)
	f.binaryBody = full
	f.hash = hex.EncodeToString(sum[:]) // hash du corps COMPLET
	stubs := &updateStubs{}
	agent, store, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	if stagedExists(store) {
		t.Fatal("un binaire tronqué (>16 Mio) ne doit JAMAIS être écrit (hash divergent)")
	}
	if stubs.swapCalls != 0 {
		t.Error("aucun swap sur binaire tronqué")
	}
	if agent.pendingUpdateError == "" {
		t.Error("échec rapporté attendu sur binaire tronqué")
	}
}

// ── Échec de swap (SwapAndRestart renvoie une erreur) → report, pas de panique ─
// Au niveau de l'orchestration shared/, un swap KO = SwapAndRestart renvoie une
// erreur → l'échec est rapporté (item agent_update), le cycle continue. Le VRAI
// anti-brique (rollback : ancien binaire intact, triggerRestart jamais appelé)
// est testé directement sur shared.PerformSwap dans swap_test.go (AC3, #6/M6).
func TestSelfUpdateSwapFailureReportsAndAgentStaysInPlace(t *testing.T) {
	f := newFakeReleaseServer(t)
	stubs := &updateStubs{swapErr: fmt.Errorf("dépose du neuf KO, rollback effectué (ancien binaire en place intact)")}
	agent, _, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	if stubs.swapCalls != 1 {
		t.Errorf("le swap est tenté (et échoue), got %d", stubs.swapCalls)
	}
	if agent.pendingUpdateError == "" || !strings.Contains(agent.pendingUpdateError, "swap") {
		t.Errorf("échec de swap rapporté attendu (anti-brique : ancien binaire en place), got %q", agent.pendingUpdateError)
	}
}

// ── 401 sur le download → arrêt (portée machine), pas de swap ────────────────

func TestSelfUpdate401OnBinaryStops(t *testing.T) {
	f := newFakeReleaseServer(t)
	f.binaryCode = 401
	stubs := &updateStubs{}
	agent, store, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	if stagedExists(store) {
		t.Error("rien écrit sur 401")
	}
	if stubs.swapCalls != 0 {
		t.Error("aucun swap sur 401")
	}
}

func TestSelfUpdate401OnManifestStops(t *testing.T) {
	f := newFakeReleaseServer(t)
	f.manifestCode = 401
	stubs := &updateStubs{}
	agent, _, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	if f.binaryCalls != 0 {
		t.Error("aucun download sur 401 manifest")
	}
	if stubs.swapCalls != 0 {
		t.Error("aucun swap sur 401 manifest")
	}
}

// ── M4 (Option 1) : 403 release → update SAUTÉ, PAS de quarantaine globale ─────
// Un 403 sur le canal release (manifest OU download, ring gelé) ne met PAS le
// poste en quarantaine globale : il saute seulement l'update. Le poste continue
// son cycle normal et ENVOIE son report (la quarantaine globale — qui supprime
// le POST /report — reste réservée au 403 du canal principal /state, loop.go).

func TestSelfUpdate403OnManifestSkipsUpdateNoQuarantine(t *testing.T) {
	f := newFakeReleaseServer(t)
	f.manifestCode = 403
	stubs := &updateStubs{}
	agent, _, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	if agent.Quarantined() {
		t.Error("M4 : 403 release ne doit PAS mettre le poste en quarantaine globale")
	}
	if f.binaryCalls != 0 {
		t.Error("aucun download sur 403 manifest (update sauté)")
	}
	if stubs.swapCalls != 0 {
		t.Error("aucun swap sur 403 manifest")
	}
	// L'update sauté est signalé (item agent_update), mais pas une quarantaine.
	if agent.pendingUpdateError == "" || !strings.Contains(agent.pendingUpdateError, "403") {
		t.Errorf("update sauté signalé attendu (403 release), got %q", agent.pendingUpdateError)
	}
}

func TestSelfUpdate403OnBinarySkipsUpdateNoQuarantine(t *testing.T) {
	f := newFakeReleaseServer(t)
	f.binaryCode = 403
	stubs := &updateStubs{}
	agent, _, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	if agent.Quarantined() {
		t.Error("M4 : 403 download ne doit PAS mettre le poste en quarantaine globale")
	}
	if stubs.swapCalls != 0 {
		t.Error("aucun swap sur 403 download")
	}
	if agent.pendingUpdateError == "" || !strings.Contains(agent.pendingUpdateError, "403") {
		t.Errorf("update sauté signalé attendu (403 release), got %q", agent.pendingUpdateError)
	}
}

// ── M4 : 403 release dans un cycle COMPLET → pas de quarantaine, report émis ───
// La preuve « Option 1 » de bout en bout, à travers le vrai runCycle : le canal
// principal /state répond 200, mais le canal release répond 403 (ring gelé).
// Attendu : l'update est sauté, le poste N'est PAS mis en quarantaine globale,
// et le POST /report a bien lieu (le poste reste visible sur sa conformité) —
// contrairement à un 403 /state qui, lui, met en quarantaine et supprime le
// report (comportement inchangé, testé par ailleurs dans loop_test.go).
func TestRunCycle403ReleaseSkipsUpdateButStillReports(t *testing.T) {
	f := newFakeServer(t)
	f.releaseCode = 403 // le canal release répond 403 dans CE cycle
	agent, _, cfg := newTestAgent(t, f)

	// Primitives d'update câblées (sinon SelfUpdate est inerte et ne tape jamais
	// le canal release).
	swapCalls := 0
	agent.VerifyAuthenticode = func(string) error { return nil }
	agent.SwapAndRestart = func(_, _, _ string) error { swapCalls++; return nil }

	if outcome := agent.RunCycle(cfg); outcome != OutcomeOK {
		t.Fatalf("OutcomeOK attendu, got %v", outcome)
	}

	if f.releaseCalls == 0 {
		t.Fatal("pré-condition : le canal release doit avoir été consulté")
	}
	if agent.Quarantined() {
		t.Error("M4 : un 403 release ne met PAS le poste en quarantaine globale")
	}
	if swapCalls != 0 {
		t.Error("M4 : aucun swap sur 403 release (update sauté)")
	}
	// L'invariant CENTRAL de M4 : le report a bien lieu malgré le 403 release.
	if f.reportCalls != 1 || f.lastReport == nil {
		t.Errorf("M4 : le POST /report doit avoir lieu (poste non muet), reportCalls=%d", f.reportCalls)
	}
	// L'update sauté est rapporté comme un item agent_update/error (sans
	// quarantaine) : le serveur voit pourquoi le ring est gelé.
	var payload map[string]any
	if err := json.Unmarshal(f.lastReport, &payload); err != nil {
		t.Fatal(err)
	}
	items, _ := payload["items"].([]any)
	found := false
	for _, it := range items {
		m, _ := it.(map[string]any)
		if m["type"] == "agent_update" && m["status"] == "error" {
			found = true
		}
	}
	if !found {
		t.Errorf("item agent_update/error attendu (403 release signalé), got %v", payload["items"])
	}
}

// ── Quarantaine active → sauté ───────────────────────────────────────────────

func TestSelfUpdateSkippedInQuarantine(t *testing.T) {
	f := newFakeReleaseServer(t)
	stubs := &updateStubs{}
	agent, _, cfg := newUpdateAgent(t, f, stubs)
	agent.quarantined = true

	agent.SelfUpdate(cfg)

	if f.manifestCalls != 0 {
		t.Errorf("auto-update sauté en quarantaine, got %d appels manifest", f.manifestCalls)
	}
}

// ── Pas de primitives (Linux/test sans stub) → no-op ─────────────────────────

func TestSelfUpdateWithoutPrimitivesIsNoop(t *testing.T) {
	f := newFakeReleaseServer(t)
	store := newTestStore(t)
	writeToken(t, store, validToken)
	log := &Logger{}
	agent := &Agent{
		Store:    store,
		Client:   NewClient(store, log, "PC"),
		Log:      log,
		Hostname: "PC",
		// SwapAndRestart nil = update inerte.
	}
	cfg := Config{ServerURL: f.server.URL, IntervalSeconds: 3600}

	agent.SelfUpdate(cfg)

	if f.manifestCalls != 0 {
		t.Errorf("sans primitive de swap, aucun appel réseau d'update, got %d", f.manifestCalls)
	}
}

// ── Report d'échec : item agent_update présent dans le rapport du cycle ───────

func TestUpdateFailureSurfacesAsReportItem(t *testing.T) {
	f := newFakeReleaseServer(t)
	f.hash = strings.Repeat("b", 64) // hash KO → échec rapporté
	stubs := &updateStubs{}
	agent, _, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)
	if agent.pendingUpdateError == "" {
		t.Fatal("pré-condition : un échec doit être en attente")
	}

	items := agent.drainUpdateReportItems()
	if len(items) != 1 {
		t.Fatalf("un item agent_update attendu, got %d", len(items))
	}
	if items[0].Type != "agent_update" || items[0].Status != "error" {
		t.Errorf("item {agent_update, error} attendu, got %+v", items[0])
	}
	if items[0].Detail == "" {
		t.Error("detail de l'échec attendu")
	}
	// Drainé une fois : un échec se rapporte UNE fois.
	if more := agent.drainUpdateReportItems(); len(more) != 0 {
		t.Errorf("l'item d'échec est vidé après drain, got %d", len(more))
	}
}

// ── Version dans le rapport (AC4, contract.go déjà en place) ──────────────────

func TestReportCarriesAgentVersion(t *testing.T) {
	raw, err := BuildReport("PC01", "uuid", nil, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	var payload map[string]any
	if err := json.Unmarshal(raw, &payload); err != nil {
		t.Fatal(err)
	}
	if payload["agent_version"] != Version {
		t.Errorf("agent_version = shared.Version attendu (%q), got %v", Version, payload["agent_version"])
	}
}

// ── Un seul download par cycle (pas de boucle) ───────────────────────────────

func TestSelfUpdateSingleDownloadPerCall(t *testing.T) {
	f := newFakeReleaseServer(t)
	f.hash = strings.Repeat("c", 64) // hash KO : l'update échoue à chaque appel
	stubs := &updateStubs{}
	agent, _, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)
	if f.binaryCalls != 1 {
		t.Fatalf("un seul download par appel attendu, got %d", f.binaryCalls)
	}
	// Un second appel (= cycle suivant) retente — mais toujours UN download par
	// cycle, jamais de boucle intra-cycle.
	agent.SelfUpdate(cfg)
	if f.binaryCalls != 2 {
		t.Errorf("retry au cycle suivant (1 download/cycle), got %d", f.binaryCalls)
	}
}

// ── Token/cache survivent au swap (simulé) ───────────────────────────────────

func TestTokenSurvivesUpdate(t *testing.T) {
	f := newFakeReleaseServer(t)
	stubs := &updateStubs{}
	agent, store, cfg := newUpdateAgent(t, f, stubs)

	agent.SelfUpdate(cfg)

	// Le swap ne touche QUE agent.exe (Program Files) ; le token vit sous
	// ProgramData → relisible sans ré-enrôlement après l'update.
	tok, err := store.ReadToken()
	if err != nil || tok != validToken {
		t.Errorf("token relisible après swap attendu (hors périmètre du swap), got %q err=%v", tok, err)
	}
}

// ── url manifest verbatim : filename extrait, jamais reconstruit ──────────────

func TestReleaseFilenameFromURL(t *testing.T) {
	cases := []struct {
		url     string
		want    string
		wantErr bool
	}{
		{"https://se5.example.org/api/v1/agent/releases/sambaedu-agent-2.2.1.exe", "sambaedu-agent-2.2.1.exe", false},
		{"https://se5.example.org/api/v1/agent/releases/sambaedu-agent-2.2.1%2Brc1.exe", "sambaedu-agent-2.2.1+rc1.exe", false},
		{"https://se5.example.org/api/v1/agent/releases/evil%2F..%2F..%2Fwin.exe", "", true},
		{"https://se5.example.org/api/v1/agent/releases/notmatching.exe", "", true},
		{"://bad-url", "", true},
	}
	for _, c := range cases {
		got, err := releaseFilenameFromURL(c.url)
		if c.wantErr {
			if err == nil {
				t.Errorf("%s : erreur attendue, got %q", c.url, got)
			}

			continue
		}
		if err != nil || got != c.want {
			t.Errorf("%s : got %q err=%v, want %q", c.url, got, err, c.want)
		}
	}
}

// ── #5 : binaire déjà stagé et valide → AUCUN download HTTP ──────────────────

func TestSelfUpdateSkipsDownloadWhenStagedValid(t *testing.T) {
	f := newFakeReleaseServer(t)
	stubs := &updateStubs{}
	agent, store, cfg := newUpdateAgent(t, f, stubs)

	// Pré-stager le binaire cible avec le BON hash (== celui annoncé par le
	// manifest, défaut = SHA-256 de binaryBody). Simule un cycle précédent ayant
	// stagé+vérifié-hash mais échoué ensuite (signature/swap/réseau).
	if err := store.EnsureUpdateDir(func(string) error { return nil }); err != nil {
		t.Fatal(err)
	}
	if err := WriteFileAtomic(store.UpdateStagePath(fakeReleaseFilename), f.binaryBody); err != nil {
		t.Fatal(err)
	}

	agent.SelfUpdate(cfg)

	// Le manifest est bien consulté (pour comparer la version), mais AUCUN
	// download du binaire : le court-circuit content-addressed a fait son office.
	if f.manifestCalls != 1 {
		t.Errorf("1 GET manifest attendu, got %d", f.manifestCalls)
	}
	if f.binaryCalls != 0 {
		t.Errorf("AUCUN download attendu quand le binaire stagé est déjà valide, got %d", f.binaryCalls)
	}
	// Mais la suite procède : porte signature PUIS swap.
	if stubs.verifyCalls != 1 {
		t.Errorf("signature vérifiée une fois attendu (même sans download), got %d", stubs.verifyCalls)
	}
	if stubs.swapCalls != 1 {
		t.Errorf("swap appelé une fois attendu (même sans download), got %d", stubs.swapCalls)
	}
	if agent.pendingUpdateError != "" {
		t.Errorf("aucun échec attendu sur staged valide, got %q", agent.pendingUpdateError)
	}
}

// ── #5 (bis) : un staging au mauvais hash NE court-circuite PAS le download ───

func TestSelfUpdateStaledStagedWrongHashStillDownloads(t *testing.T) {
	f := newFakeReleaseServer(t)
	stubs := &updateStubs{}
	agent, store, cfg := newUpdateAgent(t, f, stubs)

	// Un fichier stagé d'un contenu DIVERGENT (hash != manifest) : le
	// court-circuit ne doit pas s'activer, le download nominal a lieu et écrase
	// le staging par le bon binaire.
	if err := store.EnsureUpdateDir(func(string) error { return nil }); err != nil {
		t.Fatal(err)
	}
	if err := WriteFileAtomic(store.UpdateStagePath(fakeReleaseFilename), []byte("ancien-binaire-obsolete")); err != nil {
		t.Fatal(err)
	}

	agent.SelfUpdate(cfg)

	if f.binaryCalls != 1 {
		t.Errorf("download attendu quand le staging a un hash divergent, got %d", f.binaryCalls)
	}
	if stubs.swapCalls != 1 {
		t.Errorf("swap attendu après re-download, got %d", stubs.swapCalls)
	}
}

// ── #2 : fail-closed — swap possible mais VerifyAuthenticode non câblée ───────

func TestSelfUpdateFailsClosedWhenVerifyNotWired(t *testing.T) {
	f := newFakeReleaseServer(t)
	stubs := &updateStubs{}
	agent, store, cfg := newUpdateAgent(t, f, stubs)
	// SwapAndRestart est câblée (plateforme « Windows »), mais la vérif de
	// signature ne l'est PAS : anomalie de configuration, PAS une plateforme sans
	// Authenticode. On NE doit jamais swapper sans la porte signature.
	swapCalls := 0
	agent.SwapAndRestart = func(stagedPath, version, expectedHash string) error {
		swapCalls++

		return nil
	}
	agent.VerifyAuthenticode = nil

	agent.SelfUpdate(cfg)

	if swapCalls != 0 {
		t.Errorf("AUCUN swap attendu sans VerifyAuthenticode câblée (fail-closed), got %d", swapCalls)
	}
	if agent.pendingUpdateError == "" || !strings.Contains(agent.pendingUpdateError, "VerifyAuthenticode") {
		t.Errorf("échec de configuration rapporté attendu, got %q", agent.pendingUpdateError)
	}
	// Le binaire a bien été stagé (hash OK) mais jamais swappé.
	if !stagedExists(store) {
		t.Error("binaire stagé écrit (hash OK) attendu avant la porte signature")
	}
}

// ── #4 : truncateDetail tronque sur frontière de rune (UTF-8 valide) ─────────

func TestTruncateDetailKeepsUTF8Valid(t *testing.T) {
	// Detail entièrement composé de « é » (2 octets chacun) plus long que la
	// limite : une troncature à l'octet couperait une séquence UTF-8.
	detail := strings.Repeat("é", 100)
	result := truncateDetail(detail, 40)

	if !utf8.ValidString(result) {
		t.Errorf("résultat tronqué doit rester de l'UTF-8 valide, got %q", result)
	}
	// 40 runes + le « … » de troncature.
	if got := utf8.RuneCountInString(result); got != 41 {
		t.Errorf("40 runes conservées + … attendu (41 runes), got %d", got)
	}

	// Sous la limite : inchangé.
	short := "déjà court"
	if got := truncateDetail(short, 40); got != short {
		t.Errorf("string sous la limite inchangée attendue, got %q", got)
	}
}

// ── parse manifest : rejet des manifests incomplets/malformés ────────────────

func TestParseReleaseManifestRejectsIncomplete(t *testing.T) {
	cases := []string{
		`{"success":true,"version":"","hash":"` + strings.Repeat("a", 64) + `","url":"http://x/y"}`,
		`{"success":true,"version":"2.2.1","hash":"","url":"http://x/y"}`,
		`{"success":true,"version":"2.2.1","hash":"` + strings.Repeat("a", 64) + `","url":""}`,
		`{"success":true,"version":"2.2.1","hash":"tooshort","url":"http://x/y"}`,
		// success=false ou absent (contrat 25.1) : jamais traité comme cible.
		`{"success":false,"version":"2.2.1","hash":"` + strings.Repeat("a", 64) + `","url":"http://x/y"}`,
		`{"version":"2.2.1","hash":"` + strings.Repeat("a", 64) + `","url":"http://x/y"}`,
		`not json`,
	}
	for _, raw := range cases {
		if _, err := parseReleaseManifest([]byte(raw)); err == nil {
			t.Errorf("manifest rejeté attendu : %s", raw)
		}
	}

	// Le golden 25.1 doit parser.
	golden, err := os.ReadFile("../../tests/Fixtures/Agent/release-manifest.v1.json")
	if err != nil {
		t.Fatal(err)
	}
	m, err := parseReleaseManifest(golden)
	if err != nil {
		t.Fatalf("golden release-manifest.v1.json doit parser : %v", err)
	}
	if m.Version == "" || m.Hash == "" || m.URL == "" {
		t.Errorf("golden parsé incomplet : %+v", m)
	}
}

// ── Store : répertoire de staging update\ (ACL SYSTEM, idempotent) ───────────

func TestEnsureUpdateDirACLAndIdempotent(t *testing.T) {
	store := newTestStore(t)
	calls := 0
	acl := func(path string) error { calls++; return nil }

	if err := store.EnsureUpdateDir(acl); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(store.UpdateDir()); err != nil {
		t.Fatalf("répertoire update\\ créé attendu : %v", err)
	}
	if calls != 1 {
		t.Errorf("ACL posée une fois à la création, got %d", calls)
	}
	// Idempotent : un second appel ne re-crée ni ne ré-ACL.
	if err := store.EnsureUpdateDir(acl); err != nil {
		t.Fatal(err)
	}
	if calls != 1 {
		t.Errorf("pas de ré-ACL sur répertoire existant, got %d", calls)
	}

	// Le staging est sous la racine agent, hors assets\ (pas de Users:R) et
	// hors Program Files (token/cache intouchés par le swap).
	want := store.root() + "/update"
	if store.UpdateDir() != want {
		t.Errorf("chemin update : got %q, want %q", store.UpdateDir(), want)
	}
}

// ── Intégration boucle : un échec d'update rejoint le POST /report ───────────

func TestRunCycleSurfacesUpdateFailureInReport(t *testing.T) {
	// Serveur d'auto-update qui sert un binaire au hash divergent → échec
	// d'update → item agent_update dans le rapport. On câble state/report +
	// release sur le MÊME serveur.
	fr := newFakeReleaseServer(t)
	fr.hash = strings.Repeat("d", 64) // hash KO

	// Monter aussi /state (golden) et /report sur le même mux n'est pas trivial
	// (le mux est déjà figé) : on teste l'agrégation au niveau BuildReport en
	// posant directement l'échec, puis on vérifie qu'un cycle complet l'émet.
	f := newFakeServer(t)
	agent, store, cfg := newTestAgent(t, f)
	_ = store
	agent.pendingUpdateError = "swap/restart de l'agent en échec (agent en place préservé) : test"

	if outcome := agent.RunCycle(cfg); outcome != OutcomeOK {
		t.Fatalf("OutcomeOK attendu, got %v", outcome)
	}

	var payload map[string]any
	if err := json.Unmarshal(f.lastReport, &payload); err != nil {
		t.Fatal(err)
	}
	items, _ := payload["items"].([]any)
	found := false
	for _, it := range items {
		m, _ := it.(map[string]any)
		if m["type"] == "agent_update" && m["status"] == "error" {
			found = true
		}
	}
	if !found {
		t.Errorf("item agent_update/error attendu dans le rapport du cycle, got %v", payload["items"])
	}
	// Vidé : un second cycle (sans nouvel échec) ne le re-rapporte pas.
	if agent.pendingUpdateError != "" {
		t.Error("pendingUpdateError vidé après le rapport attendu")
	}
}
