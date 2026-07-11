package shared

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
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

func TestCompanionRunLaunchesWatchdogBeforeCacheWait(t *testing.T) {
	// Levier A : le watchdog Rainmeter est lancé IMMÉDIATEMENT au démarrage,
	// AVANT l'attente du cache de convergence (WaitForCache). overlay.json est
	// déjà écrit par SYSTEM au logon → le rendu ne doit pas attendre le cache
	// per-SID. PollTimeout long + aucun cache : si le lancement n'arrivait
	// qu'APRÈS WaitForCache (boucle résidente), il faudrait ~PollTimeout (2 s)
	// pour le voir. On exige qu'il arrive bien avant (Tick anticipé).
	h := &fakeHandler{compliant: true}
	c, _ := newTestCompanion(t, h)
	c.PollInterval = 5 * time.Millisecond
	c.PollTimeout = 2 * time.Second
	c.CachePoll = 10 * time.Millisecond
	ops := &fakeRainmeterOps{installed: true, running: false, launched: make(chan struct{}, 1)}
	c.Watchdog = &RainmeterWatchdog{Ops: ops}

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		c.Run(ctx)
		close(done)
	}()

	select {
	case <-ops.launched: // lancé avant la fin de WaitForCache (2 s) → Tick anticipé
	case <-time.After(500 * time.Millisecond):
		cancel()
		<-done
		t.Fatal("le watchdog doit être lancé dès le démarrage, sans attendre WaitForCache")
	}

	cancel()
	<-done
}

// TestCompanionRunWritesUserRainmeterIniBeforeWatchdog (Story 27.1ter) : le
// compagnon écrit le Rainmeter.ini per-user (%APPDATA%) AVANT de lancer
// Rainmeter par le watchdog — sinon Rainmeter lirait un .ini absent (Safe Start)
// au premier lancement.
func TestCompanionRunWritesUserRainmeterIniBeforeWatchdog(t *testing.T) {
	c, _ := newTestCompanion(t, &fakeHandler{compliant: true})
	c.PollInterval = 5 * time.Millisecond
	c.PollTimeout = 2 * time.Second
	c.CachePoll = 10 * time.Millisecond

	var order []string
	var mu sync.Mutex
	c.EnsureUserRainmeterIni = func() error {
		mu.Lock()
		order = append(order, "ini")
		mu.Unlock()

		return nil
	}
	launched := make(chan struct{}, 1)
	ops := &fakeRainmeterOps{installed: true, running: false, launched: launched, onLaunch: func() {
		mu.Lock()
		order = append(order, "launch")
		mu.Unlock()
	}}
	c.Watchdog = &RainmeterWatchdog{Ops: ops}

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		c.Run(ctx)
		close(done)
	}()

	select {
	case <-launched:
	case <-time.After(500 * time.Millisecond):
		cancel()
		<-done
		t.Fatal("le watchdog doit être lancé au démarrage")
	}
	cancel()
	<-done

	mu.Lock()
	defer mu.Unlock()
	if len(order) < 2 || order[0] != "ini" || order[1] != "launch" {
		t.Fatalf("le .ini per-user doit être écrit AVANT le lancement de Rainmeter, got %v", order)
	}
}

// --- Story 43.1 : échelle de rafraîchissement (fin de RunPass) ----------------

// fakeRefreshOps : RefreshOps en mémoire — enregistre la SÉQUENCE des gestes
// (AC5 : prouver « un seul geste par passe, le plus fort ») et simule l'échec.
type fakeRefreshOps struct {
	seq          []string
	broadcastErr error
	restartErr   error
}

func (f *fakeRefreshOps) ShellNotify() { f.seq = append(f.seq, "shell_notify") }
func (f *fakeRefreshOps) PolicyBroadcast() error {
	f.seq = append(f.seq, "policy_broadcast")

	return f.broadcastErr
}
func (f *fakeRefreshOps) RestartExplorer() error {
	f.seq = append(f.seq, "explorer_restart")

	return f.restartErr
}

// newRefreshTestCompanion : compagnon câblé handlers registry+registry_list
// (fake ops registre partagée) + fake RefreshOps — le banc d'essai de
// l'agrégation de fin de passe.
func newRefreshTestCompanion(t *testing.T) (*Companion, *Store, *fakeRegistryOps, *fakeRefreshOps) {
	t.Helper()
	regOps := newFakeRegistryOps()
	refresh := &fakeRefreshOps{}
	c, store := newTestCompanion(t, nil)
	c.Engine.Handlers = map[string]Handler{
		"registry":      &RegistryHandler{Ops: regOps},
		"registry_list": &RegistryListHandler{Ops: regOps},
	}
	c.Refresh = refresh

	return c, store, regOps, refresh
}

// refreshSessionState : enveloppe /state v1 avec les items donnés en portée
// SESSION (le terrain du compagnon).
func refreshSessionState(items ...string) string {
	return `{"schema":"se5.desired-state/v1","generated_at":"2026-07-11T08:00:00+00:00","ttl_seconds":3600,"machine":[],"session":[` +
		strings.Join(items, ",") + `],"machine_user":[]}`
}

const registryHiddenNoHint = `{"type":"registry","semantics":"exclusive","hash":"` +
	`aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa` +
	`","payload":{"hive":"HKCU","path":"Software\\Test\\Advanced","name":"Hidden","type":"REG_DWORD","value":1}}`

const registryRestrictRunExplorerRestart = `{"type":"registry","semantics":"exclusive","hash":"` +
	`bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb` +
	`","payload":{"hive":"HKCU","path":"Software\\P\\Explorer","name":"RestrictRun","type":"REG_DWORD","value":1,"refresh":"explorer_restart"}}`

const registryListDisallowShellNotify = `{"type":"registry_list","semantics":"exclusive","hash":"` +
	`cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc` +
	`","payload":{"hive":"HKCU","path":"Software\\P\\Explorer\\DisallowRun","entry_type":"REG_SZ","values":["cmd.exe"],"refresh":"shell_notify"}}`

const registryUnknownHint = `{"type":"registry","semantics":"exclusive","hash":"` +
	`dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd` +
	`","payload":{"hive":"HKCU","path":"Software\\Test\\Advanced2","name":"HideFileExt","type":"REG_DWORD","value":0,"refresh":"warp_speed"}}`

const registryNoDrivesPolicyBroadcast = `{"type":"registry","semantics":"exclusive","hash":"` +
	`eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee` +
	`","payload":{"hive":"HKCU","path":"Software\\P\\Explorer","name":"NoDrives","type":"REG_DWORD","value":1,"refresh":"policy_broadcast"}}`

func TestCompanionRefreshStrongestGestureOnceAcrossHandlers(t *testing.T) {
	// AC5 (a)+(c) : hints hétérogènes sur des items changés répartis sur DEUX
	// handlers (registry hint explorer_restart, registry_list hint
	// shell_notify) ⇒ EXACTEMENT UN geste, le plus fort (explorer_restart).
	c, store, _, refresh := newRefreshTestCompanion(t)
	writeSessionCache(t, store, refreshSessionState(registryRestrictRunExplorerRestart, registryListDisallowShellNotify))

	ran, err := c.RunPass()
	if err != nil || !ran {
		t.Fatalf("passe attendue : %v %v", ran, err)
	}
	if len(refresh.seq) != 1 || refresh.seq[0] != "explorer_restart" {
		t.Fatalf("un seul geste (le plus fort) attendu : %v", refresh.seq)
	}
}

func TestCompanionRefreshStablePassNoGesture(t *testing.T) {
	// AC5 (b) : passe compliant/stable ⇒ ZÉRO geste, même avec un hint fort
	// sur l'item (le gate est le changement EFFECTIF, pas le hint).
	c, store, regOps, refresh := newRefreshTestCompanion(t)
	regOps.values[keyID("HKCU", `Software\P\Explorer`, "RestrictRun")] = RegistryValue{Kind: "REG_DWORD", Int: 1}
	writeSessionCache(t, store, refreshSessionState(registryRestrictRunExplorerRestart))

	ran, err := c.RunPass()
	if err != nil || !ran {
		t.Fatalf("passe attendue : %v %v", ran, err)
	}
	if len(refresh.seq) != 0 {
		t.Fatalf("passe stable : aucun geste attendu (NFR-A2), obtenu %v", refresh.seq)
	}
}

func TestCompanionRefreshFloorWithoutHintOrUnknownHint(t *testing.T) {
	// AC5 (d) / AC4 : item HKCU changé SANS hint (lot vues Explorer : Hidden)
	// + item au hint INCONNU ⇒ plancher shell_notify — la même séquence
	// observable que le SHChangeNotify historique (non-régression D2).
	c, store, _, refresh := newRefreshTestCompanion(t)
	writeSessionCache(t, store, refreshSessionState(registryHiddenNoHint, registryUnknownHint))

	ran, err := c.RunPass()
	if err != nil || !ran {
		t.Fatalf("passe attendue : %v %v", ran, err)
	}
	if len(refresh.seq) != 1 || refresh.seq[0] != "shell_notify" {
		t.Fatalf("plancher shell_notify attendu : %v", refresh.seq)
	}
}

func TestCompanionRefreshNilOpsNoPanicAndDrainsAccumulation(t *testing.T) {
	// AC5 (e) : Refresh nil ⇒ no-op SANS panique. Et l'accumulation des
	// handlers est DRAINÉE quand même : si l'ops apparaît ensuite, aucune
	// passe STABLE ne produit de geste fantôme.
	c, store, _, refresh := newRefreshTestCompanion(t)
	c.Refresh = nil
	writeSessionCache(t, store, refreshSessionState(registryRestrictRunExplorerRestart))

	ran, err := c.RunPass()
	if err != nil || !ran {
		t.Fatalf("passe attendue : %v %v", ran, err)
	}

	// L'ops arrive (mid-vie) : la passe suivante est STABLE (clés écrites au
	// tour 1) → zéro geste — le besoin du tour 1 n'a pas survécu (drainé).
	c.Refresh = refresh
	if _, err := c.RunPass(); err != nil {
		t.Fatalf("passe 2 : %v", err)
	}
	if len(refresh.seq) != 0 {
		t.Fatalf("aucun geste fantôme attendu après drain : %v", refresh.seq)
	}
}

func TestCompanionRefreshGestureFailureKeepsPassAndReportIntact(t *testing.T) {
	// AC5 (f) / D4 : un geste en ÉCHEC = warning — la passe reste réussie, le
	// drop est déposé avec ses statuts, l'applied-state est persisté (le geste
	// part APRÈS, D5 — l'échec ne peut rien casser en amont).
	c, store, _, refresh := newRefreshTestCompanion(t)
	refresh.restartErr = errors.New("explorer.exe introuvable")
	writeSessionCache(t, store, refreshSessionState(registryRestrictRunExplorerRestart))

	ran, err := c.RunPass()
	if err != nil || !ran {
		t.Fatalf("échec du geste ≠ échec de passe : %v %v", ran, err)
	}
	raw, err := os.ReadFile(c.DropPath)
	if err != nil {
		t.Fatalf("drop attendu malgré l'échec du geste : %v", err)
	}
	if !strings.Contains(string(raw), `"status":"drift"`) {
		t.Fatalf("statut drift attendu au drop : %s", raw)
	}
	applied, corrupted := ReadAppliedState(c.User.AppliedStatePath())
	if corrupted || applied["registry"].Hash == "" {
		t.Fatalf("applied-state persisté attendu : %+v %v", applied, corrupted)
	}
	if len(refresh.seq) != 1 || refresh.seq[0] != "explorer_restart" {
		t.Fatalf("le geste a bien été TENTÉ : %v", refresh.seq)
	}
}

// readCompanionLog : contenu du companion.log d'un Logger{Dir: dir} de test
// (vide si rien n'a encore été écrit).
func readCompanionLog(t *testing.T, dir string) string {
	t.Helper()
	raw, err := os.ReadFile(filepath.Join(dir, "companion.log"))
	if err != nil {
		return ""
	}

	return string(raw)
}

// redriftKey : simule une force EXTERNE qui réécrit une valeur HKCU entre deux
// passes (drift récurrent) — le Test suivant la voit divergente, l'Apply la
// re-converge (changed=true à chaque passe).
func redriftKey(regOps *fakeRegistryOps, path, name string) {
	regOps.values[keyID("HKCU", path, name)] = RegistryValue{Kind: "REG_DWORD", Int: 9}
}

func TestCompanionRefreshPolicyBroadcastGesture(t *testing.T) {
	// Review 43.1 #2 : hint policy_broadcast sur item HKCU changé ⇒ séquence
	// EXACTEMENT [policy_broadcast] (le case médian de l'échelle exercé
	// bout-en-bout par le compagnon).
	c, store, _, refresh := newRefreshTestCompanion(t)
	writeSessionCache(t, store, refreshSessionState(registryNoDrivesPolicyBroadcast))

	ran, err := c.RunPass()
	if err != nil || !ran {
		t.Fatalf("passe attendue : %v %v", ran, err)
	}
	if len(refresh.seq) != 1 || refresh.seq[0] != "policy_broadcast" {
		t.Fatalf("séquence [policy_broadcast] attendue : %v", refresh.seq)
	}
}

func TestCompanionRefreshPolicyBroadcastFailureKeepsPassAndReportIntact(t *testing.T) {
	// Review 43.1 #2 : broadcastErr non-nil ⇒ warning loggé, passe/drop/
	// applied-state INTACTS (best-effort D4), jamais une erreur de passe.
	c, store, _, refresh := newRefreshTestCompanion(t)
	logDir := t.TempDir()
	c.Log = &Logger{Dir: logDir, FileName: "companion.log"}
	refresh.broadcastErr = errors.New("fenêtre pendue (timeout)")
	writeSessionCache(t, store, refreshSessionState(registryNoDrivesPolicyBroadcast))

	ran, err := c.RunPass()
	if err != nil || !ran {
		t.Fatalf("échec du geste ≠ échec de passe : %v %v", ran, err)
	}
	if len(refresh.seq) != 1 || refresh.seq[0] != "policy_broadcast" {
		t.Fatalf("le geste a bien été TENTÉ : %v", refresh.seq)
	}
	raw, err := os.ReadFile(c.DropPath)
	if err != nil || !strings.Contains(string(raw), `"status":"drift"`) {
		t.Fatalf("drop intact attendu malgré l'échec du geste : %s %v", raw, err)
	}
	applied, corrupted := ReadAppliedState(c.User.AppliedStatePath())
	if corrupted || applied["registry"].Hash == "" {
		t.Fatalf("applied-state persisté attendu : %+v %v", applied, corrupted)
	}
	if !strings.Contains(readCompanionLog(t, logDir), "policy_broadcast en échec") {
		t.Fatalf("warning attendu au log :\n%s", readCompanionLog(t, logDir))
	}
}

func TestCompanionRefreshExplorerRestartThrottledDegradesToPolicyBroadcast(t *testing.T) {
	// Review 43.1 #1 (a) : deux passes changed successives au hint
	// explorer_restart (drift récurrent — une force externe réécrit la clé à
	// chaque passe) ⇒ 1er geste = explorer_restart, 2e DÉGRADÉ en
	// policy_broadcast + warning explicite (throttle anti-thrash : jamais deux
	// restarts en < 10 min, la session ne casse pas en boucle).
	c, store, regOps, refresh := newRefreshTestCompanion(t)
	logDir := t.TempDir()
	c.Log = &Logger{Dir: logDir, FileName: "companion.log"}
	now := time.Date(2026, 7, 11, 8, 0, 0, 0, time.UTC)
	c.Now = func() time.Time { return now }
	writeSessionCache(t, store, refreshSessionState(registryRestrictRunExplorerRestart))

	if _, err := c.RunPass(); err != nil {
		t.Fatalf("passe 1 : %v", err)
	}
	redriftKey(regOps, `Software\P\Explorer`, "RestrictRun")
	now = now.Add(time.Minute)
	if _, err := c.RunPass(); err != nil {
		t.Fatalf("passe 2 : %v", err)
	}

	if got := strings.Join(refresh.seq, ","); got != "explorer_restart,policy_broadcast" {
		t.Fatalf("séquence [explorer_restart policy_broadcast] attendue : %v", refresh.seq)
	}
	log := readCompanionLog(t, logDir)
	if !strings.Contains(log, "THROTTLÉ") || !strings.Contains(log, "drift récurrent") {
		t.Fatalf("warning de throttle (drift récurrent) attendu au log :\n%s", log)
	}
}

func TestCompanionRefreshExplorerRestartReallowedAfterWindow(t *testing.T) {
	// Review 43.1 #1 (b) : après avance d'horloge AU-DELÀ de la fenêtre de
	// 10 min, explorer_restart repart normalement.
	c, store, regOps, refresh := newRefreshTestCompanion(t)
	now := time.Date(2026, 7, 11, 8, 0, 0, 0, time.UTC)
	c.Now = func() time.Time { return now }
	writeSessionCache(t, store, refreshSessionState(registryRestrictRunExplorerRestart))

	if _, err := c.RunPass(); err != nil {
		t.Fatalf("passe 1 : %v", err)
	}
	redriftKey(regOps, `Software\P\Explorer`, "RestrictRun")
	now = now.Add(explorerRestartMinInterval + time.Second)
	if _, err := c.RunPass(); err != nil {
		t.Fatalf("passe 2 : %v", err)
	}

	if got := strings.Join(refresh.seq, ","); got != "explorer_restart,explorer_restart" {
		t.Fatalf("deux explorer_restart attendus (fenêtre écoulée) : %v", refresh.seq)
	}
}

func TestCompanionRefreshWeakerGesturesNeverThrottled(t *testing.T) {
	// Review 43.1 #1 (c) : shell_notify et policy_broadcast ne sont JAMAIS
	// throttlés — deux passes changed successives de chaque niveau, toutes
	// émises telles quelles, même DANS la fenêtre d'interdiction ouverte par
	// un explorer_restart antérieur.
	c, store, regOps, refresh := newRefreshTestCompanion(t)
	now := time.Date(2026, 7, 11, 8, 0, 0, 0, time.UTC)
	c.Now = func() time.Time { return now }

	// Arme le throttle : un explorer_restart part en passe 1.
	writeSessionCache(t, store, refreshSessionState(registryRestrictRunExplorerRestart))
	if _, err := c.RunPass(); err != nil {
		t.Fatalf("passe 1 : %v", err)
	}

	// Dans la fenêtre : deux passes shell_notify (plancher sans hint)…
	writeSessionCache(t, store, refreshSessionState(registryHiddenNoHint))
	for i := 0; i < 2; i++ {
		redriftKey(regOps, `Software\Test\Advanced`, "Hidden")
		now = now.Add(time.Minute)
		if _, err := c.RunPass(); err != nil {
			t.Fatalf("passe shell_notify %d : %v", i+1, err)
		}
	}
	// … puis deux passes policy_broadcast (hint explicite).
	writeSessionCache(t, store, refreshSessionState(registryNoDrivesPolicyBroadcast))
	for i := 0; i < 2; i++ {
		redriftKey(regOps, `Software\P\Explorer`, "NoDrives")
		now = now.Add(time.Minute)
		if _, err := c.RunPass(); err != nil {
			t.Fatalf("passe policy_broadcast %d : %v", i+1, err)
		}
	}

	want := "explorer_restart,shell_notify,shell_notify,policy_broadcast,policy_broadcast"
	if got := strings.Join(refresh.seq, ","); got != want {
		t.Fatalf("gestes faibles jamais throttlés — séquence %s attendue, obtenu %v", want, refresh.seq)
	}
}

func TestCompanionRefreshAccumulationResetBetweenPasses(t *testing.T) {
	// AC5 (g) : passe 1 (drift) → 1 geste ; passe 2 (stable) → AUCUN geste de
	// plus (l'accumulation est consommée par passe — pas de flicker au tick).
	c, store, _, refresh := newRefreshTestCompanion(t)
	writeSessionCache(t, store, refreshSessionState(registryHiddenNoHint))

	if _, err := c.RunPass(); err != nil {
		t.Fatalf("passe 1 : %v", err)
	}
	if len(refresh.seq) != 1 {
		t.Fatalf("passe 1 : un geste attendu, obtenu %v", refresh.seq)
	}
	if _, err := c.RunPass(); err != nil {
		t.Fatalf("passe 2 : %v", err)
	}
	if len(refresh.seq) != 1 {
		t.Fatalf("passe 2 stable : aucun geste supplémentaire, obtenu %v", refresh.seq)
	}
}

// TestCompanionRunUserRainmeterIniFailureGraceful (Story 27.1ter, NFR1) : un
// échec d'écriture du .ini per-user est gracieux — le watchdog lance quand même
// Rainmeter, le compagnon ne panique/ne bloque pas.
func TestCompanionRunUserRainmeterIniFailureGraceful(t *testing.T) {
	c, _ := newTestCompanion(t, &fakeHandler{compliant: true})
	c.PollInterval = 5 * time.Millisecond
	c.PollTimeout = 2 * time.Second
	c.CachePoll = 10 * time.Millisecond

	c.EnsureUserRainmeterIni = func() error { return errors.New("APPDATA non défini") }
	launched := make(chan struct{}, 1)
	c.Watchdog = &RainmeterWatchdog{Ops: &fakeRainmeterOps{installed: true, running: false, launched: launched}}

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		c.Run(ctx)
		close(done)
	}()

	select {
	case <-launched: // malgré l'échec du .ini, le watchdog lance Rainmeter
	case <-time.After(500 * time.Millisecond):
		cancel()
		<-done
		t.Fatal("le watchdog doit lancer Rainmeter même si l'écriture du .ini per-user échoue (NFR1)")
	}
	cancel()
	<-done
}
