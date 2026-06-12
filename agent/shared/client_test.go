package shared

import (
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
)

// tokenA/B/C/D : tokens de test (64 hex valides).
var (
	tokenA = strings.Repeat("a", 64)
	tokenB = strings.Repeat("b", 64)
	tokenC = strings.Repeat("c", 64)
	tokenD = strings.Repeat("d", 64)
)

type recordedRequest struct {
	Method      string
	Bearer      string
	Hostname    string
	IfNoneMatch string
	Body        string
}

func bearerOf(r *http.Request) string {
	return strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
}

func newTestClient(t *testing.T, handler http.HandlerFunc) (*Client, *Store, *httptest.Server) {
	t.Helper()
	server := httptest.NewServer(handler)
	t.Cleanup(server.Close)

	store := newTestStore(t)
	client := NewClient(store, &Logger{}, "SALLE101-PC03")

	return client, store, server
}

func TestClientSendsContractHeaders(t *testing.T) {
	var seen recordedRequest
	client, _, server := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		seen = recordedRequest{
			Method:      r.Method,
			Bearer:      bearerOf(r),
			Hostname:    r.Header.Get("X-Agent-Hostname"),
			IfNoneMatch: r.Header.Get("If-None-Match"),
		}
		w.WriteHeader(200)
	})
	client.SetToken(tokenA)

	etag := `"` + frozenStateHash + `"`
	if _, err := client.Get(server.URL+"/state", etag); err != nil {
		t.Fatalf("Get : %v", err)
	}

	if seen.Bearer != tokenA {
		t.Errorf("Authorization : got %q", seen.Bearer)
	}
	// Anti-clonage 23.2 : hostname COURT sur chaque appel.
	if seen.Hostname != "SALLE101-PC03" {
		t.Errorf("X-Agent-Hostname : got %q", seen.Hostname)
	}
	// ETag renvoyé VERBATIM (guillemets RFC 7232 inclus).
	if seen.IfNoneMatch != etag {
		t.Errorf("If-None-Match verbatim : got %q, want %q", seen.IfNoneMatch, etag)
	}
}

func TestClientRotationOnGet200(t *testing.T) {
	client, store, server := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-Agent-New-Token", tokenB)
		w.WriteHeader(200)
	})
	writeToken(t, store, tokenA)
	client.SetToken(tokenA)

	resp, err := client.Get(server.URL+"/state", "")
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("Get : %v / %v", resp, err)
	}

	// Nouveau token écrit ATOMIQUEMENT sur disque + utilisé dès l'appel suivant.
	if disk, _ := store.ReadToken(); disk != tokenB {
		t.Errorf("token disque : got %q, want tokenB", disk)
	}
	if client.Token() != tokenB {
		t.Errorf("token courant : got %q, want tokenB", client.Token())
	}
}

func TestClientRotationOn304AndOnPostNon200(t *testing.T) {
	// Invariant D5 : X-Agent-New-Token lu sur TOUTE réponse — 304 du GET et
	// même un 422 du POST.
	cases := []struct {
		name string
		call func(c *Client, url string) (*Response, error)
		code int
	}{
		{"get_304", func(c *Client, url string) (*Response, error) { return c.Get(url, `"e"`) }, 304},
		{"post_422", func(c *Client, url string) (*Response, error) { return c.Post(url, []byte(`{}`)) }, 422},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			client, store, server := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("X-Agent-New-Token", tokenC)
				w.WriteHeader(tc.code)
			})
			writeToken(t, store, tokenA)
			client.SetToken(tokenA)

			resp, err := tc.call(client, server.URL)
			if err != nil || resp.StatusCode != tc.code {
				t.Fatalf("appel : %v / %v", resp, err)
			}
			if disk, _ := store.ReadToken(); disk != tokenC {
				t.Errorf("rotation sur %d : token disque %q, want tokenC", tc.code, disk)
			}
		})
	}
}

func TestClientGraceWindowAfterRotation(t *testing.T) {
	// Séquence réaliste : rotation reçue (A→B), puis le serveur refuse B
	// (fenêtre de grâce côté serveur) — l'agent réessaie UNE fois avec A.
	step := 0
	var bearers []string
	client, store, server := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		bearers = append(bearers, bearerOf(r))
		step++
		switch step {
		case 1: // rotation : A accepté, B émis
			w.Header().Set("X-Agent-New-Token", tokenB)
			w.WriteHeader(200)
		case 2: // B refusé (401) → grâce attendue
			w.WriteHeader(401)
		default: // réessai avec A → accepté
			w.WriteHeader(200)
		}
	})
	writeToken(t, store, tokenA)
	client.SetToken(tokenA)

	if _, err := client.Get(server.URL, ""); err != nil {
		t.Fatal(err)
	}
	resp, err := client.Get(server.URL, "")
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Fatalf("la grâce devait aboutir à 200, got %d", resp.StatusCode)
	}
	want := []string{tokenA, tokenB, tokenA}
	for i, b := range want {
		if bearers[i] != b {
			t.Errorf("appel %d : bearer %q, want %q", i+1, bearers[i], b)
		}
	}
	// L'ancien token redevient le courant pour la suite du cycle.
	if client.Token() != tokenA {
		t.Errorf("token courant après grâce : %q, want tokenA", client.Token())
	}
}

func TestClientGracePurgedOnceNewTokenAccepted(t *testing.T) {
	// Une fois le token post-rotation ACCEPTÉ, la grâce est purgée : un 401
	// ultérieur (révocation légitime) ne déclenche AUCUN réessai parasite.
	step := 0
	calls := 0
	client, store, server := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		calls++
		step++
		switch step {
		case 1:
			w.Header().Set("X-Agent-New-Token", tokenB)
			w.WriteHeader(200)
		case 2: // B accepté → fenêtre fermée
			w.WriteHeader(200)
		default: // révocation
			w.WriteHeader(401)
		}
	})
	writeToken(t, store, tokenB) // le disque porte déjà B (rotation persistée)
	client.SetToken(tokenA)

	_, _ = client.Get(server.URL, "")
	_, _ = client.Get(server.URL, "")
	callsBefore := calls
	resp, _ := client.Get(server.URL, "")

	if resp.StatusCode != 401 {
		t.Fatalf("révocation : 401 attendu, got %d", resp.StatusCode)
	}
	// 1 seul appel pour le 401 final : pas de réessai mémoire (purgée) ni
	// disque (identique au token essayé).
	if calls-callsBefore != 1 {
		t.Errorf("réessais parasites après purge de la grâce : %d appels", calls-callsBefore)
	}
}

func TestClientTwoActorDiskReread(t *testing.T) {
	// Durcissement deux-acteurs (24.3) : un AUTRE acteur SYSTEM a rotaté le
	// token sur disque pendant que cet appel était en vol → relecture disque
	// + réessai UNIQUE.
	var bearers []string
	client, store, server := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		b := bearerOf(r)
		bearers = append(bearers, b)
		if b == tokenD {
			w.WriteHeader(200)

			return
		}
		w.WriteHeader(401)
	})
	writeToken(t, store, tokenD) // l'autre acteur a déjà écrit D
	client.SetToken(tokenA)      // ce process est resté sur A

	resp, err := client.Get(server.URL, "")
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Fatalf("rattrapage deux-acteurs : 200 attendu, got %d", resp.StatusCode)
	}
	if len(bearers) != 2 || bearers[0] != tokenA || bearers[1] != tokenD {
		t.Errorf("séquence bearers : %v (attendu A puis D)", bearers)
	}
	if client.Token() != tokenD {
		t.Errorf("adoption du token disque : %q, want tokenD", client.Token())
	}
}

func TestClient401Irrecoverable(t *testing.T) {
	calls := 0
	client, store, server := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		calls++
		w.WriteHeader(401)
	})
	writeToken(t, store, tokenA) // disque = token essayé : rien à rattraper
	client.SetToken(tokenA)

	resp, err := client.Get(server.URL, "")
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 401 {
		t.Fatalf("401 attendu, got %d", resp.StatusCode)
	}
	if calls != 1 {
		t.Errorf("aucun réessai attendu (pas de grâce, disque identique) : %d appels", calls)
	}
}

func TestClientNetworkErrorBubblesUp(t *testing.T) {
	client, _, server := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {})
	server.Close() // connexion refusée
	client.SetToken(tokenA)

	if _, err := client.Get(server.URL, ""); err == nil {
		t.Error("erreur réseau attendue (→ backoff par l'appelant)")
	}
}

func TestClientRotationWritePreservesAcl(t *testing.T) {
	client, store, server := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-Agent-New-Token", tokenB)
		w.WriteHeader(200)
	})
	aclCalls := 0
	store.SetACL = func(path string) error {
		aclCalls++

		return nil
	}
	writeToken(t, store, tokenA)
	client.SetToken(tokenA)

	if _, err := client.Get(server.URL, ""); err != nil {
		t.Fatal(err)
	}
	if aclCalls == 0 {
		t.Error("l'écriture du token de rotation doit poser l'ACL")
	}
	if _, err := os.Stat(store.TokenPath()); err != nil {
		t.Errorf("token absent après rotation : %v", err)
	}
}
