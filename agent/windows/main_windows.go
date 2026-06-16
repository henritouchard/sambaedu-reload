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

	return &shared.Agent{
		Store:            store,
		Client:           shared.NewClient(store, logger, hostname),
		Log:              logger,
		Hostname:         hostname,
		UUID:             smbiosUUID(logger),
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
		// Users:R / SYSTEM+Admins full (setAssetsACL réutilisé). nil en
		// test/Linux (provisioning inerte).
		Rainmeter:    rainmeterPortableStore(),
		RainmeterACL: setAssetsACL,
	}
}
