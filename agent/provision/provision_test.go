package provision

import (
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// fixedResolver place toute ressource sous `dir/<RelPath>` (sous-arbo préservée),
// en garantissant le dossier parent — adaptateur de test OS-agnostique.
type fixedResolver struct {
	dir string
	err error // si non nil, Resolve échoue (simule une cible non résoluble).
}

func (f fixedResolver) Resolve(r Resource) (string, error) {
	if f.err != nil {
		return "", f.err
	}
	abs := filepath.Join(f.dir, filepath.FromSlash(r.RelPath))
	if err := ensureParentDir(abs); err != nil {
		return "", err
	}

	return abs, nil
}

func sha256hex(b []byte) string {
	h := sha256.Sum256(b)

	return hex.EncodeToString(h[:])
}

// serveContent monte un serveur HTTP de test renvoyant `body` pour `/path`, et
// 404 ailleurs. Retourne la base URL.
func serveContent(t *testing.T, routes map[string][]byte) string {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, ok := routes[r.URL.Path]
		if !ok {
			w.WriteHeader(http.StatusNotFound)

			return
		}
		_, _ = w.Write(body)
	}))
	t.Cleanup(srv.Close)

	return srv.URL
}

func TestReconcile_SkipWhenHashMatches(t *testing.T) {
	dir := t.TempDir()
	body := []byte("contenu 7za")
	hash := sha256hex(body)

	// Pré-déposer le fichier déjà à jour.
	if err := os.WriteFile(filepath.Join(dir, "7za.exe"), body, 0o644); err != nil {
		t.Fatal(err)
	}

	// Serveur qui DOIT NE PAS être appelé (s'il l'est, on renverrait un autre
	// contenu — mais on assert sur le statut skipped + intégrité du fichier).
	base := serveContent(t, map[string][]byte{"/7za.exe": []byte("AUTRE")})

	res := []Resource{{ID: "7za.exe", Kind: "wpkg-tool", RelPath: "7za.exe", URL: base + "/7za.exe", SHA256: hash}}
	out := Reconcile(res, fixedResolver{dir: dir})

	if len(out) != 1 || out[0].Status != StatusSkipped {
		t.Fatalf("attendu skipped, obtenu %+v", out)
	}
	// Le fichier ne doit pas avoir été réécrit avec "AUTRE".
	got, _ := os.ReadFile(filepath.Join(dir, "7za.exe"))
	if string(got) != string(body) {
		t.Fatalf("fichier réécrit alors qu'il était à jour : %q", got)
	}
}

func TestReconcile_ApplyWhenAbsent(t *testing.T) {
	dir := t.TempDir()
	body := []byte("nircmd binaire")
	hash := sha256hex(body)
	base := serveContent(t, map[string][]byte{"/nircmd.exe": body})

	res := []Resource{{ID: "nircmd.exe", Kind: "wpkg-tool", RelPath: "nircmd.exe", URL: base + "/nircmd.exe", SHA256: hash}}
	out := Reconcile(res, fixedResolver{dir: dir})

	if out[0].Status != StatusApplied {
		t.Fatalf("attendu applied, obtenu %+v", out[0])
	}
	got, err := os.ReadFile(filepath.Join(dir, "nircmd.exe"))
	if err != nil {
		t.Fatalf("fichier non déposé : %v", err)
	}
	if string(got) != string(body) {
		t.Fatalf("contenu déposé incorrect : %q", got)
	}
}

func TestReconcile_ApplyWhenHashDrifts(t *testing.T) {
	dir := t.TempDir()
	newBody := []byte("nouvelle version corrigée")
	hash := sha256hex(newBody)
	// Déposer une version OBSOLÈTE (hash divergent).
	if err := os.WriteFile(filepath.Join(dir, "7za.exe"), []byte("ancienne corrompue"), 0o644); err != nil {
		t.Fatal(err)
	}
	base := serveContent(t, map[string][]byte{"/7za.exe": newBody})

	res := []Resource{{ID: "7za.exe", Kind: "wpkg-tool", RelPath: "7za.exe", URL: base + "/7za.exe", SHA256: hash}}
	out := Reconcile(res, fixedResolver{dir: dir})

	if out[0].Status != StatusApplied {
		t.Fatalf("attendu applied (dérive de hash), obtenu %+v", out[0])
	}
	got, _ := os.ReadFile(filepath.Join(dir, "7za.exe"))
	if string(got) != string(newBody) {
		t.Fatalf("fichier non rafraîchi : %q", got)
	}
}

func TestReconcile_FailOnHashMismatch(t *testing.T) {
	dir := t.TempDir()
	body := []byte("contenu réel servi")
	wrongHash := sha256hex([]byte("ce que le manifeste prétend"))
	base := serveContent(t, map[string][]byte{"/7za.exe": body})

	res := []Resource{{ID: "7za.exe", Kind: "wpkg-tool", RelPath: "7za.exe", URL: base + "/7za.exe", SHA256: wrongHash}}
	out := Reconcile(res, fixedResolver{dir: dir})

	if out[0].Status != StatusFailed || out[0].Err == nil {
		t.Fatalf("attendu failed sur hash divergent, obtenu %+v", out[0])
	}
	// Écriture ATOMIQUE : aucun fichier final ne doit subsister (ni tmp).
	if _, err := os.Stat(filepath.Join(dir, "7za.exe")); !os.IsNotExist(err) {
		t.Fatalf("un fichier au mauvais hash a été publié")
	}
	entries, _ := os.ReadDir(dir)
	for _, e := range entries {
		if strings.Contains(e.Name(), ".tmp") {
			t.Fatalf("fichier temporaire non nettoyé : %s", e.Name())
		}
	}
}

func TestReconcile_FailOnHTTP404(t *testing.T) {
	dir := t.TempDir()
	base := serveContent(t, map[string][]byte{}) // tout en 404.

	res := []Resource{{ID: "absent.exe", Kind: "wpkg-tool", RelPath: "absent.exe", URL: base + "/absent.exe", SHA256: sha256hex([]byte("x"))}}
	out := Reconcile(res, fixedResolver{dir: dir})

	if out[0].Status != StatusFailed || out[0].Err == nil {
		t.Fatalf("attendu failed sur 404, obtenu %+v", out[0])
	}
}

func TestReconcile_FailOnResolveError(t *testing.T) {
	res := []Resource{{ID: "x.exe", Kind: "wpkg-tool", RelPath: "x.exe", URL: "http://unused", SHA256: "deadbeef"}}
	out := Reconcile(res, fixedResolver{err: os.ErrPermission})

	if out[0].Status != StatusFailed || out[0].Err == nil {
		t.Fatalf("attendu failed sur résolution KO, obtenu %+v", out[0])
	}
}

func TestReconcile_PreservesSubtree(t *testing.T) {
	dir := t.TempDir()
	body := []byte("wpkg-msg")
	hash := sha256hex(body)
	base := serveContent(t, map[string][]byte{"/tooltip/wpkg-msg.exe": body})

	res := []Resource{{ID: "tooltip/wpkg-msg.exe", Kind: "wpkg-tool", RelPath: "tooltip/wpkg-msg.exe", URL: base + "/tooltip/wpkg-msg.exe", SHA256: hash}}
	out := Reconcile(res, fixedResolver{dir: dir})

	if out[0].Status != StatusApplied {
		t.Fatalf("attendu applied, obtenu %+v", out[0])
	}
	// La sous-arborescence tooltip/ doit être préservée.
	if _, err := os.Stat(filepath.Join(dir, "tooltip", "wpkg-msg.exe")); err != nil {
		t.Fatalf("sous-arbre tooltip/ non préservé : %v", err)
	}
}

func TestReconcile_MultipleResourcesIndependentOutcomes(t *testing.T) {
	dir := t.TempDir()
	okBody := []byte("ok")
	okHash := sha256hex(okBody)
	base := serveContent(t, map[string][]byte{"/ok.exe": okBody}) // ko.exe → 404.

	res := []Resource{
		{ID: "ok.exe", Kind: "wpkg-tool", RelPath: "ok.exe", URL: base + "/ok.exe", SHA256: okHash},
		{ID: "ko.exe", Kind: "wpkg-tool", RelPath: "ko.exe", URL: base + "/ko.exe", SHA256: sha256hex([]byte("ko"))},
	}
	out := Reconcile(res, fixedResolver{dir: dir})

	if len(out) != 2 {
		t.Fatalf("attendu 2 outcomes, obtenu %d", len(out))
	}
	if out[0].Status != StatusApplied {
		t.Fatalf("ok.exe : attendu applied, obtenu %+v", out[0])
	}
	if out[1].Status != StatusFailed {
		t.Fatalf("ko.exe : attendu failed (un échec n'interrompt pas les suivants), obtenu %+v", out[1])
	}
}

func TestReconcile_EmptyHashAlwaysApplies(t *testing.T) {
	dir := t.TempDir()
	body := []byte("sans hash attendu")
	// Pré-déposer un fichier identique : sans hash, on ne peut pas skip → APPLY.
	if err := os.WriteFile(filepath.Join(dir, "x.exe"), body, 0o644); err != nil {
		t.Fatal(err)
	}
	base := serveContent(t, map[string][]byte{"/x.exe": body})

	res := []Resource{{ID: "x.exe", Kind: "wpkg-tool", RelPath: "x.exe", URL: base + "/x.exe", SHA256: ""}}
	out := Reconcile(res, fixedResolver{dir: dir})

	if out[0].Status != StatusApplied {
		t.Fatalf("hash vide → toujours applied (pas de skip possible), obtenu %+v", out[0])
	}
}

// TestReconcile_RejectsPathTraversal : un manifeste forgé ne doit JAMAIS faire
// écrire l'agent hors de la racine du kind (defense-in-depth). validateRelPath
// rejette AVANT toute résolution → StatusFailed, aucun fichier créé, aucune
// requête HTTP émise (un serveur qui paniquerait s'il était appelé).
func TestReconcile_RejectsPathTraversal(t *testing.T) {
	dir := t.TempDir()
	srv := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {
		t.Fatal("le téléchargement ne doit PAS être tenté pour un relpath traversal")
	}))
	t.Cleanup(srv.Close)

	malicious := []string{
		"../../System32/evil.exe", // remontée slash Unix.
		`..\..\evil.exe`,          // remontée backslash Windows (manifeste OS distant).
		"/etc/passwd",             // absolu Unix.
		`C:\evil.exe`,             // absolu Windows (volume).
		"sub/../../escape.exe",    // remontée au milieu.
		"",                        // vide.
	}
	for _, rel := range malicious {
		res := []Resource{{ID: "evil", Kind: "wpkg-tool", RelPath: rel, URL: srv.URL + "/evil", SHA256: sha256hex([]byte("x"))}}
		out := Reconcile(res, fixedResolver{dir: dir})
		if out[0].Status != StatusFailed || out[0].Err == nil {
			t.Fatalf("relpath %q : attendu failed (refusé), obtenu %+v", rel, out[0])
		}
	}

	// Aucun fichier ne doit avoir fui hors de la racine de test.
	if entries, _ := os.ReadDir(dir); len(entries) != 0 {
		t.Fatalf("des fichiers ont été créés malgré le refus : %d entrées", len(entries))
	}
}
