package shared

import (
	"context"
	"math/rand"
	"strings"
	"time"
)

// Outcome : résultat d'un cycle de convergence.
type Outcome int

const (
	// OutcomeOK : cycle nominal → attente cadence normale + jitter.
	OutcomeOK Outcome = iota
	// OutcomeBackoff : serveur injoignable / 5xx / 429 → backoff exponentiel.
	OutcomeBackoff
	// OutcomeStop : 401 irrécupérable → ARRÊT + log local, JAMAIS de
	// re-enrôlement automatique (intervention admin).
	OutcomeStop
)

// Agent : boucle de convergence portée machine (service SYSTEM).
//
// Cycle (1 itération, iso-24.2) :
//  1. token relu sur disque (la rotation peut l'avoir changé) ;
//  2. GET /api/v1/agent/state avec If-None-Match (ETag verbatim) ;
//  3. 200 → persister cache\state.json + cache\etag.txt ; 304 → cache valide ;
//  4. construire le rapport (hostname COURT, uuid SMBIOS, items: [] en 24.5) ;
//  5. POST /api/v1/agent/report ;
//  6. attendre (intervalle 3600 s + jitter ±10 %, ou backoff).
type Agent struct {
	Store  *Store
	Client *Client
	Log    *Logger

	// Hostname : nom COURT du poste (defer 24.1 #8 — jamais le FQDN).
	Hostname string

	// UUID : fournisseur d'UUID SMBIOS, injecté par le binaire Windows
	// (shell-out PowerShell Get-CimInstance) ; une chaîne vide est admise
	// (champ déclaratif — l'identité réelle est le token).
	UUID func() string

	// Sessions : énumérateur des sessions interactives (WTS côté Windows,
	// liste blanche ^S-1-5-21- + login non vide — Story 24.6). nil = aucun
	// fetch de session (tests, plateformes sans sessions).
	Sessions func() ([]Session, error)

	// ACL injectées par le binaire Windows (icacls), posées À LA CRÉATION
	// des répertoires — nil = no-op (tests hôte) :
	//   - SessionCacheACL  : cache\sessions\<SID>\  (<SID>:R) ;
	//   - SessionReportACL : reports\sessions\<SID>\ (<SID>:M) ;
	//   - AssetsACL        : assets\ (Users:R).
	SessionCacheACL  SessionACL
	SessionReportACL SessionACL
	AssetsACL        func(path string) error

	// Rand : source de jitter injectable (tests). nil = math/rand global.
	Rand *rand.Rand

	// quarantined : 403 AGENT_QUARANTINED → check-ins légers (GET /state à
	// cadence normale, plus de POST /report ni de traitement d'état) ;
	// levée AUTOMATIQUE au premier 200/304. PROCESS-LOCAL, jamais persisté.
	quarantined bool
}

// Quarantined expose l'état de quarantaine (tests + diagnostics).
func (a *Agent) Quarantined() bool { return a.quarantined }

// RunCycle exécute un cycle complet. Toute panique est rattrapée : un agent
// ne crashe jamais silencieusement (AC2) — log + backoff.
func (a *Agent) RunCycle(cfg Config) (outcome Outcome) {
	defer func() {
		if r := recover(); r != nil {
			a.Log.Errorf("Cycle en échec (panique rattrapée) : %v", r)
			outcome = OutcomeBackoff
		}
	}()

	return a.runCycle(cfg)
}

func (a *Agent) runCycle(cfg Config) Outcome {
	// 1. Token relu sur disque À CHAQUE cycle (contrat 23.3).
	token, err := a.Store.ReadToken()
	if err != nil {
		a.Log.Errorf("Cycle en échec : %v", err)

		return OutcomeBackoff
	}
	a.Client.SetToken(token)

	if err := a.Store.EnsureLayout(); err != nil {
		a.Log.Errorf("Préparation du cache locale en échec : %v", err)

		return OutcomeBackoff
	}

	// 2. GET /state avec If-None-Match (ETag verbatim du cache).
	stateURL := cfg.ServerURL + "/api/v1/agent/state"
	resp, err := a.Client.Get(stateURL, a.Store.ReadEtag())
	if err != nil {
		a.Log.Warningf("Serveur injoignable sur GET /state : %v", err)

		return OutcomeBackoff
	}

	switch resp.StatusCode {
	case 200:
		// Refus d'un major inconnu (§9) : log erreur, cache PRÉSERVÉ, les
		// check-ins CONTINUENT à cadence normale (piège n° 10) — le rapport
		// part quand même (signal de vie).
		if _, err := ParseState(resp.Body); err != nil {
			a.Log.Errorf("État reçu refusé (%v) : cache local préservé, check-ins maintenus.", err)
		} else {
			newEtag := resp.Header.Get(headerETag)
			if newEtag != "" {
				// 3. Persister cache + ETag (VERBATIM, guillemets inclus).
				if err := a.Store.WriteStateCache(resp.Body, newEtag); err != nil {
					a.Log.Warningf("Persistance du cache d'état en échec : %v", err)
				}
			}
			a.Log.Infof("GET /state -> 200 : état cible rafraîchi en cache.")
		}
		a.liftQuarantine()
	case 304:
		a.Log.Debugf("GET /state -> 304 : cache local valide, état inchangé.")
		a.liftQuarantine()
	case 401:
		a.Log.Errorf("401 irrécupérable sur GET /state (token courant ET fenêtre de grâce refusés). ARRÊT du service — re-enrôlement MANUEL requis par un admin, jamais automatique.")

		return OutcomeStop
	case 403:
		a.enterQuarantine("GET /state")

		return OutcomeOK // cadence normale, PAS de backoff agressif sur 403
	case 429:
		a.Log.Warningf("GET /state -> 429 (throttle serveur) : backoff.")

		return OutcomeBackoff
	default:
		a.Log.Warningf("GET /state -> %d inattendu : backoff.", resp.StatusCode)

		return OutcomeBackoff
	}

	// Story 24.6 (décision 24.3 n° 4 conservée) : après la portée machine,
	// le cycle rafraîchit aussi les caches de session (IN-PROCESS, même code
	// que la tâche at-logon — fraîcheur laxe NFR3 : logon + timer) puis
	// synchronise les assets wallpaper (AUSSI hors fetch : zéro session
	// interactive = pré-téléchargement avant le premier logon ; idempotent,
	// content-addressed). Une erreur ici ne casse JAMAIS le cycle machine —
	// les Outcome restent ceux de la portée machine.
	if !a.quarantined {
		a.fetchSessionStates(cfg)
		a.SyncWallpaperAssets(cfg)
	}

	// Garde défensive : pas de rapport tant que la quarantaine est active —
	// elle peut aussi être TOMBÉE pendant le fetch de session (403).
	if a.quarantined {
		return OutcomeOK
	}

	// 4. Rapport — hostname COURT + UUID SMBIOS verbatim ; items RÉELS =
	// drops session collectés/validés (24.6). Le rapport part MÊME sur 304 :
	// état inchangé = on rapporte quand même (signal de vie).
	uuid := ""
	if a.UUID != nil {
		uuid = a.UUID()
	}
	if uuid == "" || isPlaceholderUUID(uuid) {
		a.Log.Warningf("UUID SMBIOS vide ou placeholder firmware (%q) : le champ workstation.uuid du rapport n'est pas fiable (warnings identity_mismatch possibles côté serveur).", uuid)
	}
	// Items réels du rapport : collecte + validation stricte des drops
	// session (latence ≤ 1 cycle entre convergence session et rapport,
	// NFR3 — « forcer la synchro » = 24.7). Aucun drop = items: [] (valide).
	reportBody, err := BuildReport(a.Hostname, uuid, CollectSessionReports(a.Store, a.Log), time.Now())
	if err != nil {
		a.Log.Errorf("Construction du rapport en échec : %v", err)

		return OutcomeBackoff
	}

	// 5. POST /report.
	resp, err = a.Client.Post(cfg.ServerURL+"/api/v1/agent/report", reportBody)
	if err != nil {
		a.Log.Warningf("Serveur injoignable sur POST /report : %v", err)

		return OutcomeBackoff
	}

	switch resp.StatusCode {
	case 200:
		a.Log.Infof("POST /report -> 200 : rapport accepté, boucle fermée.")

		return OutcomeOK
	case 401:
		a.Log.Errorf("401 irrécupérable sur POST /report. ARRÊT du service — re-enrôlement MANUEL requis.")

		return OutcomeStop
	case 403:
		a.enterQuarantine("POST /report")

		return OutcomeOK
	case 429:
		a.Log.Warningf("POST /report -> 429 (throttle serveur) : backoff.")

		return OutcomeBackoff
	default:
		a.Log.Warningf("POST /report -> %d inattendu : backoff.", resp.StatusCode)

		return OutcomeBackoff
	}
}

func (a *Agent) enterQuarantine(during string) {
	if !a.quarantined {
		a.quarantined = true
		a.Log.Warningf("AGENT_QUARANTINED (403) sur %s : passage en check-ins légers — GET /state continue à cadence normale, plus aucun POST /report ni traitement d'état tant que la quarantaine n'est pas levée.", during)
	}
}

func (a *Agent) liftQuarantine() {
	if a.quarantined {
		a.quarantined = false
		a.Log.Infof("Quarantaine levée par le serveur : reprise du cycle complet.")
	}
}

// isPlaceholderUUID : UUID SMBIOS non fiable sur certains firmwares (tout-F,
// tout-0) — envoyé quand même (champ déclaratif), tracé localement.
func isPlaceholderUUID(uuid string) bool {
	u := strings.ToLower(uuid)

	return u == "ffffffff-ffff-ffff-ffff-ffffffffffff" ||
		u == "00000000-0000-0000-0000-000000000000"
}

// NextBackoff : backoff exponentiel 30 s → ×2 → plafonné à la cadence
// normale (FR22 — jamais de retry agressif sur un serveur qui redémarre).
// current = 0 signifie « pas de backoff en cours ».
func NextBackoff(current, interval time.Duration) time.Duration {
	if current <= 0 {
		return 30 * time.Second
	}
	next := current * 2
	if next > interval {
		return interval
	}

	return next
}

// Jitter : tirage entier uniforme symétrique sur ±10 % de l'intervalle
// (évite les vagues synchronisées sur ~600 postes, D7).
func (a *Agent) Jitter(interval time.Duration) time.Duration {
	jitterMax := int64(interval / 10 / time.Second)
	if jitterMax <= 0 {
		return 0
	}
	var n int64
	if a.Rand != nil {
		n = a.Rand.Int63n(2*jitterMax + 1)
	} else {
		n = rand.Int63n(2*jitterMax + 1)
	}

	return time.Duration(n-jitterMax) * time.Second
}

// Run : boucle principale — timer + jitter ±10 % (D7/FR23), backoff
// exponentiel (FR22), arrêt sur ctx (stop SCM) ou 401 irrécupérable.
func (a *Agent) Run(ctx context.Context) {
	a.Log.Infof("SambaEdu Agent %s démarré (hostname=%s).", Version, a.Hostname)

	backoff := time.Duration(0)
	for {
		interval := time.Duration(DefaultIntervalSeconds) * time.Second
		outcome := OutcomeBackoff

		cfg, err := a.Store.ReadConfig()
		if err != nil {
			// Défaut de config : log + retry — un agent ne crashe jamais
			// silencieusement (AC2).
			a.Log.Errorf("Cycle en échec : %v", err)
		} else {
			interval = time.Duration(cfg.IntervalSeconds) * time.Second
			outcome = a.RunCycle(cfg)
		}

		if outcome == OutcomeStop {
			a.Log.Errorf("Arrêt du service sur erreur d'authentification irrécupérable.")

			return
		}

		var sleep time.Duration
		if outcome == OutcomeBackoff {
			backoff = NextBackoff(backoff, interval)
			sleep = backoff
			a.Log.Infof("Prochain essai dans %d s (backoff exponentiel).", int(sleep/time.Second))
		} else {
			backoff = 0
			jitter := a.Jitter(interval)
			sleep = interval + jitter
			if sleep < time.Second {
				sleep = time.Second
			}
			a.Log.Debugf("Prochain cycle dans %d s (intervalle %d s, jitter %+d s).",
				int(sleep/time.Second), int(interval/time.Second), int(jitter/time.Second))
		}

		select {
		case <-ctx.Done():
			a.Log.Infof("Arrêt demandé (SCM) : sortie propre de la boucle.")

			return
		case <-time.After(sleep):
		}
	}
}
