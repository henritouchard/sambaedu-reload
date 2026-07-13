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

	// EnsureUserRainmeterIni : primitive injectée (Story 27.1ter, D2) — écrit
	// %APPDATA%\Rainmeter\Rainmeter.ini DURCI et WRITABLE (mode installé), au
	// démarrage du compagnon, AVANT le lancement de Rainmeter par le watchdog
	// (sinon Rainmeter lirait un .ini absent/ancien). Atomique + idempotent +
	// sans ACL (le fichier appartient à l'user). nil = no-op (tests hôte,
	// plateforme sans %APPDATA%/Rainmeter). Un échec est GRACIEUX (log, jamais de
	// blocage — NFR1).
	EnsureUserRainmeterIni func() error

	// Refresh : gestes de rafraîchissement de session (Story 43.1, D4) —
	// exécutés en TOUTE FIN de RunPass (après applied-state et drop, D5) : UN
	// seul geste par passe, le plus fort requis par les items EFFECTIVEMENT
	// changés (RefreshRequester des handlers), zéro geste si passe stable.
	// nil = no-op (tests hôte, non-Windows) — l'accumulation des handlers est
	// DRAINÉE quand même (pas de geste fantôme si l'ops apparaît plus tard).
	// Best-effort : un geste en échec = warning, JAMAIS une erreur de passe ni
	// un statut d'item (D4). Purement local : ni réseau ni token (NFR5).
	Refresh RefreshOps

	Log *Logger

	// Now : horloge injectable (tests). nil = time.Now.
	Now func() time.Time

	// NoticeLeadTime : délai de lecture entre l'affichage de la fenêtre
	// d'avertissement et le kill d'Explorer (Story 43.4, D5). <= 0 = défaut
	// restartNoticeLeadTime (~2 s). Injectable (tests) ; encouru UNIQUEMENT
	// sur la branche explorer_restart réellement exécutée.
	NoticeLeadTime time.Duration

	// lastExplorerRestart : horodatage du dernier explorer_restart TENTÉ par
	// CETTE instance (throttle anti-thrash, review 43.1 #1). En mémoire
	// seulement, jamais persisté : le thrash visé est INTRA-vie du compagnon
	// (drift récurrent re-convergé à chaque passe) — un redémarrage du
	// compagnon ré-arme légitimement le premier restart.
	lastExplorerRestart time.Time

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

func (c *Companion) pollInterval() time.Duration {
	return defaultDuration(c.PollInterval, 2*time.Second)
}
func (c *Companion) pollTimeout() time.Duration {
	return defaultDuration(c.PollTimeout, 60*time.Second)
}
func (c *Companion) freshWindow() time.Duration { return defaultDuration(c.FreshWindow, 5*time.Minute) }
func (c *Companion) cachePoll() time.Duration   { return defaultDuration(c.CachePoll, 60*time.Second) }
func (c *Companion) periodicPass() time.Duration {
	return defaultDuration(c.PeriodicPass, 5*time.Minute)
}
func (c *Companion) noticeLeadTime() time.Duration {
	return defaultDuration(c.NoticeLeadTime, restartNoticeLeadTime)
}

// explorerRestartMinInterval : intervalle MINIMAL entre deux explorer_restart
// par instance de Companion (review 43.1 #1, anti-thrash). En drift RÉCURRENT
// (une force externe réécrit une clé à CHAQUE passe : GPO tierce, antivirus,
// script legacy), le geste le plus fort partirait à chaque cycle de re-test
// (~5 min) → session cassée en boucle, à rebours de l'esprit NFR-A1. Dans la
// fenêtre d'interdiction, le geste est DÉGRADÉ en policy_broadcast + warning
// explicite. Le premier restart d'une instance n'est JAMAIS throttlé.
const explorerRestartMinInterval = 10 * time.Minute

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

	// Partition par EXÉCUTANT (Story 35.7, D4 — AVANT le moteur, engine.go
	// intouché) : les items porteurs du champ `writer` sont DÉLÉGUÉS au
	// service SYSTEM (trees HKCU\…\Policies\* en lecture seule pour
	// l'utilisateur standard sur poste joint au domaine — plus JAMAIS de
	// tentative user-context, plus d'« Accès refusé »). Skip GÉNÉRIQUE sur
	// PRÉSENCE du champ (tout type, valeur future inconnue incluse —
	// forward-compat, piège n°5) ; les items non marqués suivent le chemin
	// historique byte-identique. Conséquence 43.1 : ces items ne passent plus
	// par les handlers du compagnon — l'échelle de rafraîchissement n'en
	// reçoit plus rien (effet au logon suivant, comportement GPO user).
	companionItems, systemItems := SplitSystemWriterItems(items)
	if delegated := len(items) - len(companionItems); delegated > 0 {
		c.Log.Debugf("%d item(s) porteurs de `writer` écartés avant le moteur (dont %d writer=system, délégués au service SYSTEM).",
			delegated, len(systemItems))
	}
	items = companionItems

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

	// Échelle de rafraîchissement (Story 43.1, D5) : en TOUTE FIN de passe —
	// après applied-state et drop, pour qu'un SendMessageTimeout qui traîne ne
	// retarde ni la persistance ni le rapport. UN geste max par passe.
	c.runRefreshGesture()

	return true, nil
}

// runRefreshGesture : collecte le besoin de rafraîchissement accumulé par les
// handlers pendant la passe (RefreshRequester — interface optionnelle,
// consommée ICI et jamais par le moteur : engine.go zéro diff, D1) et exécute
// LE geste le plus fort. Passe stable (aucun item effectivement changé) =
// RefreshNone = aucun geste (NFR-A2, pas de « flicker »). Best-effort (D4) :
// échec = warning, la passe et le rapport sont déjà terminés.
func (c *Companion) runRefreshGesture() {
	// Toujours DRAINER l'accumulation (Take… remet à zéro), même sans ops
	// injectée : sinon un geste fantôme partirait à la passe suivante.
	level := RefreshNone
	for _, handler := range c.Engine.Handlers {
		if requester, ok := handler.(RefreshRequester); ok {
			level = maxRefreshLevel(level, requester.TakeRefreshRequest())
		}
	}
	if level == RefreshNone {
		return
	}
	if c.Refresh == nil {
		c.Log.Debugf("Geste de rafraîchissement %s requis mais aucune ops injectée (hôte/tests) : no-op.", level)

		return
	}

	switch level {
	case RefreshShellNotify:
		// SHChangeNotify n'a aucun retour exploitable (void) : succès supposé.
		c.Refresh.ShellNotify()
		c.Log.Infof("Rafraîchissement de session émis : shell_notify (SHChangeNotify — l'Explorer relit ses réglages de vue).")
	case RefreshPolicyBroadcast:
		if err := c.Refresh.PolicyBroadcast(); err != nil {
			c.Log.Warningf("Rafraîchissement policy_broadcast en échec : %v — les clés sont écrites, l'effet attendra le relogon (best-effort).", err)

			return
		}
		c.Log.Infof("Rafraîchissement de session émis : policy_broadcast (WM_SETTINGCHANGE \"Policy\").")
	case RefreshExplorerRestart:
		// Throttle anti-thrash (review 43.1 #1) : jamais deux restarts en
		// moins de explorerRestartMinInterval par instance — dans la fenêtre,
		// DÉGRADATION en policy_broadcast (les clés sont écrites ; au pire
		// l'effet plein attendra la fin de fenêtre ou le relogon).
		if !c.lastExplorerRestart.IsZero() && c.now().Sub(c.lastExplorerRestart) < explorerRestartMinInterval {
			c.Log.Warningf("Rafraîchissement explorer_restart requis mais THROTTLÉ (dernier restart < %s — drift récurrent probable : une force externe réécrit une clé HKCU à chaque passe ?) : dégradé en policy_broadcast.",
				explorerRestartMinInterval)
			if err := c.Refresh.PolicyBroadcast(); err != nil {
				c.Log.Warningf("Rafraîchissement policy_broadcast (dégradé) en échec : %v — les clés sont écrites, l'effet attendra le relogon (best-effort).", err)

				return
			}
			c.Log.Infof("Rafraîchissement de session émis : policy_broadcast (dégradé — explorer_restart throttlé).")

			return
		}
		// Horodaté à la TENTATIVE (même en échec : le shell a pu être tué) —
		// le throttle protège la session, pas le succès du geste.
		c.lastExplorerRestart = c.now()
		// Fenêtre d'avertissement (Story 43.4, D1/D2) : UNIQUEMENT ici — le
		// seul chemin qui atteint réellement RestartExplorer (jamais sur les
		// gestes faibles, jamais en passe stable, jamais sur le restart
		// throttlé→dégradé ci-dessus : aucune perturbation à couvrir). La
		// fenêtre vit dans le PROCESS du compagnon : elle SURVIT au kill
		// d'explorer.exe, et c'est dismiss() qui la ferme APRÈS le retour de
		// RestartExplorer (qui sonde déjà le retour du shell — poll ~3 s +
		// grâce 1 s). Best-effort ABSOLU (D4) : une notice en échec rend un
		// dismiss no-op côté impl — le restart part QUAND MÊME.
		shown, dismiss := c.Refresh.ShowRestartNotice(restartNoticeText)
		if dismiss == nil {
			dismiss = func() {} // défense : une impl rendant nil ne casse rien (D4)
		}
		// Bref délai de lecture (D5) — borné et constant : laisser lire le
		// message avant le clignotement de la barre des tâches. UNIQUEMENT si la
		// fenêtre s'est réellement affichée : pas de délai mort avant le kill
		// quand la création a échoué (review 43.4 #2).
		if shown {
			time.Sleep(c.noticeLeadTime())
		}
		err := c.Refresh.RestartExplorer()
		// Fermée APRÈS le retour du geste (shell revenu, D2) — y compris en
		// échec du restart : jamais de fenêtre orpheline. dismiss est
		// idempotent et borné (contrat ShowRestartNotice).
		dismiss()
		if err != nil {
			c.Log.Warningf("Rafraîchissement explorer_restart en échec : %v — les clés sont écrites, l'effet attendra le relogon (best-effort).", err)

			return
		}
		c.Log.Infof("Rafraîchissement de session émis : explorer_restart (Explorer relancé dans la session).")
	}
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

	// MODE INSTALLÉ (Story 27.1ter) : AVANT de lancer Rainmeter, on (ré)impose le
	// Rainmeter.ini per-user durci dans %APPDATA%\Rainmeter\ (writable, droits
	// user). Il DOIT exister avant le lancement du watchdog ci-dessous, sinon
	// Rainmeter lirait un .ini absent (Safe Start) ou ancien. Idempotent (réécrit
	// seulement si divergent). GRACIEUX (NFR1) : un échec est loggé en warning et
	// n'interrompt RIEN — le watchdog tente quand même (au pire les modales
	// reviennent, mais l'overlay rend). nil = no-op (tests hôte, non-Windows).
	if c.EnsureUserRainmeterIni != nil {
		if err := c.EnsureUserRainmeterIni(); err != nil {
			c.Log.Warningf("Écriture du Rainmeter.ini per-user (%%APPDATA%%) en échec : %v — le watchdog lance quand même Rainmeter (les modales peuvent réapparaître).", err)
		}
	}

	// Rendu overlay PROMPT au logon (levier A) : on lance le watchdog Rainmeter
	// AVANT l'attente du cache de convergence. overlay.json est déjà écrit par le
	// SERVICE SYSTEM au logon (overlay_logon) ; Rainmeter n'a besoin que d'être
	// lancé pour l'afficher — ça ne dépend PAS du cache per-SID qu'attend
	// WaitForCache. Sans ce Tick anticipé, l'overlay n'apparaît qu'APRÈS
	// WaitForCache (jusqu'à PollTimeout, ~60 s). Idempotent (relance seulement si
	// absent, back-off borné) : le Tick de la boucle résidente continue de
	// surveiller. Toujours hors du chemin synchrone du logon (NFR1 : le compagnon
	// est lancé par la tâche planifiée, pas dans la séquence de logon Windows).
	if c.Watchdog != nil {
		c.Watchdog.Tick()
	}

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
