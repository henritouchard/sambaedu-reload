package shared

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// newTestCompanion : compagnon câblé sur des répertoires éphémères, moteur
// avec un handler wallpaper scriptable.
func newTestCompanion(t *testing.T, handler Handler) (*Companion, *Store) {
	t.Helper()
	store := newTestStore(t)
	if err := store.EnsureSessionCacheDir(testSID, nil); err != nil {
		t.Fatal(err)
	}
	if err := store.EnsureSessionReportDir(testSID, nil); err != nil {
		t.Fatal(err)
	}

	handlers := map[string]Handler{}
	if handler != nil {
		handlers["wallpaper"] = handler
	}

	return &Companion{
		SID:       testSID,
		StatePath: store.SessionStatePath(testSID),
		DropDir:   store.SessionReportDir(testSID),
		DropPath:  store.SessionReportPath(testSID),
		User:      &UserStore{Root: filepath.Join(t.TempDir(), "localappdata")},
		Engine:    &Engine{Handlers: handlers},
		Log:       &Logger{},
	}, store
}

func writeSessionCache(t *testing.T, store *Store, body string) {
	t.Helper()
	if err := store.WriteSessionStateCache(testSID, []byte(body), `"e"`, nil); err != nil {
		t.Fatal(err)
	}
}

func TestCompanionPassPartitionsAndDrops(t *testing.T) {
	h := &fakeHandler{}
	c, store := newTestCompanion(t, h)
	// Le golden : 1 item machine (aucun ici), 2 session (wallpaper+overlay),
	// 1 machine_user (shortcuts). Seuls session+machine_user sont traités ;
	// overlay/shortcuts sans handler → ignorés sans statut.
	writeSessionCache(t, store, string(mustReadGolden(t)))

	ran, err := c.RunPass()
	if err != nil || !ran {
		t.Fatalf("passe attendue : %v %v", ran, err)
	}

	// Drop déposé : 1 statut (wallpaper, premier passage non conforme → drift).
	raw, err := os.ReadFile(c.DropPath)
	if err != nil {
		t.Fatalf("drop attendu : %v", err)
	}
	var drop struct {
		GeneratedAt string       `json:"generated_at"`
		Items       []ReportItem `json:"items"`
	}
	if err := json.Unmarshal(raw, &drop); err != nil {
		t.Fatal(err)
	}
	if len(drop.Items) != 1 || drop.Items[0].Type != "wallpaper" || drop.Items[0].Status != "drift" {
		t.Errorf("drop : %+v", drop.Items)
	}
	if _, err := time.Parse(time.RFC3339, drop.GeneratedAt); err != nil {
		t.Errorf("generated_at RFC3339 : %q", drop.GeneratedAt)
	}

	// Applied-state PER-USER persisté.
	applied, corrupted := ReadAppliedState(c.User.AppliedStatePath())
	if corrupted || applied["wallpaper"].Hash == "" {
		t.Errorf("applied-state per-user : %+v %v", applied, corrupted)
	}

	// Le handler n'a JAMAIS vu la portée machine (partition).
	for _, item := range h.lastItems {
		if item.Type != "wallpaper" {
			t.Errorf("item inattendu chez le handler : %+v", item)
		}
	}
}

func TestCompanionPassMachineScopeNeverDispatched(t *testing.T) {
	// Un wallpaper en portée MACHINE n'est jamais traité par le compagnon
	// (partition stricte, piège n° 3) — le scope est déclaré par type mais
	// la partition se fait par PORTÉE de l'enveloppe.
	h := &fakeHandler{}
	c, store := newTestCompanion(t, h)
	machineOnly := `{"schema":"se5.desired-state/v1","generated_at":"2026-06-12T08:00:00+00:00","ttl_seconds":3600,"machine":[{"type":"wallpaper","payload":{"asset":null},"hash":"` + strings.Repeat("a", 64) + `"}],"session":[],"machine_user":[]}`
	writeSessionCache(t, store, machineOnly)

	ran, err := c.RunPass()
	if err != nil || !ran {
		t.Fatal(err)
	}
	if h.testCalls.Load() != 0 {
		t.Error("la portée machine est l'exclusivité du service SYSTEM")
	}

	// Drop déposé avec zéro item (la passe a tourné).
	raw, _ := os.ReadFile(c.DropPath)
	if !strings.Contains(string(raw), `"items":[]`) {
		t.Errorf("drop vide attendu : %s", raw)
	}
}

func TestCompanionPassCacheAbsentNoPass(t *testing.T) {
	c, _ := newTestCompanion(t, &fakeHandler{})

	ran, err := c.RunPass()
	if err != nil || ran {
		t.Errorf("aucune passe sans cache : %v %v", ran, err)
	}
	if _, err := os.Stat(c.DropPath); err == nil {
		t.Error("aucun drop sans passe")
	}
}

func TestCompanionPassDropDirAbsentSkipsQuietly(t *testing.T) {
	// Install pas à niveau / fetch pas encore passé : la convergence locale
	// a EU lieu, le drop attendra (rapport au cycle suivant).
	h := &fakeHandler{compliant: true}
	c, store := newTestCompanion(t, h)
	writeSessionCache(t, store, string(mustReadGolden(t)))
	if err := os.RemoveAll(c.DropDir); err != nil {
		t.Fatal(err)
	}

	ran, err := c.RunPass()
	if err != nil || !ran {
		t.Fatalf("la passe converge quand même : %v %v", ran, err)
	}
	if h.testCalls.Load() != 1 {
		t.Error("convergence attendue malgré le drop absent")
	}
}

func TestCompanionPassUnknownMajorErrors(t *testing.T) {
	c, store := newTestCompanion(t, &fakeHandler{})
	writeSessionCache(t, store, `{"schema":"se5.desired-state/v2","machine":[],"session":[],"machine_user":[]}`)

	ran, err := c.RunPass()
	if err == nil || ran {
		t.Errorf("major inconnu = erreur de passe (log + retry au tick) : %v %v", ran, err)
	}
}

func TestCompanionPassCorruptedAppliedStateRestartsWithoutMemory(t *testing.T) {
	// applied-state corrompu = premier passage §5 → drift (STRICT, Story 27.8).
	h := &fakeHandler{} // non conforme
	c, store := newTestCompanion(t, h)
	writeSessionCache(t, store, string(mustReadGolden(t)))
	if err := c.User.EnsureRoot(); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(c.User.AppliedStatePath(), []byte("{broken"), 0o600); err != nil {
		t.Fatal(err)
	}

	if _, err := c.RunPass(); err != nil {
		t.Fatal(err)
	}
	raw, _ := os.ReadFile(c.DropPath)
	if !strings.Contains(string(raw), `"status":"drift"`) {
		t.Errorf("premier passage sans mémoire = drift (STRICT inconditionnel) : %s", raw)
	}
}

func TestCompanionWaitForCacheFreshVsStale(t *testing.T) {
	c, store := newTestCompanion(t, nil)
	c.PollInterval = 10 * time.Millisecond
	c.PollTimeout = 100 * time.Millisecond
	c.FreshWindow = 5 * time.Minute

	// Aucun cache → (false, false).
	fresh, exists := c.WaitForCache(context.Background())
	if fresh || exists {
		t.Errorf("aucun cache : %v %v", fresh, exists)
	}

	// Cache frais (mtime récent) → (true, true).
	writeSessionCache(t, store, `{}`)
	fresh, exists = c.WaitForCache(context.Background())
	if !fresh || !exists {
		t.Errorf("cache frais : %v %v", fresh, exists)
	}

	// Cache VIEUX (mtime au-delà de la fenêtre) → (false, true) : la
	// session vit sur le dernier état connu.
	old := time.Now().Add(-time.Hour)
	if err := os.Chtimes(c.StatePath, old, old); err != nil {
		t.Fatal(err)
	}
	fresh, exists = c.WaitForCache(context.Background())
	if fresh || !exists {
		t.Errorf("cache vieux : %v %v", fresh, exists)
	}
}

func TestCompanionRunResidentReconvergesOnCacheChange(t *testing.T) {
	h := &fakeHandler{compliant: true}
	c, store := newTestCompanion(t, h)
	c.PollInterval = 5 * time.Millisecond
	c.PollTimeout = 30 * time.Millisecond
	c.CachePoll = 20 * time.Millisecond
	c.PeriodicPass = time.Hour // neutralisé : on ne teste que le mtime
	writeSessionCache(t, store, string(mustReadGolden(t)))

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		c.Run(ctx)
		close(done)
	}()

	// 1re passe (cache frais), puis attendre un tick.
	deadline := time.Now().Add(2 * time.Second)
	for h.testCalls.Load() < 1 && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	if h.testCalls.Load() < 1 {
		t.Fatal("première passe attendue")
	}

	// Changement d'état (mtime) → re-convergence.
	time.Sleep(20 * time.Millisecond)
	writeSessionCache(t, store, string(mustReadGolden(t)))
	future := time.Now().Add(2 * time.Second)
	_ = os.Chtimes(c.StatePath, future, future)

	for h.testCalls.Load() < 2 && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	if h.testCalls.Load() < 2 {
		t.Error("re-convergence sur changement de cache attendue")
	}

	cancel()
	select {
	case <-done: // sortie propre
	case <-time.After(2 * time.Second):
		t.Fatal("le compagnon doit sortir proprement sur ctx")
	}
}

func TestCompanionRunStaysResidentWithoutCache(t *testing.T) {
	// Premier logon hors-ligne sans cache : on RESTE résident (le cycle du
	// service peut écrire le cache mid-session), en silence.
	h := &fakeHandler{compliant: true}
	c, store := newTestCompanion(t, h)
	c.PollInterval = 5 * time.Millisecond
	c.PollTimeout = 20 * time.Millisecond
	c.CachePoll = 15 * time.Millisecond

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan struct{})
	go func() {
		c.Run(ctx)
		close(done)
	}()

	// Pas de cache → pas de passe.
	time.Sleep(60 * time.Millisecond)
	if h.testCalls.Load() != 0 {
		t.Fatal("aucune passe sans cache")
	}

	// Le cache apparaît mid-session → convergence.
	writeSessionCache(t, store, string(mustReadGolden(t)))
	deadline := time.Now().Add(2 * time.Second)
	for h.testCalls.Load() < 1 && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	if h.testCalls.Load() < 1 {
		t.Error("convergence dès qu'un cache apparaît attendue")
	}

	cancel()
	<-done
}
