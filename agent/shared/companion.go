package shared

import (
	"context"
	"errors"
	"os"
	"time"
)

// Compagnon de session — côté USER (Story 24.6, portage de
// SessionCompanion.ps1 24.3/24.4). S'exécute DANS la session du user qui
// ouvre, avec SES droits — jamais SYSTEM (tâche planifiée
// `SambaEduAgent-SessionCompanion`, principal BUILTIN\Users, At log on).
//
// Frontière de confiance (NFR5, contrat 23.3 FIGÉ) — ce processus :
//   - ne lit JAMAIS le token (ACL SYSTEM+Administrators : illisible ici) ;
//   - n'appelle JAMAIS le serveur (AUCUN code réseau dans ce fichier ni dans
//     le chemin companion — le canal réseau est 100 % SYSTEM : session-fetch
//     a tiré l'état au logon, SyncWallpaperAssets a pré-téléchargé) ;
//   - lit UNIQUEMENT son cache per-user cache\sessions\<SON SID>\state.json
//     (lecture seule) et le cache d'assets (lecture seule, Users:R) ;
//   - écrit dans %LOCALAPPDATA%\SambaEdu\Agent\ (log, applied-state,
//     overlay.json) + UNE exception cadrée (décision 24.4 n° 7) : SON drop
//     reports\sessions\<SON SID>\session-report.json (ACL <SID>:M posée par
//     SYSTEM — il n'écrit ni ne lit les drops des autres) ;
//   - ne déclare jamais son identité : son SID est résolu localement (token
//     de processus) UNIQUEMENT pour trouver SON cache/SON drop — l'identité
//     envoyée au serveur a été résolue côté SYSTEM par énumération WTS.
//
// Boucle RÉSIDENTE (décision 24.4 n° 6 conservée) : passe initiale après
// attente bornée du cache frais, puis poll du mtime du cache (~60 s) et
// re-test périodique (~5 min, level-triggered — détecte les dérives
// locales). Le processus meurt au logoff ; MultipleInstances IgnoreNew
// empêche le doublon. Quarantaine : le fetch SYSTEM est sauté → le compagnon
// converge sur son DERNIER cache (level-triggered, inoffensif — limitation
// MVP documentée, reconduite).
//
// AUCUNE dépendance AD/Kerberos/LDAP (NFR7). Aucun message visible, jamais :
// tout passe par companion.log.
type Companion struct {
	// SID : le SID du processus courant (token de processus, décision n° 2)
	// — uniquement pour trouver SON cache et SON drop.
	SID string

	// StatePath : cache\sessions\<SID>\state.json (lecture seule).
	StatePath string

	// DropDir/DropPath : reports\sessions\<SID>\ (ACL <SID>:M posée par
	// SYSTEM — répertoire absent = drop sauté, rapport au cycle suivant).
	DropDir  string
	DropPath string

	// User : racine per-user (%LOCALAPPDATA%\SambaEdu\Agent).
	User *UserStore

	// Engine : moteur de convergence (handlers wallpaper + overlay).
	Engine *Engine

	// Watchdog : surveillance de Rainmeter côté compagnon (Story 27.1bis, D5) —
	// relance Rainmeter.exe s'il disparaît (idempotent, borné). nil = inerte
	// (tests hôte, plateforme sans Rainmeter). Évalué à chaque tour de la
	// boucle résidente, JAMAIS dans le chemin synchrone du logon.
	Watchdog *RainmeterWatchdog

	Log *Logger

	// Now : horloge injectable (tests). nil = time.Now.
	Now func() time.Time

	// Cadences — défauts iso-24.3/24.4, injectables (tests).
	PollInterval time.Duration // poll du cache frais (~2 s)
	PollTimeout  time.Duration // attente bornée au démarrage (~60 s)
	FreshWindow  time.Duration // « frais » = RÉCENT (< 5 min), pas « du logon courant »
	CachePoll    time.Duration // boucle résidente : poll mtime (~60 s)
	PeriodicPass time.Duration // re-test périodique level-triggered (~5 min)
}

func (c *Companion) now() time.Time {
	if c.Now != nil {
		return c.Now()
	}

	return time.Now()
}

func (c *Companion) pollInterval() time.Duration { return defaultDuration(c.PollInterval, 2*time.Second) }
func (c *Companion) pollTimeout() time.Duration  { return defaultDuration(c.PollTimeout, 60*time.Second) }
func (c *Companion) freshWindow() time.Duration  { return defaultDuration(c.FreshWindow, 5*time.Minute) }
func (c *Companion) cachePoll() time.Duration    { return defaultDuration(c.CachePoll, 60*time.Second) }
func (c *Companion) periodicPass() time.Duration { return defaultDuration(c.PeriodicPass, 5*time.Minute) }

func defaultDuration(v, fallback time.Duration) time.Duration {
	if v <= 0 {
		return fallback
	}

	return v
}

// WaitForCache : attente bornée (poll) d'un state.json FRAIS dans le cache
// de CE SID, sinon dernier cache existant, sinon absent (décision 24.3 n° 1).
// « Frais » = RÉCENT (< FreshWindow), PAS « garanti du logon courant »
// (review 24.3 #4) : la tâche session-fetch démarre en parallèle, son
// écriture peut précéder de peu le démarrage du compagnon — la fenêtre
// accepte donc aussi un cache écrit par un cycle service juste avant.
// Retourne (fresh, exists).
func (c *Companion) WaitForCache(ctx context.Context) (fresh, exists bool) {
	start := c.now()
	deadline := start.Add(c.pollTimeout())
	freshFloor := start.Add(-c.freshWindow())

	for c.now().Before(deadline) {
		if info, err := os.Stat(c.StatePath); err == nil && !info.ModTime().Before(freshFloor) {
			return true, true
		}
		select {
		case <-ctx.Done():
			return false, c.cacheExists()
		case <-time.After(c.pollInterval()):
		}
	}

	// Serveur injoignable au logon (ou fetch en retard) : la session vit
	// sur le DERNIER état connu — le cycle du service rattrapera.
	return false, c.cacheExists()
}

func (c *Companion) cacheExists() bool {
	_, err := os.Stat(c.StatePath)

	return err == nil
}

// RunPass : UNE passe — lecture du cache, partition, convergence,
// applied-state, drop. Retourne (true, nil) si une passe a tourné.
func (c *Companion) RunPass() (bool, error) {
	raw, err := os.ReadFile(c.StatePath)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			c.Log.Debugf("Cache absent (%s) : pas de passe.", c.StatePath)

			return false, nil
		}
		// Course assumée (review 24.3 #4) : le fetch peut renommer
		// state.json pendant la lecture — loggée par l'appelant, re-tentée
		// au tick suivant de la boucle résidente.
		return false, err
	}

	state, err := ParseState(raw)
	if err != nil {
		return false, err
	}

	// Partition des portées (piège n° 3) — JAMAIS de recouvrement :
	//   service SYSTEM  → machine SEULEMENT ;
	//   compagnon (ici) → session + machine_user SEULEMENT.
	if n := len(state.Machine); n > 0 {
		c.Log.Debugf("Portée machine ignorée (%d item(s)) : exclusivité du service SYSTEM.", n)
	}

	// Ordre SERVEUR (FR18) : items de la portée session puis machine_user,
	// chacun dans l'ordre du payload — jamais d'ordre inventé.
	items := ItemsFromScope(state.Session, c.Log)
	items = append(items, ItemsFromScope(state.MachineUser, c.Log)...)

	// Dernier-appliqué PER-USER (mode default §5) — JAMAIS le fichier
	// machine sous ProgramData (ACL SYSTEM, et le dernier-appliqué d'un item
	// session est propre à CHAQUE user). Corrompu = repart sans mémoire
	// (premier passage §5 : jamais interprété comme une dérive humaine).
	applied, corrupted := ReadAppliedState(c.User.AppliedStatePath())
	if corrupted {
		c.Log.Warningf("applied-state.json corrompu : repart sans mémoire (premier passage §5).")
	}

	reportItems := c.Engine.RunPass(items, applied)

	if err := c.User.EnsureRoot(); err != nil {
		c.Log.Warningf("Préparation du profil agent en échec : %v", err)
	} else if err := WriteAppliedState(c.User.AppliedStatePath(), applied); err != nil {
		c.Log.Warningf("Persistance de l'applied-state per-user en échec : %v", err)
	}

	c.writeDrop(reportItems)

	c.Log.Infof("Passe compagnon terminée : %d item(s) traité(s), %d statut(s) (generated_at=%s).",
		len(items), len(reportItems), state.GeneratedAt)

	return true, nil
}

// writeDrop écrit session-report.json dans le drop per-SID — la SEULE
// écriture du compagnon hors %LOCALAPPDATA% (ACL <SID>:M posée par SYSTEM).
// Répertoire absent (install pas à niveau, fetch pas encore passé) : log +
// skip — la convergence locale a EU lieu, seul le rapport attendra que le
// service ait créé le drop (cycle suivant).
func (c *Companion) writeDrop(items []ReportItem) {
	if _, err := os.Stat(c.DropDir); err != nil {
		c.Log.Warningf("Répertoire de drop absent (%s) : résultats non déposés (le fetch SYSTEM le créera — rapport au cycle suivant).", c.DropDir)

		return
	}

	raw, err := BuildSessionReportDrop(c.now().UTC().Format(time.RFC3339), items)
	if err != nil {
		c.Log.Errorf("Sérialisation du drop en échec : %v", err)

		return
	}
	if err := WriteFileAtomic(c.DropPath, raw); err != nil {
		c.Log.Warningf("Dépôt du drop en échec : %v", err)

		return
	}
	c.Log.Debugf("Drop déposé : %d item(s) de rapport (%s).", len(items), c.DropPath)
}

// Run : vie du compagnon — passe initiale après attente bornée du cache,
// puis boucle RÉSIDENTE (re-convergence quand le cache change + re-test
// périodique level-triggered). Sortie propre sur ctx (fin de session : le
// processus meurt avec elle). Aucune sortie n'est jamais visible du user.
func (c *Companion) Run(ctx context.Context) {
	c.Log.Infof("Compagnon de session démarré (sid=%s, agent %s) — après ouverture de session, jamais dans son chemin synchrone (NFR1). Boucle résidente (poll %d s, re-test %d s).",
		c.SID, Version, int(c.cachePoll()/time.Second), int(c.periodicPass()/time.Second))

	fresh, exists := c.WaitForCache(ctx)
	switch {
	case !exists:
		// Premier logon hors-ligne d'un user sans cache : on RESTE résident
		// (le cycle du service peut écrire le cache mid-session) mais en
		// silence — AUCUN message visible (décision 24.4 n° 7).
		c.Log.Infof("Aucun cache de session (%s) après %d s : attente résidente, convergence dès qu'un cache apparaît.",
			c.StatePath, int(c.pollTimeout()/time.Second))
	case !fresh:
		c.Log.Warningf("Pas de cache frais dans le délai (serveur injoignable au logon ?) : convergence sur le DERNIER cache connu.")
	}

	var lastSeenWrite time.Time
	var lastPassAt time.Time

	for {
		// Story 27.1bis (D5) : watchdog Rainmeter — relance le rendu s'il a
		// disparu (idempotent, borné). Évalué AVANT la convergence du cache
		// pour que l'overlay réapparaisse vite après un kill. nil = inerte.
		if c.Watchdog != nil {
			c.Watchdog.Tick()
		}

		var currentWrite time.Time
		if info, err := os.Stat(c.StatePath); err == nil {
			currentWrite = info.ModTime()
		}

		stateChanged := !currentWrite.IsZero() && !currentWrite.Equal(lastSeenWrite)
		periodicDue := c.now().Sub(lastPassAt) >= c.periodicPass()

		if stateChanged || (periodicDue && !currentWrite.IsZero()) {
			if ran, err := c.RunPass(); err != nil {
				// Une passe ratée (cache en cours de rename, parse) ne tue
				// JAMAIS la boucle : log + retry au tick suivant (pas de
				// retry agressif en boucle serrée).
				c.Log.Errorf("Passe compagnon en échec : %v — nouvel essai au prochain tick.", err)
				lastPassAt = c.now()
			} else if ran {
				lastSeenWrite = currentWrite
				lastPassAt = c.now()
			}
		}

		select {
		case <-ctx.Done():
			c.Log.Infof("Fin de session : sortie propre du compagnon.")

			return
		case <-time.After(c.cachePoll()):
		}
	}
}
