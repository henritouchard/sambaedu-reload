// Binaire agent SambaEdu desired-state — Windows.
//
// Service SYSTEM (portée machine + broker des sessions) : boucle de check-in
// GET /state → cache → fetch des sessions + sync assets → POST /report
// (items réels = drops session collectés/validés). Le cœur (rotation,
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
// Invariants (contrat) :
//   - token : C:\ProgramData\SambaEdu\Agent\token (FIGÉ), relu à chaque cycle,
//     ILLISIBLE du compagnon (ACL SYSTEM+Administrators — le canal réseau
//     est 100 % SYSTEM, frontière de confiance) ;
//   - hostname COURT dans le rapport (os.Hostname() = COMPUTERNAME) ;
//   - rien dans le chemin synchrone du logon (tâches asynchrones) ;
//   - aucune dépendance AD/Kerberos/LDAP — l'auth EST le bearer token.
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
	serviceDescription = "Agent SambaEdu SE5 : convergence état cible + rapport de conformité."
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
		interval := fs.Int("interval", shared.DefaultIntervalSeconds, "cadence de check-in en secondes (défaut 3600)")
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
		// local + code retour.
		runSessionFetchTask()
	case "companion":
		// Tâche planifiée at-logon (BUILTIN\Users) : processus RÉSIDENT aux
		// droits de la session — ni réseau, ni token.
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
	// garanti l'écriture du cache per-SID (correctif race
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
// ACL icacls (per-SID/assets), UUID SMBIOS, hostname COURT,
// énumération WTS des sessions.
func newAgent(echo bool) *shared.Agent {
	store := &shared.Store{SetACL: setAgentACL}
	logger := &shared.Logger{Dir: store.LogsDir(), SetACL: setAgentACL, Echo: echo}

	// os.Hostname() sous Windows = GetComputerNameEx(ComputerNameDnsHostname),
	// le nom COURT du poste (jamais le FQDN) : le serveur compare ce champ à
	// workstations.name.
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
		// Ancre MAC du faisceau d'enrôlement porte 2 (auto-enroll
		// du poste migré). Utilisée seulement quand le token est absent.
		MAC:              macAddress(logger),
		Sessions:         enumerateInteractiveSessions,
		SessionCacheACL:  setSessionCacheACL,
		SessionReportACL: setSessionReportACL,
		AssetsACL:        setAssetsACL,
		// Primitives d'auto-update Windows (vérif Authenticode +
		// swap atomique restart-SCM) + ACL SYSTEM du staging — l'orchestration
		// shared/ les injecte, nil en test/Linux (update inerte).
		UpdateACL:          setUpdateACL,
		VerifyAuthenticode: verifyAuthenticode,
		SwapAndRestart:     swapAndRestart,
		// Provisioning de l'outil de rendu Rainmeter au bootstrap
		// du cycle SYSTEM (portable install-if-absent + config verrouillée). ACL
		// dédiée Users:RX (Rainmeter.exe est lancé par le compagnon aux droits de
		// la session — R seul refuserait l'exécution) / SYSTEM+Admins full. nil en
		// test/Linux (provisioning inerte).
		Rainmeter:    rainmeterPortableStore(),
		RainmeterACL: setRainmeterACL,
		// Ops registre RÉELLES de la passe SYSTEM par-session
		// décorées PAR SID côté shared (HKCU → HKU\<SID>) pour appliquer les
		// items `writer: "system"` (trees HKCU\…\Policies\*, non écrivables
		// par le compagnon sur poste joint au domaine). Mêmes ops concrètes
		// que le MachineEngine ; nil = passe inerte (tests hôte).
		SessionSystemOps: &registryOps{log: logger},
		// Moteur de convergence de la portée MACHINE (le service
		// SYSTEM en est le SEUL acteur — le compagnon ignore la portée
		// machine). Premier type machine : `registry` HKLM (droits SYSTEM).
		// UN seul handler Go générique partagé avec le compagnon (côté
		// HKCU) ; ici câblé pour la ruche machine. logsDir = racine SYSTEM
		// (companion a son log).
		MachineEngine: &shared.Engine{
			Handlers: map[string]shared.Handler{
				"registry": &shared.RegistryHandler{
					Ops: &registryOps{log: logger},
					Log: logger,
				},
				// Listes registre a sous-valeurs indexees `\1..\N`
				// (contrat §7.6, reconciliation de cle-conteneur). Le SERVICE
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
				// Config d'app declarative (aggregate par app_kind / scope
				// MACHINE) : le SERVICE SYSTEM pose le policies.json enterprise
				// natif au chemin
				// d'install Firefox/Thunderbird (%ProgramFiles%\...\distribution\,
				// ecriture atomique). policies.json est machine-wide, admin-write
				// → SYSTEM ecrit (le compagnon user prenait ACCESS_DENIED). La
				// resolution serveur est PAR PARC (niveaux 1-4 : template + auto +
				// defaut etab + WG) ; le par-user de Firefox = le PROFIL (mecanisme
				// B / roaming, hors). UN SEUL mecanisme : pas de registre, pas
				// de Chrome/Edge. Level-triggered, idempotent ; marqueur de
				// perimetre = clef _sambaedu_managed (jamais ecraser un fichier
				// pose hors SambaEdu — conflit => error).
				"app_config": &shared.AppConfigHandler{
					Ops: &appConfigOps{log: logger},
					Log: logger,
				},
				// Applications (aggregate / scope MACHINE) : le
				// SERVICE SYSTEM DÉCLENCHE le moteur WPKG local à la place de la
				// GPO se4_wpkg. Il DONNE l'URL du bundle (Apache statique) au
				// bootstrap + DÉPOSE le profil par-hôte (profiles.xml/hosts.xml)
				// dans %ProgramData%\SambaEdu\wpkg + DÉCLENCHE
				// wpkg-client.vbs (le client télécharge, l'agent non), puis
				// LIT wpkg.xml pour l'état par paquet (inventaire). WPKG reste
				// le moteur déclaratif (non absorbé). Shell-out = seule exception
				// justifiée (déclencher un moteur externe). MACHINE et non
				// compagnon (WPKG installe machine-wide).
				"applications": &shared.ApplicationsHandler{
					Ops: &applicationsOps{log: logger, store: store},
					Log: logger,
				},
				// Fs_acl (exclusive PAR ACE / scope MACHINE) : le
				// SERVICE SYSTEM converge les ACE NTFS gérées (chirurgie DACL —
				// merge SetNamedSecurityInfo DACL-only, jamais de réécriture ;
				// owner/SACL/héritées/tierces intacts). Le store « dernier
				// appliqué » (fsacl-state.json) est la SEULE mémoire des ACE
				// posées (aucune orpheline au changement de valeur). Résolution
				// SID par LSA sur le poste joint ; refus deny système en
				// défense en profondeur. SYSTEM UNIQUEMENT (jamais
				// companion_windows.go).
				"fs_acl": &shared.FsAclHandler{
					Ops:       &fsAclOps{log: logger},
					StatePath: store.FsAclStatePath(),
					Log:       logger,
				},
				// Firewall (exclusive PAR rule_id / scope MACHINE) :
				// le SERVICE SYSTEM converge les règles pare-feu POSSÉDÉES PAR
				// GROUPE (`SambaEdu-Agent`, réconciliation par conteneur — le champ
				// Grouping EST le marqueur, PAS de store). Les règles hors groupe,
				// la politique par défaut et le service MpsSvc ne sont JAMAIS
				// touchés (FirewallOps 3 ops). Refus en défense en profondeur
				// d'un block couvrant le LAN. Impl COM natif INetFwPolicy2
				// (netsh ne sait pas poser le Grouping). SYSTEM UNIQUEMENT.
				"firewall": &shared.FirewallHandler{
					Ops: &firewallOps{log: logger},
					Log: logger,
				},
				// Privilege (exclusive PAR nom de privilège / scope
				// MACHINE) : le SERVICE SYSTEM converge les droits de logon LSA
				// `SeDeny*` gérés (réconciliation de CONTENEUR — le privilège EST
				// le conteneur, titulaires énumérables via
				// LsaEnumerateAccountsWithUserRight : accorde les manquants,
				// révoque les surnuméraires — AUCUN store). Refus SeDeny*-only en
				// défense en profondeur (un grant possédé en liste entière
				// verrouillerait la machine). Résolution SID via windows.LookupSID
				// (iso fs_acl). SYSTEM UNIQUEMENT (jamais companion_windows.go).
				"privilege": &shared.PrivilegeHandler{
					Ops: &privilegeOps{log: logger},
					Log: logger,
				},
				// Legacy_cleanup (exclusive / scope MACHINE) : le
				// SERVICE SYSTEM retire les crochets clients legacy SE4 par
				// SCAN idempotent SANS store (catalogue versionné DANS l'agent :
				// blobs applications-*, tâches wpkg4/*-system gardées par
				// action, scripts GPO locale curl-ant gpo/*.php, jonctions
				// install/rapports reparse-only, helpers en liste blanche,
				// autologon se4install gardé, paires Mozilla sambaedu.default —
				// payload `mozilla: "vanilla"`). Chaque suppression est
				// individuellement gardée ; JAMAIS GroupPolicy\DataStore,
				// wpkg.xml, le dossier SambaEdu ni Agent\**. MACHINE
				// SEULEMENT (HKLM,
				// schtasks, C:\Users\* — jamais le compagnon).
				"legacy_cleanup": newLegacyCleanupHandler(logger),
			},
			Log: logger,
		},
	}
	// Canal de réveil au logon initialisé À LA CONSTRUCTION, AVANT
	// que la goroutine Run ne démarre (le handler SCM y postera au
	// WTS_SESSION_LOGON). Garantit qu'un RequestWake ne tombe jamais sur un canal
	// nil (qui bloquerait pour toujours) et que le signal n'est jamais perdu
	// faute d'init paresseuse.
	agent.InitWake()

	return agent
}
