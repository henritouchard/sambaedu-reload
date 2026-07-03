package shared

import (
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

// newShutdownAgent : Agent minimal branché sur un Store peuplé (config vers
// le serveur de test + token) — NotifyShutdown relit tout sur disque, comme
// au vrai shutdown (la boucle Run n'a peut-être jamais tourné).
func newShutdownAgent(t *testing.T, serverURL string) (*Agent, *Store) {
	t.Helper()
	store := newTestStore(t)
	if err := store.WriteConfig(Config{ServerURL: serverURL}); err != nil {
		t.Fatal(err)
	}

	agent := NewAgentForTest(Agent{
		Store:    store,
		Client:   NewClient(store, &Logger{}, "SALLE101-PC03"),
		Log:      &Logger{},
		Hostname: "SALLE101-PC03",
	})

	return agent, store
}

func TestNotifyShutdownPostsAuthenticated(t *testing.T) {
	var seen recordedRequest
	var calls int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&calls, 1)
		seen = recordedRequest{
			Method:   r.Method,
			Bearer:   bearerOf(r),
			Hostname: r.Header.Get("X-Agent-Hostname"),
		}
		if r.URL.Path != "/api/v1/agent/shutdown" {
			t.Errorf("path = %s, attendu /api/v1/agent/shutdown", r.URL.Path)
		}
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)

	agent, store := newShutdownAgent(t, server.URL)
	writeToken(t, store, validToken)

	agent.NotifyShutdown(3 * time.Second)

	if calls != 1 {
		t.Fatalf("appels serveur = %d, attendu 1", calls)
	}
	if seen.Method != http.MethodPost {
		t.Errorf("méthode = %s, attendu POST", seen.Method)
	}
	if seen.Bearer != validToken {
		t.Errorf("bearer = %q, attendu le token disque", seen.Bearer)
	}
	if seen.Hostname != "SALLE101-PC03" {
		t.Errorf("X-Agent-Hostname = %q", seen.Hostname)
	}
}

func TestNotifyShutdownSkipsWhenNotEnrolled(t *testing.T) {
	var calls int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		atomic.AddInt32(&calls, 1)
		w.WriteHeader(http.StatusNoContent)
	}))
	t.Cleanup(server.Close)

	agent, _ := newShutdownAgent(t, server.URL)
	// Pas de token écrit : poste jamais enrôlé.

	agent.NotifyShutdown(3 * time.Second)

	if calls != 0 {
		t.Fatalf("appels serveur = %d, attendu 0 (poste non enrôlé)", calls)
	}
}

func TestNotifyShutdownSwallowsServerError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	t.Cleanup(server.Close)

	agent, store := newShutdownAgent(t, server.URL)
	writeToken(t, store, validToken)

	// Best-effort : aucune panique, aucun retour d'erreur — juste un log.
	agent.NotifyShutdown(3 * time.Second)
}

func TestNotifyShutdownSwallowsNetworkError(t *testing.T) {
	agent, store := newShutdownAgent(t, "http://127.0.0.1:1")
	writeToken(t, store, validToken)

	agent.NotifyShutdown(500 * time.Millisecond)
}
