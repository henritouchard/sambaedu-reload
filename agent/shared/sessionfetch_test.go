package shared

import (
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"strings"
	"sync"
	"testing"
)

// fakeSessionServer : serveur SE5 minimal pour le chemin `?user=` + assets.
type fakeSessionServer struct {
	mu sync.Mutex

	userStateCode int
	userStateBody string
	userStateEtag string

	userCalls    []string // logins vus (décodés)
	userEtagSeen map[string]string

	assetBody  map[string][]byte
	assetCalls []string

	// Story 27.7 : icônes raccourci servies en STATIQUE (GET simple sans token).
	iconBody  map[string][]byte
	iconCalls []string

	server *httptest.Server
}

func newFakeSessionServer(t *testing.T) *fakeSessionServer {
	t.Helper()
	f := &fakeSessionServer{
		userStateCode: 200,
		userStateBody: string(mustReadGolden(t)),
		userStateEtag: `"etag-user-1"`,
		userEtagSeen:  map[string]string{},
		assetBody:     map[string][]byte{},
		iconBody:      map[string][]byte{},
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/api/v1/agent/state", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		user := r.URL.Query().Get("user")
		if user == "" {
			// Le contexte machine n'est pas l'objet de ces tests.
			w.WriteHeader(200)
			_, _ = w.Write(mustReadGolden(t))

			return
		}
		f.userCalls = append(f.userCalls, user)
		f.userEtagSeen[user] = r.Header.Get("If-None-Match")
		if f.userStateCode == 200 && f.userEtagSeen[user] == f.userStateEtag {
			w.WriteHeader(304)

			return
		}
		if f.userStateCode == 200 {
			w.Header().Set("ETag", f.userStateEtag)
			w.WriteHeader(200)
			_, _ = w.Write([]byte(f.userStateBody))

			return
		}
		w.WriteHeader(f.userStateCode)
	})
	// Story wallpaper-static : fonds d'écran servis en STATIQUE par Apache
	// (Alias /assets/wallpaper), GET simple SANS token — le handler répond même
	// sans Authorization, comme l'Alias shortcut-icons.
	mux.HandleFunc("/assets/wallpaper/", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		filename := strings.TrimPrefix(r.URL.Path, "/assets/wallpaper/")
		f.assetCalls = append(f.assetCalls, filename)
		body, ok := f.assetBody[filename]
		if !ok {
			w.WriteHeader(404)

			return
		}
		w.WriteHeader(200)
		_, _ = w.Write(body)
	})
	// Story 27.7 : Alias statique des icônes raccourci — GET simple (PAS de
	// vérif de token : le handler répond même sans Authorization, c'est l'objet
	// du test « transport sans token »).
	mux.HandleFunc("/assets/shortcut-icons/", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		filename := strings.TrimPrefix(r.URL.Path, "/assets/shortcut-icons/")
		f.iconCalls = append(f.iconCalls, filename)
		body, ok := f.iconBody[filename]
		if !ok {
			w.WriteHeader(404)

			return
		}
		w.WriteHeader(200)
		_, _ = w.Write(body)
	})

	f.server = httptest.NewServer(mux)
	t.Cleanup(f.server.Close)

	return f
}

func newSessionAgent(t *testing.T, f *fakeSessionServer, sessions []Session) (*Agent, *Store, Config) {
	t.Helper()
	store := newTestStore(t)
	writeToken(t, store, validToken)
	log := &Logger{}
	agent := &Agent{
		Store:    store,
		Client:   NewClient(store, log, "SALLE101-PC03"),
		Log:      log,
		Hostname: "SALLE101-PC03",
		Sessions: func() ([]Session, error) { return sessions, nil },
	}

	return agent, store, Config{ServerURL: f.server.URL, IntervalSeconds: 3600}
}

func TestSessionFetch200WritesPerSidCacheAndDropDir(t *testing.T) {
	f := newFakeSessionServer(t)
	agent, store, cfg := newSessionAgent(t, f, []Session{{Login: "jdoe", SID: testSID}})

	agent.fetchSessionStates(cfg)

	// Cache per-SID : enveloppe BRUTE + ETag verbatim DU contexte.
	raw, err := store.ReadSessionStateCache(testSID)
	if err != nil || string(raw) != f.userStateBody {
		t.Errorf("cache de session brut : %v", err)
	}
	if got := store.ReadSessionEtag(testSID); got != f.userStateEtag {
		t.Errorf("ETag du contexte verbatim : got %q", got)
	}
	// Répertoire de drop garanti AVANT toute passe compagnon.
	if _, err := os.Stat(store.SessionReportDir(testSID)); err != nil {
		t.Errorf("répertoire de drop attendu : %v", err)
	}
	if len(f.userCalls) != 1 || f.userCalls[0] != "jdoe" {
		t.Errorf("login court attendu : %v", f.userCalls)
	}
}

func TestSessionFetch304PreservesCacheAndSendsContextEtag(t *testing.T) {
	f := newFakeSessionServer(t)
	agent, store, cfg := newSessionAgent(t, f, []Session{{Login: "jdoe", SID: testSID}})

	agent.fetchSessionStates(cfg) // 200 → cache
	agent.fetchSessionStates(cfg) // ETag du contexte → 304

	if got := f.userEtagSeen["jdoe"]; got != f.userStateEtag {
		t.Errorf("If-None-Match DU contexte attendu au 2e fetch : %q", got)
	}
	if raw, _ := store.ReadSessionStateCache(testSID); string(raw) != f.userStateBody {
		t.Error("cache intact sur 304")
	}
}

func TestSessionFetchEtagPerContextNotMachine(t *testing.T) {
	// L'ETag machine ne fuit JAMAIS vers un fetch ?user= (piège n° 2).
	f := newFakeSessionServer(t)
	agent, store, cfg := newSessionAgent(t, f, []Session{{Login: "jdoe", SID: testSID}})

	if err := store.WriteStateCache([]byte(`{"schema":"se5.desired-state/v1"}`), `"etag-machine"`); err != nil {
		t.Fatal(err)
	}
	agent.fetchSessionStates(cfg)

	if got := f.userEtagSeen["jdoe"]; got != "" {
		t.Errorf("premier fetch du contexte : If-None-Match vide attendu, got %q", got)
	}
}

func TestSessionFetchEmptyLoginNeverSent(t *testing.T) {
	f := newFakeSessionServer(t)
	agent, _, cfg := newSessionAgent(t, f, []Session{
		{Login: "", SID: testSID}, // garde : ?user= vide ne part JAMAIS
		{Login: "jdoe", SID: ""},  // SID vide : pas de répertoire possible
	})

	agent.fetchSessionStates(cfg)
	if len(f.userCalls) != 0 {
		t.Errorf("aucun fetch attendu : %v", f.userCalls)
	}
}

func TestSessionFetchLoginUrlEscaped(t *testing.T) {
	f := newFakeSessionServer(t)
	agent, _, cfg := newSessionAgent(t, f, []Session{{Login: "j doe&x", SID: testSID}})

	agent.fetchSessionStates(cfg)
	// Le mux décode : si l'échappement était mauvais, le login serait tronqué.
	if len(f.userCalls) != 1 || f.userCalls[0] != "j doe&x" {
		t.Errorf("login échappé attendu : %v", f.userCalls)
	}
	if _, err := url.ParseQuery("user=" + url.QueryEscape("j doe&x")); err != nil {
		t.Fatal(err)
	}
}

func TestSessionFetchQuarantine403StopsFetches(t *testing.T) {
	f := newFakeSessionServer(t)
	other := "S-1-5-21-1111111111-2222222222-3333333333-1002"
	agent, store, cfg := newSessionAgent(t, f, []Session{
		{Login: "jdoe", SID: testSID},
		{Login: "asmith", SID: other},
	})
	f.userStateCode = 403

	agent.fetchSessionStates(cfg)

	if !agent.Quarantined() {
		t.Fatal("quarantaine attendue sur 403 de fetch de session")
	}
	if len(f.userCalls) != 1 {
		t.Errorf("arrêt des fetchs après le 403 : %v", f.userCalls)
	}
	if _, err := store.ReadSessionStateCache(testSID); err == nil {
		t.Error("aucun cache écrit sur 403")
	}

	// Quarantaine active → plus AUCUN fetch de session.
	agent.fetchSessionStates(cfg)
	if len(f.userCalls) != 1 {
		t.Errorf("fetch sauté en quarantaine : %v", f.userCalls)
	}
}

func TestSessionFetchUnknownMajorPreservesContextCache(t *testing.T) {
	f := newFakeSessionServer(t)
	agent, store, cfg := newSessionAgent(t, f, []Session{{Login: "jdoe", SID: testSID}})

	agent.fetchSessionStates(cfg) // cache sain

	f.userStateBody = `{"schema":"se5.desired-state/v2","machine":[],"session":[],"machine_user":[]}`
	f.userStateEtag = `"etag-v2"`
	agent.fetchSessionStates(cfg)

	raw, _ := store.ReadSessionStateCache(testSID)
	if strings.Contains(string(raw), "v2") {
		t.Error("le cache du contexte ne doit PAS être écrasé par un état v2")
	}
	if store.ReadSessionEtag(testSID) == `"etag-v2"` {
		t.Error("l'ETag du contexte ne doit PAS être écrasé par un état v2")
	}
}

func TestSessionFetchUnknownUserMachineOnlyIsQuiet(t *testing.T) {
	// Login inconnu/compte local : 200 machine-only — traité comme tout 200,
	// aucun bruit (le test serveur 24.3 #9 fige le comportement serveur).
	f := newFakeSessionServer(t)
	f.userStateBody = `{"schema":"se5.desired-state/v1","generated_at":"2026-06-12T08:00:00+00:00","ttl_seconds":3600,"machine":[],"session":[],"machine_user":[]}`
	agent, store, cfg := newSessionAgent(t, f, []Session{{Login: "localadmin", SID: testSID}})

	agent.fetchSessionStates(cfg)

	raw, err := store.ReadSessionStateCache(testSID)
	if err != nil || !strings.Contains(string(raw), "machine") {
		t.Errorf("enveloppe machine-only cachée normalement : %v", err)
	}
}

func TestSessionFetchNilEnumeratorIsNoop(t *testing.T) {
	f := newFakeSessionServer(t)
	agent, _, cfg := newSessionAgent(t, f, nil)
	agent.Sessions = nil

	agent.fetchSessionStates(cfg) // ne panique pas, n'appelle rien
	if len(f.userCalls) != 0 {
		t.Errorf("aucun appel attendu : %v", f.userCalls)
	}
}

func TestRunCycleRefreshesSessionsAndCollectsDrops(t *testing.T) {
	// Le cycle du service rafraîchit les caches de session IN-PROCESS après
	// la portée machine, et le rapport embarque les items des drops.
	f := newFakeServer(t)
	store := newTestStore(t)
	writeToken(t, store, validToken)
	log := &Logger{}
	sessionCalls := 0
	agent := &Agent{
		Store:    store,
		Client:   NewClient(store, log, "SALLE101-PC03"),
		Log:      log,
		Hostname: "SALLE101-PC03",
		UUID:     func() string { return "" },
		Sessions: func() ([]Session, error) { sessionCalls++; return nil, nil },
	}
	cfg := Config{ServerURL: f.server.URL, IntervalSeconds: 3600}

	// Un drop valide préexistant (déposé par un compagnon).
	if err := store.EnsureSessionReportDir(testSID, nil); err != nil {
		t.Fatal(err)
	}
	drop, _ := BuildSessionReportDrop("2026-06-12T10:00:00Z", []ReportItem{
		{Type: "wallpaper", Status: "drift", Hash: strings.Repeat("a", 64)},
	})
	if err := WriteFileAtomic(store.SessionReportPath(testSID), drop); err != nil {
		t.Fatal(err)
	}

	if outcome := agent.RunCycle(cfg); outcome != OutcomeOK {
		t.Fatalf("OutcomeOK attendu, got %v", outcome)
	}
	if sessionCalls != 1 {
		t.Errorf("énumération des sessions au cycle attendue, got %d", sessionCalls)
	}
	if !strings.Contains(string(f.lastReport), `"type":"wallpaper","status":"drift"`) {
		t.Errorf("items réels des drops attendus au rapport : %s", f.lastReport)
	}
}
