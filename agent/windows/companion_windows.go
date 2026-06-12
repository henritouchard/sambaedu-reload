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

// rainmeterPresent : détection par chemins standards — purement informative,
// n'influe JAMAIS sur le statut de convergence (Rainmeter absent = gracieux).
func rainmeterPresent() bool {
	candidates := []string{
		filepath.Join(os.Getenv("ProgramFiles"), "Rainmeter", "Rainmeter.exe"),
		filepath.Join(os.Getenv("LOCALAPPDATA"), "Rainmeter", "Rainmeter.exe"),
	}
	for _, path := range candidates {
		if _, err := os.Stat(path); err == nil {
			return true
		}
	}

	return false
}

// runCompanion : point d'entrée de la sous-commande. Rien ne doit jamais
// être visible ni bloquant dans la session : toute erreur part dans
// companion.log (ou en sortie silencieuse si même le log échoue).
func runCompanion() error {
	detachConsole()

	localAppData := os.Getenv("LOCALAPPDATA")
	if localAppData == "" {
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
		logger.Errorf("Compagnon en échec : résolution du SID impossible (%v).", err)

		return err
	}

	store := &shared.Store{} // racine ProgramData par défaut — LECTURE seule ici
	computerName := os.Getenv("COMPUTERNAME")

	companion := &shared.Companion{
		SID:       sid,
		StatePath: store.SessionStatePath(sid),
		DropDir:   store.SessionReportDir(sid),
		DropPath:  store.SessionReportPath(sid),
		User:      user,
		Engine: &shared.Engine{
			Handlers: map[string]shared.Handler{
				"wallpaper": &wallpaperHandler{AssetsDir: store.AssetsDir()},
				"overlay": &shared.OverlayHandler{
					Path:             user.OverlayPath(),
					ComputerName:     computerName,
					RainmeterPresent: rainmeterPresent,
					Log:              logger,
				},
			},
			Log: logger,
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
