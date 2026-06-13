package shared

import (
	"net/http"
	"net/http/httptest"
	"os"
	"sync"
	"testing"
)

// enrollFakeServer : serveur SE5 minimal couvrant l'enrôlement porte 2 +
// state/report, pour exercer la boucle « token absent » de bout en bout (Story
// 25.4).
type enrollFakeServer struct {
	mu sync.Mutex

	enrollCode int    // code servi par POST /api/v1/agent/enrollment
	enrollBody string // corps JSON servi (token sur 200)

	enrollCalls int
	stateCalls  int

	server *httptest.Server
}

func newEnrollFakeServer(t *testing.T) *enrollFakeServer {
	t.Helper()
	f := &enrollFakeServer{enrollCode: 403, enrollBody: `{"error":"forbidden"}`}

	mux := http.NewServeMux()
	mux.HandleFunc("/api/v1/agent/enrollment", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.enrollCalls++
		w.WriteHeader(f.enrollCode)
		_, _ = w.Write([]byte(f.enrollBody))
	})
	mux.HandleFunc("/api/v1/agent/state", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.stateCalls++
		// État minimal : un 200 sans corps déclencherait un refus de parse,
		// mais on ne teste ici que le fait que la convergence DÉMARRE (GET
		// /state appelé) une fois le token écrit.
		w.WriteHeader(200)
	})
	mux.HandleFunc("/api/v1/agent/report", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
	})

	f.server = httptest.NewServer(mux)
	t.Cleanup(f.server.Close)

	return f
}

func newEnrollAgent(t *testing.T, f *enrollFakeServer) (*Agent, *Store, Config) {
	t.Helper()
	store := newTestStore(t) // PAS de token écrit (poste migré sans token)
	log := &Logger{}
	agent := &Agent{
		Store:    store,
		Client:   NewClient(store, log, "SALLE101-PC03"),
		Log:      log,
		Hostname: "SALLE101-PC03",
		UUID:     func() string { return "F1D2C3B4-A5E6-4789-9ABC-0123456789AB" },
		MAC:      func() string { return "aa:bb:cc:dd:ee:ff" },
	}
	cfg := Config{ServerURL: f.server.URL, IntervalSeconds: 3600}

	return agent, store, cfg
}

// Token absent + 403 → demande postée, cadence normale (OutcomeOK), aucun token
// écrit, JAMAIS de bascule en convergence.
func TestCycleNoTokenPending(t *testing.T) {
	f := newEnrollFakeServer(t)
	f.enrollCode = 403
	agent, store, cfg := newEnrollAgent(t, f)

	if outcome := agent.RunCycle(cfg); outcome != OutcomeOK {
		t.Fatalf("token absent + 403 : OutcomeOK attendu (cadence normale), got %v", outcome)
	}
	if f.enrollCalls != 1 {
		t.Errorf("1 demande d'enrôlement attendue, got %d", f.enrollCalls)
	}
	if store.TokenExists() {
		t.Error("aucun token ne doit être écrit sur un 403")
	}
	if f.stateCalls != 0 {
		t.Error("pas de convergence (GET /state) tant que le poste n'a pas de token")
	}
}

// Token absent + 200 {token} → token écrit, OutcomeOK ; le cycle suivant lit le
// token et bascule en convergence (GET /state appelé).
func TestCycleNoTokenApprovedWritesTokenThenConverges(t *testing.T) {
	f := newEnrollFakeServer(t)
	f.enrollCode = 200
	f.enrollBody = `{"success":true,"token":"` + enrollToken + `"}`
	agent, store, cfg := newEnrollAgent(t, f)

	if outcome := agent.RunCycle(cfg); outcome != OutcomeOK {
		t.Fatalf("token absent + 200 : OutcomeOK attendu, got %v", outcome)
	}
	if !store.TokenExists() {
		t.Fatal("le token approuvé doit être écrit sur disque")
	}
	got, err := store.ReadToken()
	if err != nil || got != enrollToken {
		t.Fatalf("token écrit attendu %q, got %q (err %v)", enrollToken, got, err)
	}
	// Cycle suivant : token présent → convergence (GET /state appelé).
	_ = agent.RunCycle(cfg)
	if f.stateCalls < 1 {
		t.Error("le cycle suivant doit basculer en convergence (GET /state)")
	}
}

// Token absent + 409 → conflit, cadence normale (OutcomeOK), aucun token,
// JAMAIS de ré-enrôlement auto.
func TestCycleNoTokenConflict(t *testing.T) {
	f := newEnrollFakeServer(t)
	f.enrollCode = 409
	agent, store, cfg := newEnrollAgent(t, f)

	if outcome := agent.RunCycle(cfg); outcome != OutcomeOK {
		t.Fatalf("token absent + 409 : OutcomeOK attendu, got %v", outcome)
	}
	if store.TokenExists() {
		t.Error("un 409 ne doit jamais écrire de token")
	}
}

// Token absent + serveur injoignable → backoff (jamais de spin).
func TestCycleNoTokenNetworkErrorBacksOff(t *testing.T) {
	f := newEnrollFakeServer(t)
	agent, _, cfg := newEnrollAgent(t, f)
	// On ferme le serveur pour provoquer une erreur réseau.
	f.server.Close()

	if outcome := agent.RunCycle(cfg); outcome != OutcomeBackoff {
		t.Fatalf("token absent + réseau KO : OutcomeBackoff attendu, got %v", outcome)
	}
}

// Non-régression : un token PRÉSENT mais corrompu reste un échec de cycle
// (backoff), JAMAIS un déclencheur d'auto-enroll (un poste enrôlé ne se
// ré-enrôle jamais auto — FR22).
func TestCycleCorruptTokenDoesNotTriggerEnroll(t *testing.T) {
	f := newEnrollFakeServer(t)
	agent, store, cfg := newEnrollAgent(t, f)
	if err := os.WriteFile(store.TokenPath(), []byte("not-a-valid-token"), 0o600); err != nil {
		t.Fatal(err)
	}

	if outcome := agent.RunCycle(cfg); outcome != OutcomeBackoff {
		t.Fatalf("token corrompu : OutcomeBackoff attendu, got %v", outcome)
	}
	if f.enrollCalls != 0 {
		t.Error("un token présent (même corrompu) ne doit JAMAIS déclencher de demande d'enrôlement")
	}
}
