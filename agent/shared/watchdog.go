package shared

import "time"

// Watchdog Rainmeter côté COMPAGNON (Story 27.1bis, volet 3 — décision D5).
// Le compagnon tourne aux droits de la SESSION (relance triviale, os/exec) et
// meurt au logoff (acceptable : pas de session = rien à rendre). Il relance
// Rainmeter.exe s'il disparaît (élève qui le tue, crash), pointant la config
// VERROUILLÉE sous ProgramData ACL. PAS d'obfuscation de process (D7) : l'élève
// voit/tue son Rainmeter.exe → c'est le watchdog qui répond.
//
// Logique de décision PURE (testable hôte) : présence du process + back-off
// borné. Le lancement OS (CreateProcess pointant la config ProgramData) et la
// détection de présence sont INJECTÉS (RainmeterOps) — agent/windows les câble.
//
// Idempotent (Rainmeter vivant → no-op) et BORNÉ (jamais de boucle de relance
// serrée : minimum MinRelaunchInterval entre deux tentatives — un Rainmeter qui
// crashe en boucle ne sature pas le poste).

// RainmeterOps : primitives OS du watchdog, injectées par agent/windows
// (nil-safe : un nil rend le watchdog inerte — tests hôte, plateforme sans
// Rainmeter).
type RainmeterOps interface {
	// Installed : Rainmeter portable est-il posé (Rainmeter.exe présent) ?
	// Absent = rien à surveiller (le provisioning SYSTEM ne l'a pas encore
	// posé) — le watchdog ne lance jamais un binaire absent.
	Installed() bool

	// Running : un process Rainmeter.exe tourne-t-il dans CETTE session ?
	Running() bool

	// Launch : lance Rainmeter.exe pointant la config verrouillée ProgramData
	// (skin SambaEduOverlay). Retourne une erreur loggée (jamais propagée à la
	// boucle compagnon).
	Launch() error
}

// RainmeterWatchdog : état du watchdog (cadence de relance bornée). Réutilisé
// d'un tick à l'autre par le compagnon — il porte la mémoire du dernier
// lancement pour le back-off.
type RainmeterWatchdog struct {
	Ops RainmeterOps
	Log *Logger

	// MinRelaunchInterval : délai minimal entre deux tentatives de relance
	// (borne anti-boucle serrée). 0 = défaut 30 s.
	MinRelaunchInterval time.Duration

	// Now : horloge injectable (tests). nil = time.Now.
	Now func() time.Time

	lastLaunchAt time.Time
}

func (w *RainmeterWatchdog) now() time.Time {
	if w.Now != nil {
		return w.Now()
	}

	return time.Now()
}

func (w *RainmeterWatchdog) minInterval() time.Duration {
	if w.MinRelaunchInterval <= 0 {
		return 30 * time.Second
	}

	return w.MinRelaunchInterval
}

// Tick : UNE évaluation du watchdog (appelée à chaque tour de la boucle
// résidente du compagnon). Décide s'il faut relancer Rainmeter et le fait.
// Idempotent et borné. Retourne true si une relance a été TENTÉE ce tick
// (diagnostic/tests).
func (w *RainmeterWatchdog) Tick() bool {
	if w == nil || w.Ops == nil {
		return false
	}

	// Rien à surveiller tant que le portable n'est pas posé (provisioning
	// SYSTEM pas encore passé) : on ne lance jamais un binaire absent.
	if !w.Ops.Installed() {
		return false
	}

	// Rainmeter vivant : no-op (idempotent). On NE remet PAS le back-off à
	// zéro ici — le minInterval ne borne QUE les tentatives consécutives de
	// relance, pas le fonctionnement nominal.
	if w.Ops.Running() {
		return false
	}

	// Rainmeter absent : back-off borné (jamais de relance serrée).
	if !w.lastLaunchAt.IsZero() && w.now().Sub(w.lastLaunchAt) < w.minInterval() {
		return false
	}

	w.lastLaunchAt = w.now()
	if err := w.Ops.Launch(); err != nil {
		logWarning(w.Log, "Watchdog Rainmeter : relance en échec (%v) — nouvelle tentative au prochain tick (borné).", err)

		return true
	}
	logInfo(w.Log, "Watchdog Rainmeter : process absent → relancé (config verrouillée ProgramData).")

	return true
}
