package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"

	"sambaedu/agent/shared"
)

// Câblage Windows de l'échelle de rafraîchissement du compagnon (Story 43.1,
// shared.RefreshOps). TROIS gestes, tous en FFI Win32 SANS cgo
// (NewLazySystemDLL — patron wallpaper/registry), tous exécutés DANS LA
// SESSION du compagnon avec les droits du user connecté — JAMAIS SYSTEM,
// jamais d'élévation (NFR-A1). L'injection ne se fait QUE dans
// companion_windows.go : le MachineEngine SYSTEM (main_windows.go) ne reçoit
// AUCUNE ops de refresh (piège n° 2 — aucun geste en session 0, fan-out HKU
// compris).
//
// Chaque geste est BEST-EFFORT (D4) : l'échec est loggé en warning par le
// compagnon, jamais converti en erreur de passe — les clés sont déjà écrites,
// au pire l'effet attend le relogon.

// --- shell_notify : SHChangeNotify (shell32) ---------------------------------
// Signale au shell un changement global : l'Explorer DÉJÀ ouvert relit ses
// réglages de vue (Hidden, HideFileExt) sans relogon. Migré de
// handler_registry_windows.go (ex-registryNotifier.NotifyShellChanged) : UNE
// seule voie d'émission désormais (piège n° 5), pilotée par le compagnon en
// fin de passe.
const (
	shcneAssocChanged = 0x08000000 // SHCNE_ASSOCCHANGED : force le shell à relire ses réglages
	shcnfIDList       = 0x0000     // SHCNF_IDLIST
)

// --- policy_broadcast : SendMessageTimeoutW (user32) -------------------------
// WM_SETTINGCHANGE diffusé à toutes les fenêtres top-level avec la section
// "Policy" : les applis à l'écoute (Explorer compris) relisent leurs policies.
// SMTO_ABORTIFHUNG + timeout borné : une fenêtre pendue ne bloque jamais le
// compagnon (piège n° 4).
const (
	hwndBroadcast          = 0xFFFF // HWND_BROADCAST
	wmSettingChange        = 0x001A // WM_SETTINGCHANGE
	smtoAbortIfHung        = 0x0002 // SMTO_ABORTIFHUNG
	policyBroadcastTimeout = 5000   // ms — borne l'appel, jamais le compagnon
)

// explorerRelaunchGrace : délai supplémentaire AVANT l'ultime vérification qui
// précède la relance d'explorer.exe (review 43.1 #4) — laisse à Windows une
// dernière chance de relancer le shell APRÈS la borne de poll de 3 s, sans
// quoi le compagnon lancerait un 2e explorer (fenêtre parasite).
const explorerRelaunchGrace = time.Second

var (
	modShell32              = windows.NewLazySystemDLL("shell32.dll")
	procSHChangeNotify      = modShell32.NewProc("SHChangeNotify")
	procSendMessageTimeoutW = modUser32.NewProc("SendMessageTimeoutW") // modUser32 : handler_wallpaper_windows.go
)

// refreshOps : impl shared.RefreshOps de production (compagnon seul).
type refreshOps struct {
	log *shared.Logger
}

// ShellNotify émet SHChangeNotify(SHCNE_ASSOCCHANGED, SHCNF_IDLIST) —
// aucun retour exploitable (void Win32) : best-effort par nature.
func (o *refreshOps) ShellNotify() {
	_, _, _ = procSHChangeNotify.Call(uintptr(shcneAssocChanged), uintptr(shcnfIDList), 0, 0)
}

// PolicyBroadcast émet SendMessageTimeout(HWND_BROADCAST, WM_SETTINGCHANGE, 0,
// "Policy", SMTO_ABORTIFHUNG, timeout). Le buffer UTF-16 de "Policy" doit
// rester VIVANT pendant l'appel (piège n° 4 — GC classique des FFI) :
// runtime.KeepAlive après le Call. Retour 0 = échec/timeout (ERROR_TIMEOUT si
// une fenêtre a pendu) → erreur remontée, traitée en warning par le compagnon.
func (o *refreshOps) PolicyBroadcast() error {
	section, err := windows.UTF16PtrFromString("Policy")
	if err != nil {
		return fmt.Errorf("conversion UTF-16 de \"Policy\" : %w", err)
	}

	var result uintptr
	r1, _, lastErr := procSendMessageTimeoutW.Call(
		uintptr(hwndBroadcast),
		uintptr(wmSettingChange),
		0,
		uintptr(unsafe.Pointer(section)),
		uintptr(smtoAbortIfHung),
		uintptr(policyBroadcastTimeout),
		uintptr(unsafe.Pointer(&result)),
	)
	runtime.KeepAlive(section)
	if r1 == 0 {
		return fmt.Errorf("SendMessageTimeout(WM_SETTINGCHANGE \"Policy\") en échec ou timeout : %v", lastErr)
	}

	return nil
}

// RestartExplorer termine puis relance explorer.exe de la SESSION du compagnon
// (droits user — TerminateProcess ne porte que sur ses propres processus,
// jamais d'élévation). Séquence robuste (piège n° 3) :
//  1. énumérer les explorer.exe de SA session (Toolhelp32 + ProcessIdToSessionId) ;
//  2. TerminateProcess sur chacun ;
//  3. poll borné (~3 s) : Windows relance parfois le shell TOUT SEUL — s'il
//     est revenu (nouveau PID), NE PAS relancer (un explorer.exe lancé alors
//     que le shell tourne OUVRE UNE FENÊTRE, ce n'est pas un no-op) ;
//  4. sinon lancer %WINDIR%\explorer.exe (droits du compagnon).
//
// NFR-A1 assumé : les applis restent intactes, seules les fenêtres de
// l'Explorateur sont perdues.
func (o *refreshOps) RestartExplorer() error {
	session, err := currentSessionID()
	if err != nil {
		return fmt.Errorf("résolution de la session courante : %w", err)
	}

	pids, err := explorerPidsInSession(session)
	if err != nil {
		return fmt.Errorf("énumération des explorer.exe de la session %d : %w", session, err)
	}

	killed := map[uint32]bool{}
	for _, pid := range pids {
		if err := terminateProcess(pid); err != nil {
			// Best-effort par PID : on continue (un doublon zombie ne doit pas
			// empêcher de tuer le shell principal) — loggé pour diagnostic.
			if o.log != nil {
				o.log.Warningf("TerminateProcess(explorer.exe pid=%d) en échec : %v", pid, err)
			}

			continue
		}
		killed[pid] = true
	}

	// Poll borné : laisser mourir les anciens PID et laisser à Windows sa
	// chance de relancer le shell lui-même (garde anti-double-lancement).
	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		time.Sleep(250 * time.Millisecond)
		current, err := explorerPidsInSession(session)
		if err != nil {
			continue // énumération transitoire en échec : on re-tente jusqu'à la borne
		}
		for _, pid := range current {
			if !killed[pid] {
				// Un NOUVEAU explorer.exe est là : Windows a relancé le shell
				// tout seul — ne pas relancer (fenêtre parasite sinon).
				return nil
			}
		}
		if len(current) == 0 {
			break // tous morts, personne n'a relancé : à nous de le faire
		}
	}

	// Dernier état des lieux (review 43.1 #4) : Windows peut relancer le shell
	// APRÈS la borne de poll — court délai supplémentaire puis ULTIME
	// vérification juste avant la relance : s'il reste ou revient UN
	// explorer.exe (ancien PID résistant à TerminateProcess, relance tardive),
	// ne pas en rajouter (fenêtre parasite sinon). La course RÉSIDUELLE (une
	// relance Windows entre CE check et le Start) est incompressible et
	// assumée — non testable hôte, documentée au runbook 43.1.3.
	time.Sleep(explorerRelaunchGrace)
	if current, err := explorerPidsInSession(session); err == nil && len(current) > 0 {
		return nil
	}

	windir := os.Getenv("WINDIR")
	if windir == "" {
		windir = `C:\Windows`
	}
	explorer := filepath.Join(windir, "explorer.exe")
	cmd := exec.Command(explorer)
	if err := cmd.Start(); err != nil {
		return fmt.Errorf("relance de %s : %w", explorer, err)
	}
	// Processus détaché : le shell vit sa vie, le compagnon ne l'attend pas.
	_ = cmd.Process.Release()

	return nil
}

// currentSessionID : la session Terminal Services du processus courant (celle
// du user — le compagnon tourne dans la session interactive, jamais en 0).
func currentSessionID() (uint32, error) {
	var session uint32
	if err := windows.ProcessIdToSessionId(windows.GetCurrentProcessId(), &session); err != nil {
		return 0, err
	}

	return session, nil
}

// explorerPidsInSession : PIDs des processus explorer.exe appartenant à LA
// session donnée (snapshot Toolhelp32 — golang.org/x/sys/windows, zéro cgo).
// Un PID dont la session n'est pas résoluble (mort en course, accès refusé)
// est ignoré : on ne touche JAMAIS un processus hors de sa propre session.
func explorerPidsInSession(session uint32) ([]uint32, error) {
	snapshot, err := windows.CreateToolhelp32Snapshot(windows.TH32CS_SNAPPROCESS, 0)
	if err != nil {
		return nil, fmt.Errorf("CreateToolhelp32Snapshot : %w", err)
	}
	defer windows.CloseHandle(snapshot)

	var entry windows.ProcessEntry32
	entry.Size = uint32(unsafe.Sizeof(entry))
	if err := windows.Process32First(snapshot, &entry); err != nil {
		return nil, fmt.Errorf("Process32First : %w", err)
	}

	pids := []uint32{}
	for {
		if strings.EqualFold(windows.UTF16ToString(entry.ExeFile[:]), "explorer.exe") {
			var pidSession uint32
			if err := windows.ProcessIdToSessionId(entry.ProcessID, &pidSession); err == nil && pidSession == session {
				pids = append(pids, entry.ProcessID)
			}
		}
		if err := windows.Process32Next(snapshot, &entry); err != nil {
			break // ERROR_NO_MORE_FILES : fin d'énumération
		}
	}

	return pids, nil
}

// terminateProcess : TerminateProcess sur un PID (droits du compagnon — un
// processus d'une autre session/d'un autre user rend ACCESS_DENIED, c'est la
// défense en profondeur naturelle).
func terminateProcess(pid uint32) error {
	handle, err := windows.OpenProcess(windows.PROCESS_TERMINATE, false, pid)
	if err != nil {
		return fmt.Errorf("OpenProcess(pid=%d) : %w", pid, err)
	}
	defer windows.CloseHandle(handle)

	if err := windows.TerminateProcess(handle, 0); err != nil {
		return fmt.Errorf("TerminateProcess(pid=%d) : %w", pid, err)
	}

	return nil
}
