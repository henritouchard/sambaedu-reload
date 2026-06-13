package shared

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// enrollToken : token d'enrôlement valide servi par le fake serveur (64 hex).
var enrollToken = strings.Repeat("e", 64)

// newEnrollClient : client + serveur httptest dédiés au chemin d'amorçage
// (PostNoAuth, pas de token sur le client).
func newEnrollClient(t *testing.T, handler http.HandlerFunc) (*Client, *httptest.Server) {
	t.Helper()
	server := httptest.NewServer(handler)
	t.Cleanup(server.Close)

	store := newTestStore(t)

	return NewClient(store, &Logger{}, "SALLE101-PC03"), server
}

func TestRequestEnrollmentApprovedReturnsToken(t *testing.T) {
	var seen recordedRequest
	var body string
	client, server := newEnrollClient(t, func(w http.ResponseWriter, r *http.Request) {
		seen = recordedRequest{
			Method:   r.Method,
			Bearer:   r.Header.Get("Authorization"),
			Hostname: r.Header.Get("X-Agent-Hostname"),
		}
		raw, _ := io.ReadAll(r.Body)
		body = string(raw)
		w.WriteHeader(200)
		_, _ = w.Write([]byte(`{"success":true,"token":"` + enrollToken + `"}`))
	})

	id := EnrollIdentity{UUID: "F1D2C3B4-A5E6", MAC: "aa:bb:cc:dd:ee:ff", Hostname: "SALLE101-PC03"}
	token, outcome := requestEnrollment(client, server.URL, id)

	if outcome != EnrollApproved {
		t.Fatalf("outcome : got %v, want EnrollApproved", outcome)
	}
	if token != enrollToken {
		t.Errorf("token : got %q, want %q", token, enrollToken)
	}
	// Piège n° 3 : POST SANS bearer (pas d'en-tête Authorization).
	if seen.Bearer != "" {
		t.Errorf("PostNoAuth ne doit PAS poser Authorization, got %q", seen.Bearer)
	}
	if seen.Method != http.MethodPost {
		t.Errorf("méthode : got %q, want POST", seen.Method)
	}
	// Faisceau {uuid, mac, hostname} sérialisé dans le corps.
	var parsed EnrollIdentity
	if err := json.Unmarshal([]byte(body), &parsed); err != nil {
		t.Fatalf("corps non JSON : %v (%q)", err, body)
	}
	if parsed.MAC != "aa:bb:cc:dd:ee:ff" || parsed.UUID != "F1D2C3B4-A5E6" || parsed.Hostname != "SALLE101-PC03" {
		t.Errorf("faisceau incomplet : %+v", parsed)
	}
}

func TestRequestEnrollmentPendingOn403(t *testing.T) {
	client, server := newEnrollClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(403)
		_, _ = w.Write([]byte(`{"error":"forbidden","code":"AGENT_ENROLL_NOT_ALLOWED"}`))
	})

	token, outcome := requestEnrollment(client, server.URL, EnrollIdentity{MAC: "aa:bb:cc:dd:ee:ff"})

	if outcome != EnrollPending {
		t.Fatalf("403 : got %v, want EnrollPending", outcome)
	}
	if token != "" {
		t.Errorf("403 ne doit pas livrer de token, got %q", token)
	}
}

func TestRequestEnrollmentConflictOn409(t *testing.T) {
	client, server := newEnrollClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(409)
		_, _ = w.Write([]byte(`{"error":"conflict","code":"AGENT_ENROLL_CONFLICT"}`))
	})

	_, outcome := requestEnrollment(client, server.URL, EnrollIdentity{MAC: "aa:bb:cc:dd:ee:ff"})

	if outcome != EnrollConflict {
		t.Fatalf("409 : got %v, want EnrollConflict", outcome)
	}
}

func TestRequestEnrollment200WithoutTokenIsError(t *testing.T) {
	client, server := newEnrollClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		_, _ = w.Write([]byte(`{"success":true}`))
	})

	_, outcome := requestEnrollment(client, server.URL, EnrollIdentity{})

	if outcome != EnrollError {
		t.Fatalf("200 sans token : got %v, want EnrollError (jamais de brique)", outcome)
	}
}

func TestRequestEnrollmentNetworkErrorIsError(t *testing.T) {
	// Serveur fermé immédiatement → erreur réseau.
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	store := newTestStore(t)
	client := NewClient(store, &Logger{}, "SALLE101-PC03")
	url := server.URL
	server.Close()

	_, outcome := requestEnrollment(client, url, EnrollIdentity{MAC: "aa:bb:cc:dd:ee:ff"})

	if outcome != EnrollError {
		t.Fatalf("réseau KO : got %v, want EnrollError", outcome)
	}
}

func TestRequestEnrollment5xxIsError(t *testing.T) {
	client, server := newEnrollClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(503)
	})

	_, outcome := requestEnrollment(client, server.URL, EnrollIdentity{MAC: "aa:bb:cc:dd:ee:ff"})

	if outcome != EnrollError {
		t.Fatalf("503 : got %v, want EnrollError (backoff)", outcome)
	}
}

func TestRequestEnrollmentUnexpected4xxIsError(t *testing.T) {
	// Tout code hors 200/403/409 tombe dans le `default` → EnrollError (backoff),
	// jamais une bascule silencieuse. Couvre 401/422/429 (validation Laravel,
	// throttle) en plus du 503 déjà testé.
	for _, status := range []int{401, 422, 429} {
		client, server := newEnrollClient(t, func(w http.ResponseWriter, r *http.Request) {
			w.WriteHeader(status)
			_, _ = w.Write([]byte(`{"error":"unexpected"}`))
		})

		token, outcome := requestEnrollment(client, server.URL, EnrollIdentity{MAC: "aa:bb:cc:dd:ee:ff"})

		if outcome != EnrollError {
			t.Errorf("%d : got %v, want EnrollError (backoff)", status, outcome)
		}
		if token != "" {
			t.Errorf("%d ne doit livrer aucun token, got %q", status, token)
		}
	}
}
