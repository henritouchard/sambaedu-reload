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

var procFreeConsole = windows.NewLazySystemDLL("kernel32.dll").NewProc("FreeConsole")

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
	// Latence assumée : le 1er logon suivant l'activation lit le cache encore
	// « non-debug » ; la console apparaît au logon suivant (cache rafraîchi).
	if shared.DebugFromStateCacheFile(store.SessionStatePath(sid)) {
		logger.Echo = true
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
				// Story 27.3 — registre HKCU (exclusive par cle / session) : le
				// COMPAGNON applique les reglages de la ruche utilisateur (effet
				// Explorer immediat). Les items HKLM (portee machine) sont
				// appliques par le SERVICE SYSTEM (machine_windows.go), JAMAIS
				// ici (droits user). UN seul handler Go generique, deux moteurs.
				"registry": &shared.RegistryHandler{
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
		Log:                    logger,
	}

	// Le processus meurt à la fin de session (logoff) ; Interrupt couvre le
	// lancement console (debug). La boucle résidente sort proprement.
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()

	companion.Run(ctx)

	return nil
}
