package main

import (
	"context"
	"fmt"
	"os"
	"os/signal"
	"path/filepath"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

var (
	kernel32             = windows.NewLazySystemDLL("kernel32.dll")
	procFreeConsole      = kernel32.NewProc("FreeConsole")
	procAllocConsole     = kernel32.NewProc("AllocConsole")
	procGetConsoleWindow = kernel32.NewProc("GetConsoleWindow")

	user32            = windows.NewLazySystemDLL("user32.dll")
	procGetSystemMenu = user32.NewProc("GetSystemMenu")
	procDeleteMenu    = user32.NewProc("DeleteMenu")
)

// Constantes console/menu système (non exposées par golang.org/x/sys/windows).
const (
	enableQuickEditMode = 0x0040
	enableExtendedFlags = 0x0080
	scClose             = 0xF060
	mfByCommand         = 0x0000
)

// consoleAttached : état console de CE processus, tel que piloté par
// attachConsole/detachConsole. Le compagnon démarre avec la console héritée de
// la tâche planifiée (binaire CONSOLE), donc true.
var consoleAttached = true

// detachConsole : la tâche planifiée lance un binaire CONSOLE dans la session
// interactive → Windows lui ouvre une fenêtre, qui resterait affichée toute
// la session (processus résident) et qu'un user pourrait fermer (= tuer le
// compagnon) ; un clic dedans (quick-edit) gèle même les écritures stdout —
// constaté lab ws 49 (T12 24.6). FreeConsole détache le processus : la
// fenêtre se ferme aussitôt (bref flash au logon, assumé). Best-effort : en
// debug manuel (`agent.exe companion` depuis un terminal), l'effet est de
// rendre la main au shell — tout le diagnostic vit dans companion.log.
func detachConsole() {
	_, _, _ = procFreeConsole.Call()
	consoleAttached = false
}

// attachConsole : RÉ-alloue une console à un processus qui a déjà appelé
// FreeConsole (Story 2.12.3). Sans elle, la décision console du compagnon était
// IRRÉVERSIBLE — un seul point de lecture du drapeau `debug`, à t≈0, et plus
// aucun chemin de retour ensuite.
//
// Pourquoi c'était cassé : sur un poste fraîchement réinstallé, la tâche
// session-fetch (SYSTEM, qui écrit cache\sessions\<SID>\state.json) et la tâche
// du compagnon partagent le trigger At log on — elles sont en COURSE. Le
// compagnon lisait un cache encore absent, DebugFromStateCacheFile rendait false
// (best-effort : toute erreur → false) et la console partait pour de bon, alors
// que le poste était bien en debug côté serveur. Le reste convergeait quand même
// (RunPass tolère 60 s d'attente du cache) : seule la console manquait, sans la
// moindre trace d'erreur. Constaté 2026-07-20, poste réinstallé.
//
// AllocConsole seul ne suffit pas : après FreeConsole, les handles standards
// hérités au démarrage sont MORTS. Il faut rouvrir CONOUT$ et re-pointer
// os.Stdout/os.Stderr dessus, sinon la console s'ouvre… vide (le Logger écrit
// dans un handle invalide). Best-effort intégral : tout échec laisse le
// diagnostic vivre dans companion.log, comme avant.
func attachConsole() error {
	if consoleAttached {
		return nil
	}
	if ret, _, err := procAllocConsole.Call(); ret == 0 {
		return fmt.Errorf("AllocConsole : %w", err)
	}

	handle, err := windows.CreateFile(
		windows.StringToUTF16Ptr("CONOUT$"),
		windows.GENERIC_READ|windows.GENERIC_WRITE,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE,
		nil, windows.OPEN_EXISTING, 0, 0,
	)
	if err != nil {
		// Console allouée mais inutilisable : on la rend plutôt que de laisser
		// une fenêtre morte à l'écran.
		_, _, _ = procFreeConsole.Call()

		return fmt.Errorf("ouverture de CONOUT$ : %w", err)
	}

	_ = windows.SetStdHandle(windows.STD_OUTPUT_HANDLE, handle)
	_ = windows.SetStdHandle(windows.STD_ERROR_HANDLE, handle)
	console := os.NewFile(uintptr(handle), "CONOUT$")
	os.Stdout = console
	os.Stderr = console

	consoleAttached = true
	hardenConsole()

	return nil
}

// hardenConsole : rend la console de diagnostic INOFFENSIVE pour le processus
// qu'elle diagnostique. Deux dangers, tous deux documentés dès l'origine dans
// detachConsole et tous deux réactivés par le rattachement de la 2.12.3 (avant
// elle, la course au logon empêchait le mode debug de s'armer — la console
// n'apparaissait jamais, donc le risque était inatteignable) :
//
//  1. QUICK-EDIT. Un simple clic dans la fenêtre met la console en sélection et
//     BLOQUE toute écriture stdout. `Logger.log` tient son mutex pendant
//     l'écriture : le compagnon se fige au premier log, définitivement. Vu de
//     SE5 : plus aucun drop, donc `companion` en erreur — alors que le
//     processus existe et que l'overlay (Rainmeter, processus SÉPARÉ) continue
//     de s'afficher, ce qui rend le diagnostic trompeur.
//  2. LE BOUTON FERMER. Fermer la console d'un processus console le TUE. On
//     offrirait à l'utilisateur un moyen d'un clic de supprimer la convergence
//     de sa propre session — jusqu'au logon suivant, la tâche planifiée n'ayant
//     qu'un trigger At log on.
//
// Best-effort intégral : chaque étape est indépendante et un échec ne fait rien
// perdre d'autre que le durcissement correspondant.
func hardenConsole() {
	// ENABLE_EXTENDED_FLAGS est OBLIGATOIRE pour que le retrait de
	// ENABLE_QUICK_EDIT_MODE soit pris en compte (contrat SetConsoleMode).
	if in, err := windows.CreateFile(
		windows.StringToUTF16Ptr("CONIN$"),
		windows.GENERIC_READ|windows.GENERIC_WRITE,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE,
		nil, windows.OPEN_EXISTING, 0, 0,
	); err == nil {
		defer func() { _ = windows.CloseHandle(in) }()

		var mode uint32
		if windows.GetConsoleMode(in, &mode) == nil {
			mode &^= enableQuickEditMode
			mode |= enableExtendedFlags
			_ = windows.SetConsoleMode(in, mode)
		}
	}

	// GetSystemMenu(hwnd, false) rend le menu système de la fenêtre ; en
	// retirer SC_CLOSE grise le bouton ET désactive Alt+F4.
	hwnd, _, _ := procGetConsoleWindow.Call()
	if hwnd == 0 {
		return
	}
	menu, _, _ := procGetSystemMenu.Call(hwnd, 0)
	if menu == 0 {
		return
	}
	_, _, _ = procDeleteMenu.Call(menu, scClose, mfByCommand)
}

// Câblage Windows du compagnon de session (Story 24.6) — sous-commande
// `agent.exe companion`, lancée par la tâche planifiée
// SambaEduAgent-SessionCompanion (principal BUILTIN\Users, At log on,
// résident — sans limite d'exécution).
//
// AUCUN code réseau ni lecture de token dans ce chemin (frontière de
// confiance NFR5) : le compagnon ne construit JAMAIS de shared.Client — il
// lit son cache per-SID (alimenté par session-fetch SYSTEM) et écrit profil
// user + drop. Toute la logique vit dans shared.Companion (testée hôte) ;
// ce fichier ne fait que résoudre le SID (token de processus), les chemins
// et les handlers.

// runCompanion : point d'entrée de la sous-commande. Rien ne doit jamais
// être visible ni bloquant dans la session : toute erreur part dans
// companion.log (ou en sortie silencieuse si même le log échoue).
func runCompanion() error {
	localAppData := os.Getenv("LOCALAPPDATA")
	if localAppData == "" {
		detachConsole()

		return fmt.Errorf("LOCALAPPDATA non défini : pas de profil agent")
	}
	user := &shared.UserStore{Root: filepath.Join(localAppData, "SambaEdu", "Agent")}

	// Log per-user : format/rotation/rétention iso shared.Logger, fichier
	// companion.log (aucune élévation, aucune ACL — profil user).
	logger := &shared.Logger{Dir: user.Root, FileName: user.CompanionLogFile()}

	// SON SID, résolu localement (token de processus — décision n° 2) :
	// uniquement pour trouver SON cache et SON drop. Jamais transmis.
	sid, err := currentProcessSID()
	if err != nil {
		detachConsole()
		logger.Errorf("Compagnon en échec : résolution du SID impossible (%v).", err)

		return err
	}

	store := &shared.Store{} // racine ProgramData par défaut — LECTURE seule ici

	// Mode debug du poste (drapeau d'enveloppe `debug` du dernier état tiré
	// par session-fetch SYSTEM, lu en best-effort dans le cache per-SID) :
	//   - debug ON  → on NE détache PAS la console (le user veut voir l'agent
	//     tourner, quelle que soit la session) + echo des logs en direct ;
	//   - debug OFF → comportement nominal : FreeConsole (la fenêtre héritée
	//     de la tâche planifiée se ferme — bref flash au logon).
	// Cette lecture reste le chemin NOMINAL (cache déjà présent = console
	// conservée sans le moindre clignotement), mais elle n'est plus la SEULE :
	// OnDebugChange ci-dessous rattrape le cas où le cache n'est pas encore là
	// (poste réinstallé, session-fetch en course) ET le toggle en cours de
	// session. Voir attachConsole.
	if shared.DebugFromStateCacheFile(store.SessionStatePath(sid)) {
		// La console héritée de la tâche planifiée est aussi dangereuse que
		// celle qu'on alloue : elle se durcit de la même façon.
		hardenConsole()
		logger.SetEcho(true)
		logger.Infof("Mode debug actif (serveur) : console conservée, logs recopiés en direct.")
	} else {
		detachConsole()
	}

	companion := &shared.Companion{
		SID:       sid,
		StatePath: store.SessionStatePath(sid),
		DropDir:   store.SessionReportDir(sid),
		DropPath:  store.SessionReportPath(sid),
		User:      user,
		Engine: &shared.Engine{
			Handlers: map[string]shared.Handler{
				"wallpaper": &wallpaperHandler{AssetsDir: store.AssetsDir()},
				// Story 27.1bis (D1) : l'overlay a QUITTÉ la map du compagnon.
				// overlay.json est désormais composé ET écrit par le SERVICE
				// SYSTEM au logon (overlay_logon_windows.go), possédé SYSTEM +
				// ACL <SID>:R — infalsifiable par l'élève (NFR5). Le compagnon
				// (droits user) ne le touche plus. La composition
				// (ComposeOverlayDocument) reste réutilisée à l'identique côté
				// SYSTEM (golden inchangé).
				// Story 27.1 — raccourcis (aggregate / machine_user) : pose les
				// `.lnk` au chemin résolu serveur (fix Bug C), level-triggered,
				// COM IShellLink natif.
				"shortcuts": &shared.ShortcutsHandler{
					// Story 27.7 : iconsDir = cache local des icônes uploadées
					// content-addressed (pré-téléchargées en SYSTEM par
					// SyncShortcutIcons). Le compagnon pointe l'IconLocation dessus.
					Ops: &shortcutOps{log: logger, iconsDir: store.IconsDir()},
					Log: logger,
				},
				// Story 27.2 — imprimantes (aggregate / session) : connexion au
				// partage Samba imprimante (AddPrinterConnection natif), defaut
				// pose sur l'item is_default ; level-triggered, marqueur de
				// perimetre = serveur SambaEdu.
				"printers": &shared.PrintersHandler{
					Ops: &printerOps{log: logger},
					Log: logger,
				},
				// Story 27.2 — lecteurs reseau (aggregate / session) : montage
				// lettre->UNC des classes du user (WNetAddConnection2 natif),
				// level-triggered, marqueur de perimetre = serveur SambaEdu.
				"drives": &shared.DrivesHandler{
					Ops: &driveOps{log: logger},
					Log: logger,
				},
				// Story 36.5 — app_profile (aggregate / session) : le COMPAGNON
				// redirige le profil applicatif (Firefox/Thunderbird) vers le home
				// reseau (lien de dossier vers UNC + paire d'ini + marqueur de
				// version). Donnee d'UTILISATEUR, jamais le service SYSTEM (AC2).
				// Report SE4 Roaming->Server (acces direct serveur sans copie).
				// Home injoignable => item error, jamais de suppression locale
				// (AC6). Le nom de profil managed.default est neuf/hors radical
				// sambaedu => jamais efface par legacy_cleanup (38.3).
				"app_profile": &shared.AppProfileHandler{
					Ops: &appProfileOps{log: logger},
					Log: logger,
				},
				// Story 58.1 — folders (exclusive par dossier / machine_user) :
				// le COMPAGNON redirige les dossiers shell (User Shell Folders)
				// vers le chemin emis par le serveur — le MEME que celui ou
				// `shortcuts` ci-dessus pose les `.lnk`. Successeur du script GPO
				// legacy `folders/bureau_samba`, coupe le 2026-07-20 sans
				// remplacant : depuis, tout profil itinerant NEUF gardait un
				// Bureau local et n'affichait AUCUN raccourci pose en reseau.
				// HKCU + cible atteinte avec l'identite de l'utilisateur => le
				// compagnon, jamais le service SYSTEM. Le registre passe par le
				// MEME registryOps que `registry` (une seule implementation).
				"folders": &shared.FoldersHandler{
					Ops:      &folderOps{log: logger},
					Registry: &registryOps{log: logger},
					Log:      logger,
				},
				// Story 27.3 — registre HKCU (exclusive par cle / session) : le
				// COMPAGNON applique les reglages de la ruche utilisateur (effet
				// Explorer immediat). Les items HKLM (portee machine) sont
				// appliques par le SERVICE SYSTEM (machine_windows.go), JAMAIS
				// ici (droits user). UN seul handler Go generique, deux moteurs.
				"registry": &shared.RegistryHandler{
					Ops: &registryOps{log: logger},
					Log: logger,
				},
				// Story 35.2 — listes registre a sous-valeurs indexees `\1..\N`
				// (contrat §7.6, reconciliation de cle-conteneur D3). Le
				// COMPAGNON reconcilie les conteneurs HKCU (ex. Policies\
				// Explorer\DisallowRun de blocked_executables — effet Explorer
				// au LOGON SUIVANT). Changement effectif => rafraichissement
				// shell, meme gate que `registry`.
				"registry_list": &shared.RegistryListHandler{
					Ops: &registryOps{log: logger},
					Log: logger,
				},
				// Story 27.3bis — associations de fichiers/protocoles (exclusive
				// par identifiant / session) : le COMPAGNON impose le ProgId par
				// defaut sous HKCU UserChoice + le HASH anti-tamper (calcule
				// agent-side a partir du SID/temps/experience du poste). ProgId
				// absent => choix utilisateur PRESERVE (pas de clobber), error non
				// fatal (D-Henri n5). Level-triggered, idempotent.
				"associations": &shared.AssociationsHandler{
					Ops: &associationsOps{log: logger},
					Log: logger,
				},
				// Story 27.4 — config d'app declarative : le handler `app_config`
				// a QUITTE la map du compagnon (correctif post-review 2026-06-17,
				// review #1). policies.json est ecrit sous %ProgramFiles%\...\
				// distribution\ (machine-wide, admin-write) — un compagnon aux
				// droits user prend ACCESS_DENIED a chaque logon. Il est desormais
				// porte par le MachineEngine SYSTEM (main_windows.go), iso le
				// handler `registry` HKLM (27.3). Le par-user de Firefox = le
				// PROFIL (mecanisme B / roaming), PAS policies.json — donc aucun
				// niveau user perdu (resolution serveur niveaux 1-4 par parc).
			},
			Log: logger,
		},
		// Story 27.1bis (D5) : watchdog Rainmeter côté compagnon (droits user) —
		// relance Rainmeter.exe (pointant la skin verrouillée ProgramData) s'il
		// disparaît, idempotent + borné, meurt au logoff. Le portable + la
		// config sont posés par le SERVICE SYSTEM (provisioning au bootstrap) ;
		// le compagnon ne fait que maintenir le rendu vivant.
		Watchdog: newRainmeterWatchdog(rainmeterPortableStore(), logger),
		// Story 27.1ter (mode installé, D2) : avant le lancement de Rainmeter par
		// le watchdog, le compagnon (droits user) (ré)impose le Rainmeter.ini
		// durci dans %APPDATA%\Rainmeter\, WRITABLE — supprime les modales « not
		// writable » / « Safe Start » sur un user standard. Le SYSTEM a, lui,
		// retiré tout Rainmeter.ini de l'arbre ProgramData (mode installé).
		EnsureUserRainmeterIni: func() error { return ensureUserRainmeterIni(logger) },
		// Story 43.1 : échelle de rafraîchissement de session (shell_notify <
		// policy_broadcast < explorer_restart) — UN geste max en fin de passe,
		// le plus fort requis par les items HKCU effectivement changés
		// (RefreshRequester des handlers registry/registry_list). Injectée ICI
		// SEULEMENT : le MachineEngine SYSTEM (main_windows.go) n'a AUCUNE ops
		// de refresh — jamais de geste en session 0 (piège n° 2).
		Refresh: &refreshOps{log: logger},
		// Story 2.12.3 : le drapeau `debug` est RELU à chaque passe et la console
		// suit — première observation (rattrapage de la course au logon d'un poste
		// réinstallé) comme bascule en cours de session.
		OnDebugChange: func(debug bool) {
			if !debug {
				if consoleAttached {
					logger.Infof("Mode debug désactivé (serveur) : console détachée, le diagnostic continue dans companion.log.")
					logger.SetEcho(false)
					detachConsole()
				}

				return
			}
			// Chemin nominal (console jamais détachée) : rien à dire de plus que
			// le message déjà émis au démarrage.
			if consoleAttached {
				logger.SetEcho(true)

				return
			}
			if err := attachConsole(); err != nil {
				// Le poste est en debug mais on n'a pas su rendre une console :
				// on le DIT dans le log plutôt que d'échouer en silence — c'est
				// exactement le silence qui avait rendu le bug indétectable.
				logger.Warningf("Mode debug actif (serveur) mais rattachement de la console impossible : %v — le diagnostic reste dans companion.log.", err)

				return
			}
			logger.SetEcho(true)
			logger.Infof("Mode debug actif (serveur) : console rattachée, logs recopiés en direct.")
		},
		Log: logger,
	}

	// Le processus meurt à la fin de session (logoff) ; Interrupt couvre le
	// lancement console (debug). La boucle résidente sort proprement.
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()

	companion.Run(ctx)

	return nil
}
