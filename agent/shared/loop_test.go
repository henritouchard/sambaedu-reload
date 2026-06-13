package shared

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strconv"
	"sync"
	"testing"
	"time"
)

// fakeServer : serveur SE5 minimal pour la boucle (state + report).
type fakeServer struct {
	mu sync.Mutex

	stateCode  int
	stateBody  string
	stateEtag  string
	reportCode int

	// releaseCode : code HTTP du GET /api/v1/agent/release (canal release, Story
	// 25.2). 0 = handler non monté → 404 par défaut du mux (no_release, l'agent
	// ne tente rien). >0 = code servi (ex. 403 pour tester M4 dans runCycle).
	releaseCode int

	stateCalls   int
	reportCalls  int
	releaseCalls int
	lastReport   []byte
	lastEtagSeen string

	server *httptest.Server
}

func newFakeServer(t *testing.T) *fakeServer {
	t.Helper()
	f := &fakeServer{stateCode: 200, stateEtag: `"` + frozenStateHash + `"`, reportCode: 200}
	f.stateBody = string(mustReadGolden(t))

	mux := http.NewServeMux()
	mux.HandleFunc("/api/v1/agent/release", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.releaseCalls++
		if f.releaseCode == 0 {
			// Pas de release configurée : 404 no_release (rien à faire).
			w.WriteHeader(404)

			return
		}
		w.WriteHeader(f.releaseCode)
	})
	mux.HandleFunc("/api/v1/agent/state", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.stateCalls++
		f.lastEtagSeen = r.Header.Get("If-None-Match")
		if f.stateCode == 200 && f.lastEtagSeen == f.stateEtag {
			w.WriteHeader(304)

			return
		}
		if f.stateCode == 200 {
			w.Header().Set("ETag", f.stateEtag)
			w.WriteHeader(200)
			_, _ = w.Write([]byte(f.stateBody))

			return
		}
		w.WriteHeader(f.stateCode)
	})
	mux.HandleFunc("/api/v1/agent/report", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.reportCalls++
		body, _ := io.ReadAll(r.Body)
		f.lastReport = body
		w.WriteHeader(f.reportCode)
	})

	f.server = httptest.NewServer(mux)
	t.Cleanup(f.server.Close)

	return f
}

func mustReadGolden(t *testing.T) []byte {
	t.Helper()
	raw, err := os.ReadFile("../../tests/Fixtures/Agent/state.v1.json")
	if err != nil {
		t.Fatal(err)
	}

	return raw
}

func newTestAgent(t *testing.T, f *fakeServer) (*Agent, *Store, Config) {
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
	}
	cfg := Config{ServerURL: f.server.URL, IntervalSeconds: 3600}

	return agent, store, cfg
}

func TestCycleNominal200ThenReport(t *testing.T) {
	f := newFakeServer(t)
	agent, store, cfg := newTestAgent(t, f)

	if outcome := agent.RunCycle(cfg); outcome != OutcomeOK {
		t.Fatalf("OutcomeOK attendu, got %v", outcome)
	}

	// Cache persisté : enveloppe BRUTE + ETag VERBATIM (guillemets inclus).
	state, err := store.ReadStateCache()
	if err != nil || string(state) != f.stateBody {
		t.Errorf("cache état brut attendu : %v", err)
	}
	if got := store.ReadEtag(); got != f.stateEtag {
		t.Errorf("ETag verbatim : got %q, want %q", got, f.stateEtag)
	}

	// applied-state.json créé vide (infra 24.6).
	raw, _ := os.ReadFile(store.AppliedStatePath())
	if string(raw) != "{}" {
		t.Errorf("applied-state : %q", raw)
	}

	// Rapport parti : schema, hostname COURT, uuid verbatim, items: [].
	if f.reportCalls != 1 {
		t.Fatalf("1 rapport attendu, got %d", f.reportCalls)
	}
	var report map[string]any
	if err := json.Unmarshal(f.lastReport, &report); err != nil {
		t.Fatalf("rapport illisible : %v (%q)", err, f.lastReport)
	}
	ws := report["workstation"].(map[string]any)
	if ws["hostname"] != "SALLE101-PC03" {
		t.Errorf("hostname court : %v", ws["hostname"])
	}
	if items, ok := report["items"].([]any); !ok || len(items) != 0 {
		t.Errorf("items: [] attendu, got %v", report["items"])
	}
	if report["agent_version"] != Version {
		t.Errorf("agent_version : %v", report["agent_version"])
	}
}

func TestCycle304ReusesCacheAndStillReports(t *testing.T) {
	f := newFakeServer(t)
	agent, store, cfg := newTestAgent(t, f)

	// 1er cycle : 200 + cache. 2e cycle : le serveur voit l'ETag → 304.
	if agent.RunCycle(cfg) != OutcomeOK {
		t.Fatal("cycle 1")
	}
	if agent.RunCycle(cfg) != OutcomeOK {
		t.Fatal("cycle 2")
	}

	if f.lastEtagSeen != f.stateEtag {
		t.Errorf("If-None-Match verbatim au 2e cycle : got %q", f.lastEtagSeen)
	}
	// Le rapport part AUSSI sur 304 (signal de vie).
	if f.reportCalls != 2 {
		t.Errorf("2 rapports attendus (le rapport part aussi sur 304), got %d", f.reportCalls)
	}
	// Cache intact.
	if state, _ := store.ReadStateCache(); string(state) != f.stateBody {
		t.Error("cache état modifié sur 304")
	}
}

func TestCycleQuarantineCheckInsLightThenAutoLift(t *testing.T) {
	f := newFakeServer(t)
	agent, _, cfg := newTestAgent(t, f)

	// 403 → quarantaine : plus de POST /report, mais GET /state continue.
	f.stateCode = 403
	if outcome := agent.RunCycle(cfg); outcome != OutcomeOK {
		t.Fatalf("403 = check-ins légers à cadence NORMALE (pas de backoff), got %v", outcome)
	}
	if !agent.Quarantined() {
		t.Fatal("flag quarantaine attendu")
	}
	if f.reportCalls != 0 {
		t.Errorf("aucun rapport en quarantaine, got %d", f.reportCalls)
	}

	// Cycle suivant : GET continue (check-in léger).
	if agent.RunCycle(cfg) != OutcomeOK {
		t.Fatal("check-in léger")
	}
	if f.stateCalls != 2 {
		t.Errorf("GET /state doit continuer en quarantaine : %d appels", f.stateCalls)
	}
	if f.reportCalls != 0 {
		t.Errorf("toujours aucun rapport, got %d", f.reportCalls)
	}

	// Levée AUTOMATIQUE au premier 200/304.
	f.stateCode = 200
	if agent.RunCycle(cfg) != OutcomeOK {
		t.Fatal("levée")
	}
	if agent.Quarantined() {
		t.Error("quarantaine levée attendue au premier 200")
	}
	if f.reportCalls != 1 {
		t.Errorf("reprise du rapport attendue, got %d", f.reportCalls)
	}
}

func TestCycleQuarantineOnReport403(t *testing.T) {
	f := newFakeServer(t)
	agent, _, cfg := newTestAgent(t, f)

	f.reportCode = 403
	if outcome := agent.RunCycle(cfg); outcome != OutcomeOK {
		t.Fatalf("403 sur POST : OutcomeOK attendu, got %v", outcome)
	}
	if !agent.Quarantined() {
		t.Error("quarantaine attendue après 403 sur POST /report")
	}
}

func TestCycleUnknownMajorPreservesCacheAndKeepsCheckingIn(t *testing.T) {
	f := newFakeServer(t)
	agent, store, cfg := newTestAgent(t, f)

	// Cache initial sain.
	if agent.RunCycle(cfg) != OutcomeOK {
		t.Fatal("cycle 1")
	}
	cachedBefore, _ := store.ReadStateCache()
	etagBefore := store.ReadEtag()

	// Le serveur passe en v2 (major inconnu) avec un NOUVEL ETag.
	f.stateBody = `{"schema":"se5.desired-state/v2","machine":[],"session":[],"machine_user":[]}`
	f.stateEtag = `"autre-etag"`
	outcome := agent.RunCycle(cfg)

	// Piège n° 10 : log erreur, cache PRÉSERVÉ, check-ins maintenus (le
	// rapport part — signal de vie), cadence normale.
	if outcome != OutcomeOK {
		t.Fatalf("check-ins maintenus à cadence normale, got %v", outcome)
	}
	if cached, _ := store.ReadStateCache(); string(cached) != string(cachedBefore) {
		t.Error("le cache ne doit PAS être écrasé par un état v2")
	}
	if store.ReadEtag() != etagBefore {
		t.Error("l'ETag ne doit PAS être écrasé par un état v2")
	}
	if f.reportCalls != 2 {
		t.Errorf("le rapport doit continuer à partir, got %d", f.reportCalls)
	}
}

func TestCycleBackoffOutcomes(t *testing.T) {
	cases := []struct {
		name  string
		setup func(f *fakeServer)
	}{
		{"state_500", func(f *fakeServer) { f.stateCode = 500 }},
		{"state_429", func(f *fakeServer) { f.stateCode = 429 }},
		{"report_500", func(f *fakeServer) { f.reportCode = 500 }},
		{"report_429", func(f *fakeServer) { f.reportCode = 429 }},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			f := newFakeServer(t)
			agent, _, cfg := newTestAgent(t, f)
			tc.setup(f)

			if outcome := agent.RunCycle(cfg); outcome != OutcomeBackoff {
				t.Errorf("OutcomeBackoff attendu, got %v", outcome)
			}
		})
	}
}

func TestCycleNetworkUnreachableBacksOff(t *testing.T) {
	f := newFakeServer(t)
	agent, _, cfg := newTestAgent(t, f)
	f.server.Close()

	if outcome := agent.RunCycle(cfg); outcome != OutcomeBackoff {
		t.Errorf("serveur injoignable : OutcomeBackoff attendu, got %v", outcome)
	}
}

func TestCycle401Stops(t *testing.T) {
	f := newFakeServer(t)
	agent, _, cfg := newTestAgent(t, f)
	f.stateCode = 401

	if outcome := agent.RunCycle(cfg); outcome != OutcomeStop {
		t.Errorf("401 irrécupérable : OutcomeStop attendu (jamais de re-enrôlement auto), got %v", outcome)
	}
}

func TestCycleMissingTokenBacksOff(t *testing.T) {
	f := newFakeServer(t)
	agent, store, cfg := newTestAgent(t, f)
	if err := os.Remove(store.TokenPath()); err != nil {
		t.Fatal(err)
	}

	if outcome := agent.RunCycle(cfg); outcome != OutcomeBackoff {
		t.Errorf("token absent : backoff (et jamais de crash), got %v", outcome)
	}
}

// --- Backoff & jitter ------------------------------------------------------------

func TestNextBackoffProgression(t *testing.T) {
	interval := 3600 * time.Second
	want := []time.Duration{30, 60, 120, 240, 480, 960, 1920, 3600, 3600}

	current := time.Duration(0)
	for i, w := range want {
		current = NextBackoff(current, interval)
		if current != w*time.Second {
			t.Fatalf("étape %d : got %v, want %v s", i, current, w)
		}
	}
}

func TestJitterBounds(t *testing.T) {
	agent := &Agent{}
	interval := 3600 * time.Second
	max := 360 * time.Second

	seenNonZero := false
	for range 1000 {
		j := agent.Jitter(interval)
		if j < -max || j > max {
			t.Fatalf("jitter hors bornes ±10 %% : %v", j)
		}
		if j != 0 {
			seenNonZero = true
		}
	}
	if !seenNonZero {
		t.Error("jitter toujours nul sur 1000 tirages : suspect")
	}
}

// --- Run (boucle complète) ---------------------------------------------------------

func TestRunStopsOnIrrecoverable401(t *testing.T) {
	f := newFakeServer(t)
	agent, store, _ := newTestAgent(t, f)
	f.stateCode = 401
	if err := store.WriteConfig(Config{ServerURL: f.server.URL, IntervalSeconds: 3600}); err != nil {
		t.Fatal(err)
	}

	done := make(chan struct{})
	go func() {
		agent.Run(context.Background())
		close(done)
	}()

	select {
	case <-done: // la boucle s'arrête seule (ARRÊT + log, AC5)
	case <-time.After(5 * time.Second):
		t.Fatal("Run aurait dû s'arrêter sur 401 irrécupérable")
	}
}

func TestRunStopsOnContextCancel(t *testing.T) {
	f := newFakeServer(t)
	agent, store, _ := newTestAgent(t, f)
	if err := store.WriteConfig(Config{ServerURL: f.server.URL, IntervalSeconds: 3600}); err != nil {
		t.Fatal(err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		agent.Run(ctx)
		close(done)
	}()

	// Laisser le 1er cycle partir puis demander l'arrêt (stop SCM).
	time.Sleep(200 * time.Millisecond)
	cancel()

	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("Run aurait dû sortir sur annulation du contexte")
	}
	if f.reportCalls < 1 {
		t.Errorf("au moins un cycle complet attendu avant l'arrêt, got %d", f.reportCalls)
	}
}

func TestRunSurvivesMissingConfig(t *testing.T) {
	// Config absente : log + retry en backoff — jamais de crash (AC2/AC4).
	f := newFakeServer(t)
	agent, _, _ := newTestAgent(t, f)

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		agent.Run(ctx)
		close(done)
	}()

	time.Sleep(150 * time.Millisecond)
	cancel()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("Run bloqué sans config")
	}
	if f.stateCalls != 0 {
		t.Errorf("aucun appel attendu sans config, got %d", f.stateCalls)
	}
}

func TestReportNotSentWhenStateUnreachable(t *testing.T) {
	// Vérifie l'ordre du cycle : pas de POST si le GET n'a pas eu lieu.
	f := newFakeServer(t)
	agent, _, cfg := newTestAgent(t, f)
	f.stateCode = 500

	_ = agent.RunCycle(cfg)
	if f.reportCalls != 0 {
		t.Errorf("aucun rapport quand GET /state échoue, got %d", f.reportCalls)
	}
}

// ── Cadence pilotée serveur (ttl_seconds, 2.2.0) ─────────────────────────────

// minimalEnvelope : enveloppe v1 valide minimale avec un ttl choisi.
func minimalEnvelope(ttl int) string {
	return `{"schema":"se5.desired-state/v1","generated_at":"2026-06-12T10:00:00Z","ttl_seconds":` +
		strconv.Itoa(ttl) + `,"machine":[],"session":[],"machine_user":[]}`
}

func TestEffectiveIntervalServerTtlGovernsAndClamps(t *testing.T) {
	agent := &Agent{Log: &Logger{}}
	cfg := Config{IntervalSeconds: 3600}

	cases := []struct {
		name string
		ttl  int64
		want time.Duration
	}{
		{"jamais vu — cadence locale", 0, 3600 * time.Second},
		{"nominal", 900, 900 * time.Second},
		{"plancher anti-martèlement", 1, MinServerIntervalSeconds * time.Second},
		{"plafond anti-extinction", 999_999, MaxServerIntervalSeconds * time.Second},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			agent.serverTtl = c.ttl
			if got := agent.EffectiveInterval(cfg); got != c.want {
				t.Errorf("got %v, want %v", got, c.want)
			}
		})
	}
}

func TestCycle200NotesServerTtl(t *testing.T) {
	f := newFakeServer(t)
	agent, _, cfg := newTestAgent(t, f)
	f.stateBody = minimalEnvelope(120)

	if got := agent.EffectiveInterval(cfg); got != 3600*time.Second {
		t.Fatalf("avant le premier 200, cadence locale attendue, got %v", got)
	}
	if outcome := agent.RunCycle(cfg); outcome != OutcomeOK {
		t.Fatalf("OutcomeOK attendu, got %v", outcome)
	}
	if got := agent.EffectiveInterval(cfg); got != 120*time.Second {
		t.Errorf("ttl serveur 120 s attendu après le 200, got %v", got)
	}
}

func TestNoteServerTtlIgnoresZero(t *testing.T) {
	// Une enveloppe SANS ttl (parse → 0) ne fait pas retomber la cadence
	// pilotée déjà apprise.
	agent := &Agent{Log: &Logger{}}
	agent.noteServerTtl(120)
	agent.noteServerTtl(0)
	if agent.serverTtl != 120 {
		t.Errorf("ttl conservé attendu, got %d", agent.serverTtl)
	}
}

func TestPrimeServerTtlFromCache(t *testing.T) {
	// Un service redémarré reprend la cadence serveur depuis le cache (son
	// GET nominal répond 304 : l'enveloppe n'est pas re-livrée).
	f := newFakeServer(t)
	agent, store, cfg := newTestAgent(t, f)
	if err := store.EnsureLayout(); err != nil {
		t.Fatal(err)
	}
	if err := store.WriteStateCache([]byte(minimalEnvelope(300)), `"abc"`); err != nil {
		t.Fatal(err)
	}

	agent.primeServerTtlFromCache()
	if got := agent.EffectiveInterval(cfg); got != 300*time.Second {
		t.Errorf("ttl 300 s amorcé depuis le cache attendu, got %v", got)
	}
}

func TestPrimeServerTtlWithoutCacheIsANoop(t *testing.T) {
	f := newFakeServer(t)
	agent, _, cfg := newTestAgent(t, f)

	agent.primeServerTtlFromCache()
	if got := agent.EffectiveInterval(cfg); got != 3600*time.Second {
		t.Errorf("cadence locale attendue sans cache, got %v", got)
	}
}
