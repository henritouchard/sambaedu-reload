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

	// wake : canal de réveil au logon (Story 27.9). Le handler SCM Windows y
	// poste un signal NON-BLOQUANT (RequestWake) au WTS_SESSION_LOGON ; la
	// boucle Run l'écoute dans son `select` de sieste pour partir sur un cycle
	// frais sans attendre le tick nominal. Bufferisé taille 1 → coalescence
	// naturelle (50 logons en rafale ⇒ au plus un signal en attente). Créé à la
	// CONSTRUCTION de l'Agent (newAgent / NewAgentForTest) — un canal nil = réveil
	// INERTE (no-op nil-safe : runConsole de debug, tests hôte Linux). Le
	// mécanisme est plateforme-agnostique : aucun import Windows dans shared/.
	wake chan struct{}

	// MachineEngine : moteur de convergence de la portée MACHINE (Story 27.3).
	// Le service SYSTEM est le SEUL acteur de la portée machine (le compagnon
	// l'ignore explicitement, NFR5) — il porte donc son propre moteur, distinct
	// de celui du compagnon (portées session/machine_user). Premier type machine
	// du canal agent : `registry` HKLM (ruche machine, droits SYSTEM). nil =
	// convergence machine INERTE (tests hôte sans handlers, console de debug,
	// plateformes sans registre) — le cycle réseau/cache/report continue.
	MachineEngine *Engine

	// SessionSystemOps : ops registre RÉELLES de la passe SYSTEM PAR-SESSION
	// (Story 35.7) — décorées PAR SID côté shared (sessionHiveOps : HKCU →
	// HKU\<SID>) pour appliquer les items `writer: "system"` des caches
	// per-session (trees HKCU\…\Policies\*, non écrivables par le compagnon).
	// Injectées par le binaire Windows (mêmes ops concrètes que le
	// MachineEngine). nil = passe INERTE (tests hôte via fake, console de
	// debug, plateforme sans registre) — patron MachineEngine/Rainmeter.
	SessionSystemOps RegistryOps

	// machineReportItems : items de rapport de la DERNIÈRE convergence machine
	// (Story 27.3), vidés dans le BuildReport du même cycle (le service est
	// in-process : pas de drop, contrairement au compagnon). PROCESS-LOCAL.
	// Story 35.7 : les verdicts de la passe SYSTEM par-session s'y AJOUTENT
	// (convergeSessionSystem) — MergeReportItemsByType fusionne avec le
	// verdict machine et les drops compagnon (pire statut gagne, types
	// uniques §6).
	machineReportItems []ReportItem

	// activeSIDs : ensemble des SID des sessions interactives VIVANTES
	// (Active+Disconnected) de la DERNIÈRE passe fetchSessionStates — réutilisé
	// par PurgeOrphanDrops (fix fantômes) pour purger les drops des sessions
	// terminées SANS seconde énumération WTS. nil = indéterminé (quarantaine /
	// pas d'énumérateur / échec) → purge fail-open ; map vide = zéro session
	// confirmée → purge légitime de tous les drops. PROCESS-LOCAL.
	activeSIDs map[string]bool
}

// NewAgentForTest construit un Agent avec son canal de réveil initialisé
// (Story 27.9) — réservé aux tests hôte qui pilotent le réveil. Le binaire
// Windows passe par newAgent (main_windows.go) qui initialise wake de la même
// manière. Le canal est bufferisé taille 1 (coalescence).
func NewAgentForTest(base Agent) *Agent {
	a := base
	a.wake = make(chan struct{}, 1)

	return &a
}

// InitWake initialise le canal de réveil bufferisé taille 1 (Story 27.9),
// appelé à la construction de l'Agent par le binaire Windows (newAgent) AVANT
// que la goroutine Run ne démarre. Idempotent : ne réinitialise pas un canal
// déjà créé (on ne veut jamais perdre un signal en vol). Sans cet appel, le
// canal reste nil → RequestWake est un no-op et le réveil est inerte (console
// de debug, plateformes sans sessions).
func (a *Agent) InitWake() {
	if a.wake == nil {
		a.wake = make(chan struct{}, 1)
	}
}

// RequestWake poste un signal de réveil NON-BLOQUANT sur le canal `wake`
// (Story 27.9). Appelé par le handler SCM au WTS_SESSION_LOGON. Invariants :
//   - nil-safe : un canal non initialisé (console, tests) = no-op silencieux,
//     jamais de send sur nil channel (qui bloquerait pour toujours) ;
//   - non-bloquant (`select … default`) : ne bloque JAMAIS le thread de
//     contrôle du service (Execute), même si la boucle est occupée (cycle en
//     vol, HTTP lent) ou si un réveil est déjà en attente ;
//   - coalescence : le buffer 1 + le `default` jettent les signaux en trop —
//     plusieurs logons rapprochés ⇒ au plus un réveil en file.
//
// Le debounce (fenêtre min-interval) est géré CÔTÉ BOUCLE (Run), thread unique
// propriétaire de l'instant « dernier cycle » : RequestWake ne fait que poster
// le signal, la boucle décide si elle l'honore.
func (a *Agent) RequestWake() {
	if a.wake == nil {
		return
	}
	select {
	case a.wake <- struct{}{}:
	default:
	}
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
		// Story 27.3 : convergence de la portée MACHINE (registre HKLM, droits
		// SYSTEM) sur le DERNIER cache d'état. AVANT le fetch des sessions et le
		// rapport (ses items machine rejoignent le POST /report du cycle). Un
		// échec ici ne casse JAMAIS le cycle (isolation par item dans le moteur ;
		// les autres types/portées continuent). Sur 304, le cache machine reste
		// valide → re-test level-triggered (drift réimposé).
		a.convergeMachine()
		a.fetchSessionStates(cfg)
		// Story 35.7 : passe SYSTEM PAR-SESSION — applique les items
		// `writer: "system"` (trees HKCU\…\Policies\*, non écrivables par le
		// compagnon) de chaque cache per-SID dans HKU\<SID>, GREFFÉE après le
		// fetch (même énumération WTS, jamais de second appel — piège n°12).
		// Ses verdicts rejoignent machineReportItems → POST /report du cycle.
		// Best-effort : une session en échec n'empêche ni les autres ni le
		// cycle ; quarantaine (même tombée PENDANT le fetch) = passe sautée.
		a.convergeSessionSystem()
		a.SyncWallpaperAssets(cfg)
		// Story 27.7 : pré-télécharge les icônes UPLOADÉES de raccourcis
		// content-addressed (GET HTTP statique sans token) AVANT la passe
		// compagnon qui pose les `.lnk` ; idempotent, un échec ne casse pas le
		// cycle (rattrapage au prochain passage, iso sync wallpaper).
		a.SyncShortcutIcons(cfg)
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
	// Fix fantômes de conformité : purger les drops des sessions TERMINÉES AVANT
	// de collecter. Sinon un drop mort (user délogué/reboot) survit sous
	// ProgramData et est re-rapporté en boucle (reported_at=now() ment « 6 min »).
	// L'ensemble autoritaire des SID vivants (Active+Disconnected) a été renseigné
	// par fetchSessionStates ci-dessus (MÊME énumération WTS — pas de second
	// appel). nil = indéterminé → PurgeOrphanDrops fail-open (aucune purge).
	PurgeOrphanDrops(a.Store, a.activeSIDs, a.Log)
	items := CollectSessionReports(a.Store, a.Log)
	items = append(items, a.drainUpdateReportItems()...)
	// Story 27.3 : items de la convergence MACHINE (registre HKLM) — in-process,
	// pas de drop. Drainés ici (un statut machine se rapporte au cycle où il a
	// convergé).
	items = append(items, a.machineReportItems...)
	a.machineReportItems = nil
	// Le contrat exige des types UNIQUES (§6). Le type `registry` peut arriver de
	// DEUX portées (HKLM machine via le service + HKCU session via le compagnon) :
	// on fusionne par type (pire statut gagne) pour ne jamais poster deux items du
	// même type (sinon l'ingestion serveur en écraserait un). No-op sur les types
	// déjà uniques (wallpaper/shortcuts/printers/drives/overlay/agent_update).
	items = MergeReportItemsByType(items)
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

// convergeMachine exécute une passe de convergence de la portée MACHINE (Story
// 27.3) sur le dernier cache d'état (state.json SYSTEM). Le service SYSTEM est le
// SEUL acteur de cette portée (le compagnon l'ignore). Les items de rapport sont
// stockés pour rejoindre le POST /report du cycle (in-process, pas de drop).
//
// Best-effort total : tout échec (cache absent/illisible, parse, persistance)
// est loggué sans casser le cycle (l'isolation par item vit déjà dans le moteur).
// MachineEngine nil = no-op (console de debug, tests hôte, plateforme sans
// registre).
func (a *Agent) convergeMachine() {
	a.machineReportItems = nil
	if a.MachineEngine == nil {
		return
	}

	raw, err := a.Store.ReadStateCache()
	if err != nil {
		a.Log.Debugf("Convergence machine sautée : cache d'état absent (%v).", err)

		return
	}
	state, err := ParseState(raw)
	if err != nil {
		a.Log.Warningf("Convergence machine sautée : cache d'état illisible (%v).", err)

		return
	}

	items := ItemsFromScope(state.Machine, a.Log)
	if len(items) == 0 {
		return // aucune règle machine = type absent (contrat §8) : rien à faire.
	}

	// Dernier-appliqué MACHINE sous ProgramData (ACL SYSTEM) — distinct du
	// per-user du compagnon. Corrompu = repart sans mémoire (premier passage §5,
	// jamais interprété comme une dérive humaine).
	applied, corrupted := ReadAppliedState(a.Store.AppliedStatePath())
	if corrupted {
		a.Log.Warningf("applied-state machine corrompu : repart sans mémoire (premier passage §5).")
	}

	a.machineReportItems = a.MachineEngine.RunPass(items, applied)

	// applied-state MACHINE : écriture atomique (WriteFileAtomic) sous le
	// répertoire racine ProgramData, déjà verrouillé SYSTEM+Admins / inheritance:r
	// (acl_windows.go) — le fichier (et son .tmp) héritent de cette ACL, jamais
	// lisibles par Users. On ne re-pose pas d'ACL par fichier (iso applied-state
	// per-user du compagnon) : le contenu n'est que des hashes/timestamps opaques.
	if err := WriteAppliedState(a.Store.AppliedStatePath(), applied); err != nil {
		a.Log.Warningf("Persistance de l'applied-state machine en échec : %v", err)
	}

	// items = nb de clés HKLM convergées ; machineReportItems = nb de verdicts de
	// rapport (un par TYPE, donc ≤ 1 pour `registry`).
	a.Log.Infof("Convergence machine terminée : %d clé(s), %d verdict(s) de type.", len(items), len(a.machineReportItems))
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
	// lastCycleStart : instant de début du DERNIER cycle (Story 27.9) — base du
	// debounce min-interval côté boucle (thread unique, aucune course avec le
	// SCM qui ne fait que poster sur `wake`). Tant qu'aucun cycle n'a démarré
	// (config illisible au boot), la valeur reste zero → time.Since(zero) >>
	// min-interval → un réveil est toujours honoré (bénin : le backoff borne
	// les re-tentatives, pas de spin).
	var lastCycleStart time.Time
	for {
		interval := time.Duration(DefaultIntervalSeconds) * time.Second
		outcome := OutcomeBackoff

		cfg, err := a.Store.ReadConfig()
		if err != nil {
			// Défaut de config : log + retry — un agent ne crashe jamais
			// silencieusement (AC2).
			a.Log.Errorf("Cycle en échec : %v", err)
		} else {
			lastCycleStart = time.Now()
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

		// Sieste interruptible : ctx.Done() (stop SCM), échéance nominale, ou
		// réveil au logon (Story 27.9). Le réveil ne touche NI la cadence
		// nominale, NI le jitter, NI le backoff (AC5) : il ne fait qu'écourter
		// la sieste, sous garde-fou debounce. On boucle ici pour pouvoir
		// re-siester le reliquat de debounce sans repartir en haut de boucle
		// (qui relancerait un cycle prématurément).
		if !a.sleepUntilDueOrWake(ctx, sleep, lastCycleStart) {
			a.Log.Infof("Arrêt demandé (SCM) : sortie propre de la boucle.")

			return
		}
	}
}

// sleepUntilDueOrWake exécute la sieste de fin de cycle en l'interrompant sur :
//   - ctx.Done() (stop SCM) → retourne false (la boucle Run doit sortir) ;
//   - l'échéance `sleep` (tick nominal / backoff) → retourne true (cycle frais) ;
//   - un signal de réveil au logon sur `a.wake` (Story 27.9) → si le debounce
//     l'autorise (>= MinLogonWakeIntervalSeconds depuis lastCycleStart) retourne
//     true (cycle frais immédiat) ; sinon (coalescence) re-siester le reliquat
//     du min-interval, BORNÉ par l'échéance nominale restante — le tick nominal
//     reste l'échéance de repli, le backoff n'est jamais réinitialisé.
//
// Le timer principal compte l'échéance nominale ABSOLUE : un réveil debouncé ne
// la repousse pas (un wake à t+1 s avec sleep=10 s ⇒ le cycle part toujours à
// t+10 s au plus tard). Toute la logique vit dans shared/ (testable hôte).
func (a *Agent) sleepUntilDueOrWake(ctx context.Context, sleep time.Duration, lastCycleStart time.Time) bool {
	deadline := time.Now().Add(sleep)
	timer := time.NewTimer(sleep)
	defer timer.Stop()

	for {
		select {
		case <-ctx.Done():
			return false
		case <-timer.C:
			// Échéance nominale / backoff atteinte : cycle frais.
			return true
		case <-a.wake:
			// Réveil au logon. Debounce côté boucle : on n'écourte la sieste que
			// si le min-interval s'est écoulé depuis le DÉBUT du dernier cycle.
			elapsed := time.Since(lastCycleStart)
			minWindow := time.Duration(MinLogonWakeIntervalSeconds) * time.Second
			if elapsed >= minWindow {
				a.Log.Infof("Réveil au logon : cycle de convergence frais lancé (%d s depuis le dernier cycle).", int(elapsed/time.Second))

				return true
			}
			// Debounce : trop tôt. On NE relance PAS de cycle ; on attend au
			// plus tôt l'expiration du min-interval, mais jamais au-delà de
			// l'échéance nominale déjà programmée (timer.C reste armé sur
			// `deadline`). Le signal est consommé (coalescence) ; un nouveau
			// logon re-postera au besoin.
			remaining := minWindow - elapsed
			debounceDeadline := lastCycleStart.Add(minWindow)
			if debounceDeadline.After(deadline) {
				// Le min-interval expire APRÈS l'échéance nominale : inutile de
				// l'attendre, le tick nominal arrivera avant. On laisse le timer
				// principal courir (rien à faire de plus).
				a.Log.Debugf("Réveil au logon ignoré (debounce) : seulement %d s depuis le dernier cycle, l'échéance nominale arrive avant la fin du min-interval.", int(elapsed/time.Second))

				continue
			}
			a.Log.Debugf("Réveil au logon coalescé (debounce) : %d s depuis le dernier cycle, ré-évaluation dans %d s.", int(elapsed/time.Second), int(remaining/time.Second))
			// Petite sieste bornée jusqu'à l'expiration du min-interval, tout en
			// gardant ctx.Done() et le timer nominal actifs.
			debounceTimer := time.NewTimer(remaining)
			select {
			case <-ctx.Done():
				debounceTimer.Stop()

				return false
			case <-timer.C:
				debounceTimer.Stop()

				return true
			case <-debounceTimer.C:
				// Le min-interval est désormais écoulé : un cycle frais part
				// (le logon avait bien demandé une convergence, on l'honore au
				// plus tôt sans avoir martelé).
				a.Log.Infof("Réveil au logon honoré après debounce : cycle de convergence frais lancé.")

				return true
			case <-a.wake:
				// Réveil supplémentaire pendant la fenêtre de debounce :
				// coalescé (au plus un cycle dans la fenêtre min-interval, AC3).
				debounceTimer.Stop()
				a.Log.Debugf("Réveil au logon supplémentaire coalescé pendant le debounce.")

				continue
			}
		}
	}
}
