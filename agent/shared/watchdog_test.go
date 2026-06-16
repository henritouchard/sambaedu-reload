package shared

import (
	"testing"
	"time"
)

// fakeRainmeterOps : ops injectables (present/absent + compteur de relances).
// launched (optionnel) : signal NON-BLOQUANT à chaque Launch — permet à un test
// concurrent (Run en goroutine) d'observer un lancement SANS lire launchCount
// (qui serait une data-race avec la goroutine du compagnon).
type fakeRainmeterOps struct {
	installed   bool
	running     bool
	launchErr   error
	launchCount int
	launched    chan struct{}
}

func (f *fakeRainmeterOps) Installed() bool { return f.installed }
func (f *fakeRainmeterOps) Running() bool   { return f.running }
func (f *fakeRainmeterOps) Launch() error {
	f.launchCount++
	// Une relance « réussie » rend Rainmeter présent (modèle réaliste).
	if f.launchErr == nil {
		f.running = true
	}
	if f.launched != nil {
		select {
		case f.launched <- struct{}{}:
		default:
		}
	}

	return f.launchErr
}

func TestWatchdog_NilInert(t *testing.T) {
	var w *RainmeterWatchdog
	if w.Tick() {
		t.Fatal("watchdog nil doit être inerte")
	}
	w = &RainmeterWatchdog{} // Ops nil
	if w.Tick() {
		t.Fatal("watchdog sans Ops doit être inerte")
	}
}

func TestWatchdog_NotInstalledNoLaunch(t *testing.T) {
	ops := &fakeRainmeterOps{installed: false, running: false}
	w := &RainmeterWatchdog{Ops: ops}
	if w.Tick() {
		t.Fatal("Rainmeter non posé : jamais de relance (le provisioning n'a pas encore eu lieu)")
	}
	if ops.launchCount != 0 {
		t.Fatalf("aucune relance attendue, got %d", ops.launchCount)
	}
}

func TestWatchdog_RunningNoLaunch(t *testing.T) {
	ops := &fakeRainmeterOps{installed: true, running: true}
	w := &RainmeterWatchdog{Ops: ops}
	if w.Tick() {
		t.Fatal("Rainmeter vivant : no-op idempotent")
	}
	if ops.launchCount != 0 {
		t.Fatalf("aucune relance attendue, got %d", ops.launchCount)
	}
}

func TestWatchdog_AbsentRelaunched(t *testing.T) {
	ops := &fakeRainmeterOps{installed: true, running: false}
	w := &RainmeterWatchdog{Ops: ops}
	if !w.Tick() {
		t.Fatal("Rainmeter posé mais absent : relance attendue")
	}
	if ops.launchCount != 1 {
		t.Fatalf("une relance attendue, got %d", ops.launchCount)
	}
	// Tick suivant : Rainmeter de nouveau vivant → no-op.
	if w.Tick() {
		t.Fatal("après relance réussie, Rainmeter vivant → no-op")
	}
}

func TestWatchdog_BoundedRelaunch(t *testing.T) {
	// Rainmeter qui ne « démarre » jamais (launchErr) : le back-off borne les
	// tentatives (jamais de boucle de relance serrée).
	now := time.Unix(0, 0)
	ops := &fakeRainmeterOps{installed: true, running: false, launchErr: errLaunch}
	w := &RainmeterWatchdog{
		Ops:                 ops,
		MinRelaunchInterval: 30 * time.Second,
		Now:                 func() time.Time { return now },
	}

	// 1re tentative : relance.
	if !w.Tick() || ops.launchCount != 1 {
		t.Fatalf("1re tentative attendue, count=%d", ops.launchCount)
	}
	// Tick immédiat (même instant) : back-off → pas de nouvelle tentative.
	if w.Tick() || ops.launchCount != 1 {
		t.Fatalf("back-off : pas de relance serrée, count=%d", ops.launchCount)
	}
	// Avance < intervalle : toujours borné.
	now = now.Add(20 * time.Second)
	if w.Tick() || ops.launchCount != 1 {
		t.Fatalf("toujours borné avant l'intervalle, count=%d", ops.launchCount)
	}
	// Avance au-delà de l'intervalle : nouvelle tentative autorisée.
	now = now.Add(15 * time.Second)
	if !w.Tick() || ops.launchCount != 2 {
		t.Fatalf("nouvelle tentative après l'intervalle, count=%d", ops.launchCount)
	}
}

var errLaunch = errLaunchSentinel{}

type errLaunchSentinel struct{}

func (errLaunchSentinel) Error() string { return "lancement Rainmeter simulé en échec" }
