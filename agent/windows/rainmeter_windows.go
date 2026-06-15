package main

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Watchdog Rainmeter côté COMPAGNON (Story 27.1bis, volet 3 / D5) — primitives
// OS injectées dans shared.RainmeterWatchdog. Le compagnon tourne aux droits de
// la SESSION : la relance est triviale (os/exec), et le process meurt au logoff
// (acceptable). PAS d'obfuscation (D7) : on relance, on ne masque rien.
//
// Le portable Rainmeter et sa config verrouillée sont posés par le SERVICE
// SYSTEM (provisioning au bootstrap) ; le watchdog ne fait que MAINTENIR le
// rendu vivant — il n'installe jamais rien (« handler jamais installeur »).

// rainmeterOps implémente shared.RainmeterOps pour le compagnon Windows.
type rainmeterOps struct {
	store *shared.RainmeterStore
	log   *shared.Logger
}

// Installed : Rainmeter.exe présent dans le dossier portable (sentinelle) ?
func (o *rainmeterOps) Installed() bool {
	return o.store.RainmeterInstalled()
}

// Running : un process Rainmeter.exe tourne-t-il DANS LA SESSION COURANTE ?
// (#12) Énumération des process via CreateToolhelp32Snapshot (Win32 plat, zéro
// shell-out, zéro WMI). On compare le nom d'image (Rainmeter.exe, insensible à
// la casse) ET on filtre par session : sur un poste multi-utilisateurs (RDS,
// bascule rapide), le Rainmeter d'une AUTRE session ne doit pas masquer
// l'absence de rendu dans CELLE-ci. Un PID dont la session est indéterminée est
// ignoré (on préfère relancer que de croire à tort qu'il tourne).
func (o *rainmeterOps) Running() bool {
	currentSession, err := o.currentSessionID()
	if err != nil {
		// Session courante indéterminée : on ne peut pas filtrer proprement —
		// supposé absent (le watchdog relancera, borné ; Windows ne lance pas
		// une 2e instance de la même config dans la même session).
		o.log.Debugf("Watchdog Rainmeter : session courante indéterminée (%v) — supposé absent.", err)

		return false
	}

	snapshot, err := windows.CreateToolhelp32Snapshot(windows.TH32CS_SNAPPROCESS, 0)
	if err != nil {
		// Énumération impossible : on suppose absent (le watchdog tentera une
		// relance bornée — inoffensif si Rainmeter tourne déjà, Windows ne
		// relance pas une 2e instance de la même config).
		o.log.Debugf("Watchdog Rainmeter : snapshot des process en échec (%v) — supposé absent.", err)

		return false
	}
	defer windows.CloseHandle(snapshot)

	var entry windows.ProcessEntry32
	entry.Size = uint32(unsafe.Sizeof(entry))
	if err := windows.Process32First(snapshot, &entry); err != nil {
		return false
	}
	for {
		name := windows.UTF16ToString(entry.ExeFile[:])
		if strings.EqualFold(name, "Rainmeter.exe") {
			var pidSession uint32
			if err := windows.ProcessIdToSessionId(entry.ProcessID, &pidSession); err == nil && pidSession == currentSession {
				return true
			}
		}
		if err := windows.Process32Next(snapshot, &entry); err != nil {
			break
		}
	}

	return false
}

// currentSessionID : identifiant de la session du process compagnon (qui tourne
// aux droits de la session de l'élève). Sert au filtre de session du watchdog.
func (o *rainmeterOps) currentSessionID() (uint32, error) {
	var session uint32
	if err := windows.ProcessIdToSessionId(windows.GetCurrentProcessId(), &session); err != nil {
		return 0, err
	}

	return session, nil
}

// Launch : lance Rainmeter.exe SANS argument (#8). En mode portable, Rainmeter
// lit son Rainmeter.ini dans son propre dossier ; ce Rainmeter.ini durci (sous
// ProgramData ACL) déclare la skin SambaEduOverlay en Active=1 et porte
// TrayIcon=0 + Draggable=0/ClickThrough=1/KeepOnScreen=1 sur la section
// d'instance — la skin se charge donc d'elle-même, pas besoin de passer son
// chemin en argument (le faire courcircuiterait le chargement automatique).
// HideWindow : pas de console parasite.
//
// Détachement réel (#13) : CREATE_NEW_PROCESS_GROUP + DETACHED_PROCESS placent
// Rainmeter dans son propre groupe, SANS console héritée du compagnon — la mort
// du compagnon (logoff partiel, crash, redémarrage du résident) ne propage pas
// de signal qui tuerait Rainmeter. Il survit tant que la session vit. Start
// (pas Run) : on ne bloque pas. Best-effort : l'erreur est loggée par le
// watchdog.
func (o *rainmeterOps) Launch() error {
	cmd := exec.Command(o.store.ExePath())
	cmd.SysProcAttr = &syscall.SysProcAttr{
		HideWindow:    true,
		CreationFlags: windows.CREATE_NEW_PROCESS_GROUP | windows.DETACHED_PROCESS,
	}

	return cmd.Start()
}

// newRainmeterWatchdog assemble le watchdog compagnon (ops Windows injectées).
func newRainmeterWatchdog(store *shared.RainmeterStore, log *shared.Logger) *shared.RainmeterWatchdog {
	return &shared.RainmeterWatchdog{
		Ops: &rainmeterOps{store: store, log: log},
		Log: log,
	}
}

// rainmeterPortableStore : store de l'outil de rendu (racine ProgramData
// frère de l'Agent). Surchargé par %ProgramData% si défini (cohérence avec
// l'environnement réel ; défaut C:\ProgramData\SambaEdu\Rainmeter).
func rainmeterPortableStore() *shared.RainmeterStore {
	if pd := os.Getenv("ProgramData"); pd != "" {
		return &shared.RainmeterStore{Root: filepath.Join(pd, "SambaEdu", "Rainmeter")}
	}

	return &shared.RainmeterStore{}
}
