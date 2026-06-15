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

	// MAC : fournisseur de l'adresse MAC de l'adaptateur actif, injecté par le
	// binaire Windows (Story 25.4) — ancre fiable de rapprochement de la
	// demande d'enrôlement porte 2 (le serveur normalise via
	// MacAddressNormalizer). nil = MAC vide (la demande reste traçable mais non
	// auto-approuvable — piège n° 5 : jamais de MAC inventée en silence). N'est
	// utilisé que sur le chemin d'auto-enroll (token absent) ; le flux nominal
	// ne l'envoie pas (X-Agent-Mac volontairement non posé, décision 24.2).
	MAC func() string

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

	// UpdateACL : ACL du répertoire de staging d'auto-update (update\, SYSTEM
	// F + Admins F, PAS de Users:R — Story 25.2). Injectée par le binaire
	// Windows (icacls). nil = no-op (tests hôte Linux).
	UpdateACL func(path string) error

	// Rainmeter : store de l'outil de rendu (C:\ProgramData\SambaEdu\Rainmeter)
	// — provisioning portable au bootstrap + pose de la config verrouillée
	// (Story 27.1bis). nil = provisioning INERTE (tests, !windows : l'agent ne
	// pose jamais Rainmeter sur une plateforme sans outil de rendu).
	Rainmeter *RainmeterStore

	// RainmeterACL : ACL de l'arbre Rainmeter (Users:R, SYSTEM/Admins full —
	// setAssetsACL réutilisé, Story 27.1bis). Injectée par le binaire Windows.
	// nil = no-op (tests hôte Linux).
	RainmeterACL func(path string) error

	// Primitives Windows de l'auto-update (Story 25.2, décision n° 2),
	// injectées par le binaire Windows (newAgent) iso AssetsACL — nil sur
	// !windows ET en test, l'orchestration shared/ se teste avec des stubs :
	//   - VerifyAuthenticode : vérifie la signature Authenticode du binaire
	//     STAGÉ AVANT tout swap (WinVerifyTrust ; erreur = binaire jeté) ;
	//   - SwapAndRestart : swap atomique anti-brique (shared.PerformSwap :
	//     copie-atomique→re-hash→rename→rollback) PUIS sortie non-gracieuse
	//     os.Exit(≠0) sur succès → la recovery SCM relance le binaire vN+1
	//     (Option A, décision review 25.2). `expectedHash` = hash manifest,
	//     re-vérifié sur le binaire RÉELLEMENT mis en place (M2). Erreur =
	//     anti-brique, ancien binaire en place ; pas d'erreur = le process est
	//     en train de mourir (os.Exit), le reste du cycle n'a pas lieu.
	// nil = update INERTE (no-op silencieux) : l'auto-update ne tourne qu'en
	// service Windows réel — sur une plateforme sans ces primitives, l'agent
	// ne tente jamais de se remplacer (un binaire Linux n'a pas de SCM).
	VerifyAuthenticode func(path string) error
	SwapAndRestart     func(stagedPath, version, expectedHash string) error

	// Rand : source de jitter injectable (tests). nil = math/rand global.
	Rand *rand.Rand

	// quarantined : 403 AGENT_QUARANTINED → check-ins légers (GET /state à
	// cadence normale, plus de POST /report ni de traitement d'état) ;
	// levée AUTOMATIQUE au premier 200/304. PROCESS-LOCAL, jamais persisté.
	quarantined bool

	// pendingUpdateError : message d'échec du DERNIER cycle d'auto-update
	// (Story 25.2, décision n° 7) — vidé dans le `BuildReport` du même cycle
	// sous forme d'un item `agent_update` status `error`. PROCESS-LOCAL, jamais
	// persisté : un échec se rapporte une fois ; le cycle suivant retentera et
	// re-posera l'item s'il échoue à nouveau. La RÉUSSITE ne pose pas d'item
	// (la nouvelle `agent_version` du rapport EST la preuve de succès, AC4).
	pendingUpdateError string

	// serverTtl : dernier `ttl_seconds` servi par le serveur (enveloppe
	// /state, source AGENT_STATE_TTL_SECONDS côté SE5). 0 = jamais vu →
	// cadence locale (config.json). Mis à jour sur chaque 200 parsé, amorcé
	// depuis le cache d'état au démarrage (le GET nominal d'un service
	// redémarré répond 304 et ne re-livre pas l'enveloppe).
	serverTtl int64
}

// Bornes de la cadence pilotée serveur : plancher anti-martèlement (un ttl
// accidentel de 1 s × ~600 postes saturerait le throttle 60/min), plafond
// anti-extinction (un ttl aberrant ne doit pas rendre le parc « muet » des
// jours — la dérivation serveur muet = 2 × ttl resterait cohérente, mais
// plus aucune demande « forcer la synchro » ne serait servie à temps).
const (
	MinServerIntervalSeconds = 60
	MaxServerIntervalSeconds = 86400
)

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
	// Story 25.4 (Fork 1 = B) : token ABSENT → demande d'enrôlement porte 2,
	// PAS un échec de cycle. Un poste migré (chemin GPO-dispatcher figée) est
	// installé sans token : il s'auto-enrôle, puis converge dès l'approbation.
	// Un token PRÉSENT mais corrompu reste un échec de cycle (backoff) côté
	// ReadToken ci-dessous — un poste enrôlé ne se ré-enrôle JAMAIS auto (FR22).
	if !a.Store.TokenExists() {
		return a.runEnrollment(cfg)
	}

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
		if st, err := ParseState(resp.Body); err != nil {
			a.Log.Errorf("État reçu refusé (%v) : cache local préservé, check-ins maintenus.", err)
		} else {
			a.noteServerTtl(st.TtlSeconds)
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
		// Story 27.1bis : provisioning de l'outil de rendu Rainmeter au
		// BOOTSTRAP du cycle SYSTEM (portable install-if-absent + config
		// verrouillée), JAMAIS depuis un handler runtime (« handler jamais
		// installeur » — D3). Idempotent ; un échec ne casse pas le cycle
		// (rattrapage au prochain passage, comme le sync wallpaper).
		a.SyncRainmeterTool(cfg)
		// Story 25.2 : auto-update en fin de portée machine, AVANT le rapport
		// (l'item agent_update d'un échec rejoint le POST /report du cycle). Un
		// succès remplace le binaire puis provoque une SORTIE NON-GRACIEUSE du
		// process (os.Exit(≠0), Option A) : la recovery SCM relance le binaire
		// vN+1. Le POST /report ci-dessous n'a alors pas lieu pour CE cycle —
		// c'est l'image vN+1 qui rapportera la nouvelle version (preuve de
		// succès, AC4). Un échec laisse l'agent en place (anti-brique) : le
		// rapport part normalement avec l'item d'échec. Un 403 sur le canal
		// release ne met PAS le poste en quarantaine globale (M4) : il saute
		// seulement l'update, le report ci-dessous a bien lieu.
		a.SelfUpdate(cfg)
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
	// Items réels = drops session collectés/validés + un éventuel item
	// agent_update (échec d'auto-update du cycle, Story 25.2 — vidé ici, un
	// échec se rapporte une fois).
	items := CollectSessionReports(a.Store, a.Log)
	items = append(items, a.drainUpdateReportItems()...)
	reportBody, err := BuildReport(a.Hostname, uuid, items, time.Now())
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

// runEnrollment : cycle « token absent » — demande d'enrôlement porte 2 (Story
// 25.4, Fork 1 = B). Construit le faisceau {uuid, mac, hostname}, poste SANS
// bearer, interprète la réponse serveur (25.3) :
//
//   - EnrollApproved : token reçu → écriture ATOMIQUE (ACL SID via Store) ; le
//     PROCHAIN cycle relira le token et basculera en convergence (GET /state…).
//     OutcomeOK = cadence normale (pas de spin : la convergence démarre au
//     cycle suivant) ;
//   - EnrollPending (403) : demande enregistrée (ou poste rejeté) → check-ins
//     légers à cadence normale, OutcomeOK, JAMAIS de backoff agressif ni
//     d'escalade (anti-boucle iso quarantaine) ;
//   - EnrollConflict (409) : MAC d'un poste déjà enrôlé → log + cadence normale,
//     OutcomeOK, JAMAIS de ré-enrôlement automatique silencieux ;
//   - EnrollError : réseau KO / 5xx / réponse inattendue → OutcomeBackoff.
//
// Aucune primitive Windows : entièrement testable sur l'hôte (le faisceau vient
// des providers injectés UUID/MAC + Hostname, le POST passe par le Client HTTP).
func (a *Agent) runEnrollment(cfg Config) Outcome {
	identity := EnrollIdentity{
		UUID:     a.collectUUID(),
		MAC:      a.collectMAC(),
		Hostname: a.Hostname,
	}
	if identity.MAC == "" {
		// Piège n° 5 : une demande sans MAC est traçable mais jamais
		// auto-approuvable (ancre de rapprochement absente). On la poste quand
		// même (l'admin peut approuver manuellement) mais on le signale.
		a.Log.Warningf("Demande d'enrôlement porte 2 sans MAC : la demande sera tracée mais non auto-approuvable (rapprochement impossible).")
	}

	token, outcome := requestEnrollment(a.Client, cfg.ServerURL, identity)

	switch outcome {
	case EnrollApproved:
		if err := a.Store.WriteToken(token); err != nil {
			a.Log.Errorf("Token d'enrôlement reçu mais écriture sur disque en échec : %v — nouvel essai au prochain cycle.", err)

			return OutcomeBackoff
		}
		a.Log.Infof("Enrôlement porte 2 approuvé : token écrit sur disque, bascule en convergence au prochain cycle.")

		return OutcomeOK
	case EnrollPending:
		a.Log.Infof("Enrôlement porte 2 en attente d'approbation (ou poste non éligible) : check-ins légers, nouvel essai à cadence normale.")

		return OutcomeOK
	case EnrollConflict:
		a.Log.Warningf("Enrôlement porte 2 en conflit (l'ancre MAC matche un poste déjà enrôlé) : aucun ré-enrôlement automatique — résolution serveur/admin requise. Check-ins légers.")

		return OutcomeOK
	default:
		a.Log.Warningf("Demande d'enrôlement porte 2 en échec (serveur injoignable ou réponse inattendue) : backoff.")

		return OutcomeBackoff
	}
}

// collectUUID : UUID SMBIOS verbatim (champ déclaratif) ; chaîne vide admise.
func (a *Agent) collectUUID() string {
	if a.UUID == nil {
		return ""
	}

	return a.UUID()
}

// collectMAC : MAC de l'adaptateur actif ; chaîne vide admise (jamais inventée,
// piège n° 5).
func (a *Agent) collectMAC() string {
	if a.MAC == nil {
		return ""
	}

	return a.MAC()
}

// noteServerTtl retient le `ttl_seconds` servi (ignore 0/absent : une
// enveloppe sans ttl ne doit pas faire retomber la cadence pilotée).
func (a *Agent) noteServerTtl(ttl int64) {
	if ttl > 0 {
		a.serverTtl = ttl
	}
}

// primeServerTtlFromCache amorce serverTtl depuis le dernier état caché.
// Sans cet amorçage, un service redémarré repartirait sur la cadence locale
// jusqu'au prochain changement d'état serveur (son GET nominal répond 304,
// l'enveloppe — et son ttl — ne sont pas re-livrés). Cache absent ou
// illisible = no-op silencieux (premier 200 fera foi).
func (a *Agent) primeServerTtlFromCache() {
	raw, err := a.Store.ReadStateCache()
	if err != nil {
		return
	}
	if st, err := ParseState(raw); err == nil {
		a.noteServerTtl(st.TtlSeconds)
	}
}

// EffectiveInterval : cadence du prochain cycle. Priorité au `ttl_seconds`
// serveur (clampé [MinServerIntervalSeconds, MaxServerIntervalSeconds]) ;
// repli sur `interval_seconds` de la config locale (déjà défaillé à 3600 par
// ReadConfig) tant qu'aucune enveloppe n'a été vue.
func (a *Agent) EffectiveInterval(cfg Config) time.Duration {
	if a.serverTtl > 0 {
		ttl := a.serverTtl
		if ttl < MinServerIntervalSeconds {
			ttl = MinServerIntervalSeconds
		}
		if ttl > MaxServerIntervalSeconds {
			ttl = MaxServerIntervalSeconds
		}

		return time.Duration(ttl) * time.Second
	}

	return time.Duration(cfg.IntervalSeconds) * time.Second
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
// Cadence : `ttl_seconds` serveur quand il est connu (voir EffectiveInterval),
// sinon `interval_seconds` local.
func (a *Agent) Run(ctx context.Context) {
	a.Log.Infof("SambaEdu Agent %s démarré (hostname=%s).", Version, a.Hostname)
	a.primeServerTtlFromCache()

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
			outcome = a.RunCycle(cfg)
			// APRÈS le cycle : un ttl appris sur le 200 de CE cycle
			// s'applique dès le sleep qui suit.
			interval = a.EffectiveInterval(cfg)
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
