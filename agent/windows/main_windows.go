// Binaire agent SambaEdu desired-state — Windows (Stories 24.5 + 24.6, Epic 24).
//
// Service SYSTEM (portée machine + broker des sessions) : boucle de check-in
// GET /state → cache → fetch des sessions + sync assets → POST /report
// (items réels = drops session collectés/validés). Le cœur (rotation D5,
// grâce, quarantaine, backoff, cache atomique, StateHasher, moteur §5,
// compagnon, collecte des drops) vit dans sambaedu/agent/shared —
// OS-agnostique et testé sur l'hôte. Ce package ne contient QUE le
// spécifique Win32 : protocole SCM (x/sys/windows/svc), ACL icacls, UUID
// SMBIOS, énumération WTS, registre HKCU + SystemParametersInfoW (FFI sans
// cgo), tâches planifiées.
//
// Sous-commandes :
//
//	agent.exe install -server-url http://<se5> [-interval 3600]
//	agent.exe uninstall [-purge]
//	agent.exe run            (mode console, debug)
//	agent.exe session-fetch  (tâche at-logon SYSTEM : GET /state?user= → cache per-SID)
//	agent.exe companion      (tâche at-logon Users : convergence session, résident)
//	agent.exe version
//
// Invariants (iso-24.2/24.3, contrat 23.3) :
//   - token : C:\ProgramData\SambaEdu\Agent\token (FIGÉ), relu à chaque cycle,
//     ILLISIBLE du compagnon (ACL SYSTEM+Administrators — le canal réseau
//     est 100 % SYSTEM, frontière NFR5) ;
//   - hostname COURT dans le rapport (os.Hostname() = COMPUTERNAME) ;
//   - NFR1 : rien dans le chemin synchrone du logon (tâches asynchrones) ;
//   - NFR7 : aucune dépendance AD/Kerberos/LDAP — l'auth EST le bearer token.
package main

import (
	"flag"
	"fmt"
	"os"

	"golang.org/x/sys/windows/svc"

	"sambaedu/agent/shared"
)

const (
	serviceName        = "SambaEduAgent"
	serviceDisplayName = "SambaEdu Agent (desired-state)"
	serviceDescription = "Agent SambaEdu SE5 : convergence état cible + rapport de conformité (Epic 24)."
)

func main() {
	isService, err := svc.IsWindowsService()
	if err != nil {
		fmt.Fprintf(os.Stderr, "détection du contexte service : %v\n", err)
		os.Exit(1)
	}
	if isService {
		if err := runService(); err != nil {
			os.Exit(1)
		}

		return
	}

	cmd := ""
	if len(os.Args) > 1 {
		cmd = os.Args[1]
	}

	switch cmd {
	case "install":
		fs := flag.NewFlagSet("install", flag.ExitOnError)
		serverURL := fs.String("server-url", "", "URL du serveur SE5 (obligatoire), ex. http://se5.mondomaine.lan")
		interval := fs.Int("interval", shared.DefaultIntervalSeconds, "cadence de check-in en secondes (D7, défaut 3600)")
		_ = fs.Parse(os.Args[2:])
		if *serverURL == "" {
			fmt.Fprintln(os.Stderr, "usage : agent.exe install -server-url http://<serveur-se5> [-interval 3600]")
			os.Exit(2)
		}
		exitOn(installService(*serverURL, *interval))
	case "uninstall":
		fs := flag.NewFlagSet("uninstall", flag.ExitOnError)
		purge := fs.Bool("purge", false, "efface aussi les données (token compris — re-enrôlement iPXE requis)")
		_ = fs.Parse(os.Args[2:])
		exitOn(uninstallService(*purge))
	case "run":
		// Mode console (debug) : même boucle que le service, log recopié
		// sur stderr. Ctrl-C pour arrêter.
		runConsole()
	case "session-fetch":
		// Tâche planifiée at-logon (SYSTEM) : un fetch des sessions + sync
		// des assets, puis sortie. Jamais d'erreur visible au logon : log
		// local + code retour (NFR1).
		runSessionFetchTask()
	case "companion":
		// Tâche planifiée at-logon (BUILTIN\Users) : processus RÉSIDENT aux
		// droits de la session — ni réseau, ni token (NFR5).
		if err := runCompanion(); err != nil {
			os.Exit(1)
		}
	case "version":
		fmt.Println(shared.Version)
	default:
		fmt.Fprintf(os.Stderr, "usage : agent.exe install|uninstall|run|session-fetch|companion|version\n")
		os.Exit(2)
	}
}

// runSessionFetchTask : point d'entrée de la tâche SambaEduAgent-SessionFetch
// (processus SYSTEM neuf à chaque logon — il ne connaît pas l'état
// quarantaine du service : il tente UN fetch, encaisse un éventuel 403 et
// s'arrête, asymétrie documentée session-companion.md §7).
func runSessionFetchTask() {
	agent := newAgent(false)
	cfg, err := agent.Store.ReadConfig()
	if err != nil {
		// Poste non installé/config corrompue : log + sortie silencieuse —
		// rien ne doit jamais bloquer un logon.
		agent.Log.Errorf("SessionStateFetch en échec : %v", err)
		os.Exit(1)
	}
	agent.RunSessionFetch(cfg)

	// Composition d'overlay.json DANS CE PROCESS, après que RunSessionFetch a
	// garanti l'écriture du cache per-SID (Story 27.1bis — correctif race
	// logon). L'évènement WTS_SESSION_LOGON du service (qui écrit aussi
	// overlay.json, idempotent) arrive avant que ce fetch réseau n'ait peuplé
	// le cache : OverlayDocumentForSession y voyait un cache absent → no-op, et
	// le logon-only ne rattrapait jamais. Ici le cache vient d'être écrit
	// séquentiellement → composition fiable. Best-effort, jamais bloquant.
	writeOverlayForAllSessions(agent.Store, os.Getenv("COMPUTERNAME"), agent.Log)
}

func exitOn(err error) {
	if err != nil {
		fmt.Fprintf(os.Stderr, "erreur : %v\n", err)
		os.Exit(1)
	}
}

// newAgent assemble la boucle shared avec les implémentations Windows :
// ACL icacls (iso-24.2 + per-SID/assets 24.6), UUID SMBIOS, hostname COURT,
// énumération WTS des sessions.
func newAgent(echo bool) *shared.Agent {
	store := &shared.Store{SetACL: setAgentACL}
	logger := &shared.Logger{Dir: store.LogsDir(), SetACL: setAgentACL, Echo: echo}

	// os.Hostname() sous Windows = GetComputerNameEx(ComputerNameDnsHostname),
	// le nom COURT du poste (jamais le FQDN) — règle defer 24.1 #8 : le
	// serveur compare ce champ à workstations.name.
	hostname, err := os.Hostname()
	if err != nil || hostname == "" {
		hostname = os.Getenv("COMPUTERNAME")
	}

	agent := &shared.Agent{
		Store:    store,
		Client:   shared.NewClient(store, logger, hostname),
		Log:      logger,
		Hostname: hostname,
		UUID:     smbiosUUID(logger),
		// Story 25.4 : ancre MAC du faisceau d'enrôlement porte 2 (auto-enroll
		// du poste migré). Utilisée seulement quand le token est absent.
		MAC:              macAddress(logger),
		Sessions:         enumerateInteractiveSessions,
		SessionCacheACL:  setSessionCacheACL,
		SessionReportACL: setSessionReportACL,
		AssetsACL:        setAssetsACL,
		// Story 25.2 : primitives d'auto-update Windows (vérif Authenticode +
		// swap atomique restart-SCM) + ACL SYSTEM du staging — l'orchestration
		// shared/ les injecte, nil en test/Linux (update inerte).
		UpdateACL:          setUpdateACL,
		VerifyAuthenticode: verifyAuthenticode,
		SwapAndRestart:     swapAndRestart,
		// Story 27.1bis : provisioning de l'outil de rendu Rainmeter au bootstrap
		// du cycle SYSTEM (portable install-if-absent + config verrouillée). ACL
		// dédiée Users:RX (Rainmeter.exe est lancé par le compagnon aux droits de
		// la session — R seul refuserait l'exécution) / SYSTEM+Admins full. nil en
		// test/Linux (provisioning inerte).
		Rainmeter:    rainmeterPortableStore(),
		RainmeterACL: setRainmeterACL,
		// Story 27.3 : moteur de convergence de la portée MACHINE (le service
		// SYSTEM en est le SEUL acteur — le compagnon ignore la portée machine,
		// NFR5). Premier type machine : `registry` HKLM (droits SYSTEM). UN seul
		// handler Go générique partagé avec le compagnon (côté HKCU) ; ici câblé
		// pour la ruche machine. logsDir = racine SYSTEM (companion a son log).
		MachineEngine: &shared.Engine{
			Handlers: map[string]shared.Handler{
				"registry": &shared.RegistryHandler{
					Ops: &registryOps{log: logger},
					Log: logger,
				},
				// Story 35.2 — listes registre a sous-valeurs indexees `\1..\N`
				// (contrat §7.6, reconciliation de cle-conteneur D3). Le SERVICE
				// SYSTEM reconcilie les conteneurs HKLM (ex. Forcelist Chrome/
				// Edge de pix_extension_forced) : ecrit 1..N dans l'ordre,
				// supprime les noms numeriques hors canon — jamais les valeurs
				// non numeriques, jamais la cle-conteneur.
				"registry_list": &shared.RegistryListHandler{
					Ops: &registryOps{log: logger},
					Log: logger,
				},
				// Fond de l'écran de VERROUILLAGE (exclusive / machine) : le
				// SERVICE SYSTEM impose l'image via PersonalizationCSP (HKLM —
				// le verrouillage est pré-login, LogonUI tourne en SYSTEM). Le
				// pendant `wallpaper` (fond de bureau) est SESSION/HKCU côté
				// compagnon. Même cache d'assets (SyncWallpaperAssets pré-télécharge
				// les deux types).
				"lockscreen": &lockscreenHandler{AssetsDir: store.AssetsDir()},
				// Story 27.4 — config d'app declarative (aggregate par app_kind /
				// scope MACHINE, correctif post-review 2026-06-17 review #1) : le
				// SERVICE SYSTEM pose le policies.json enterprise natif au chemin
				// d'install Firefox/Thunderbird (%ProgramFiles%\...\distribution\,
				// ecriture atomique). policies.json est machine-wide, admin-write
				// → SYSTEM ecrit (le compagnon user prenait ACCESS_DENIED). La
				// resolution serveur est PAR PARC (niveaux 1-4 : template + auto +
				// defaut etab + WG) ; le par-user de Firefox = le PROFIL (mecanisme
				// B / roaming, hors 27.4). UN SEUL mecanisme : pas de registre, pas
				// de Chrome/Edge. Level-triggered, idempotent ; marqueur de
				// perimetre = clef _sambaedu_managed (jamais ecraser un fichier
				// pose hors SambaEdu — conflit => error, review #7).
				"app_config": &shared.AppConfigHandler{
					Ops: &appConfigOps{log: logger},
					Log: logger,
				},
				// Story 27.5 — applications (aggregate / scope MACHINE) : le
				// SERVICE SYSTEM DÉCLENCHE le moteur WPKG local à la place de la
				// GPO se4_wpkg. Il DONNE l'URL du bundle (Apache statique) au
				// bootstrap + DÉPOSE le profil par-hôte (profiles.xml/hosts.xml)
				// dans %ProgramData%\SambaEdu\wpkg (D9) + DÉCLENCHE
				// wpkg-client.vbs (le client télécharge, l'agent non — D7), puis
				// LIT wpkg.xml pour l'état par paquet (inventaire AC4). WPKG reste
				// le moteur déclaratif (non absorbé). Shell-out = seule exception
				// justifiée (déclencher un moteur externe). MACHINE et non
				// compagnon (WPKG installe machine-wide — leçon 🔴 27.4 #1).
				"applications": &shared.ApplicationsHandler{
					Ops: &applicationsOps{log: logger, store: store},
					Log: logger,
				},
				// Story 36.1 — fs_acl (exclusive PAR ACE / scope MACHINE) : le
				// SERVICE SYSTEM converge les ACE NTFS gérées (chirurgie DACL —
				// merge SetNamedSecurityInfo DACL-only, jamais de réécriture ;
				// owner/SACL/héritées/tierces intacts, D4). Le store « dernier
				// appliqué » (fsacl-state.json) est la SEULE mémoire des ACE
				// posées (aucune orpheline au changement de valeur). Résolution
				// SID par LSA sur le poste joint (D5) ; refus deny système en
				// défense en profondeur (piège #8). SYSTEM UNIQUEMENT (jamais
				// companion_windows.go).
				"fs_acl": &shared.FsAclHandler{
					Ops:       &fsAclOps{log: logger},
					StatePath: store.FsAclStatePath(),
					Log:       logger,
				},
			},
			Log: logger,
		},
	}
	// Story 27.9 : canal de réveil au logon initialisé À LA CONSTRUCTION, AVANT
	// que la goroutine Run ne démarre (le handler SCM y postera au
	// WTS_SESSION_LOGON). Garantit qu'un RequestWake ne tombe jamais sur un canal
	// nil (qui bloquerait pour toujours) et que le signal n'est jamais perdu
	// faute d'init paresseuse.
	agent.InitWake()

	return agent
}
