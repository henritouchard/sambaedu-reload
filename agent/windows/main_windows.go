// Binaire agent SambaEdu desired-state — Windows (Story 24.5, Epic 24).
//
// Service SYSTEM (portée machine) : boucle de check-in GET /state → cache →
// POST /report (items: [] tant que les handlers ne sont pas portés, 24.6).
// Le cœur de la boucle (rotation D5, grâce, quarantaine, backoff, cache
// atomique, StateHasher) vit dans sambaedu/agent/shared — OS-agnostique et
// testé sur l'hôte. Ce package ne contient QUE le spécifique Win32 :
// protocole SCM (x/sys/windows/svc), ACL icacls, UUID SMBIOS.
//
// Sous-commandes (remplacent Install-/Uninstall-SambaEduAgent.ps1 du spike
// 24.2 — le binaire parle le protocole SCM nativement, plus de wrapper
// ServiceBase compilé à l'install) :
//
//	agent.exe install -server-url http://<se5> [-interval 3600]
//	agent.exe uninstall [-purge]
//	agent.exe run        (mode console, debug)
//	agent.exe version
//
// Invariants (iso-24.2, contrat 23.3) :
//   - token : C:\ProgramData\SambaEdu\Agent\token (FIGÉ), relu à chaque cycle ;
//   - hostname COURT dans le rapport (os.Hostname() = COMPUTERNAME) ;
//   - NFR1 : ce service n'interagit JAMAIS avec le logon ;
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
	case "version":
		fmt.Println(shared.Version)
	default:
		fmt.Fprintf(os.Stderr, "usage : agent.exe install|uninstall|run|version\n")
		os.Exit(2)
	}
}

func exitOn(err error) {
	if err != nil {
		fmt.Fprintf(os.Stderr, "erreur : %v\n", err)
		os.Exit(1)
	}
}

// newAgent assemble la boucle shared avec les implémentations Windows :
// ACL icacls (iso-24.2), UUID SMBIOS, hostname COURT.
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
		Store:    store,
		Client:   shared.NewClient(store, logger, hostname),
		Log:      logger,
		Hostname: hostname,
		UUID:     smbiosUUID(logger),
	}
}
