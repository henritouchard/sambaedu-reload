// Impl Windows de LegacyCleanupOps (Story 38.3, contrat §7.10) — service
// SYSTEM seul. La LOGIQUE (catalogue, gardes, idempotence) vit dans
// agent/shared/handler_legacy_cleanup.go ; ce fichier n'apporte que les
// primitives OS :
//   - fichiers : os.* (Remove ciblé JAMAIS récursif ; RemoveAll réservé par le
//     handler à C:\Netinst — %WINDIR%\Web\SE4 en forme conservatrice, review
//     38.3 #2) ;
//   - reparse points : détection iso provision_windows.go (Lstat + repli
//     locale-agnostique `fsutil reparsepoint query`) — un vrai dossier
//     `%WinDir%\install` (provisioning natif 27.20) reste INTOUCHABLE ;
//   - tâches planifiées : shell-out powershell (échappatoire admise par
//     l'addendum architecture, iso tasks_windows.go — le Task Scheduler natif
//     est du COM) : lecture de l'ACTION (garde de contenu) + Unregister ;
//   - registre : DÉLÈGUE au registryOps existant (RegistryOps.Read/Delete de
//     handler_registry_windows.go — jamais la clé-conteneur).
package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"

	"sambaedu/agent/shared"
)

type legacyCleanupOps struct {
	log *shared.Logger
	reg *registryOps
}

// newLegacyCleanupHandler assemble le handler avec les racines RÉELLES du
// poste (env) — les défauts shared s'appliquent si une variable manque.
func newLegacyCleanupHandler(logger *shared.Logger) *shared.LegacyCleanupHandler {
	systemDrive := os.Getenv("SystemDrive")
	if systemDrive == "" {
		systemDrive = "C:"
	}
	winDir := os.Getenv("WinDir")
	if winDir == "" {
		winDir = systemDrive + `\Windows`
	}
	programFiles := []string{}
	for _, env := range []string{"ProgramFiles", "ProgramFiles(x86)"} {
		if dir := os.Getenv(env); dir != "" {
			programFiles = append(programFiles, dir)
		}
	}
	if len(programFiles) == 0 {
		programFiles = []string{systemDrive + `\Program Files`, systemDrive + `\Program Files (x86)`}
	}

	return &shared.LegacyCleanupHandler{
		Ops:          &legacyCleanupOps{log: logger, reg: &registryOps{log: logger}},
		Log:          logger,
		WinDir:       winDir,
		UsersDir:     systemDrive + `\Users`,
		ProgramFiles: programFiles,
		NetinstDir:   systemDrive + `\Netinst`,
	}
}

// --- Fichiers -------------------------------------------------------------------

// Glob : équivalent filepath.Glob mais INSENSIBLE à la casse sur le dernier
// segment (review 38.3 #4) — NTFS est insensible, filepath.Match ne l'est pas
// (un `Applications-Logon.CMD` échapperait au motif minuscule). Un seul
// ReadDir, pas de fsutil (contrairement à ListDir, réservé aux gardes reparse).
func (o *legacyCleanupOps) Glob(pattern string) ([]string, error) {
	dir, base := filepath.Split(pattern)
	dir = strings.TrimSuffix(dir, `\`)
	entries, err := os.ReadDir(dir)
	if os.IsNotExist(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var out []string
	for _, entry := range entries {
		ok, err := filepath.Match(strings.ToLower(base), strings.ToLower(entry.Name()))
		if err != nil {
			return nil, err
		}
		if ok {
			out = append(out, filepath.Join(dir, entry.Name()))
		}
	}

	return out, nil
}

func (o *legacyCleanupOps) ReadFile(path string) ([]byte, error) {
	return os.ReadFile(path)
}

func (o *legacyCleanupOps) WriteFile(path string, data []byte) error {
	return os.WriteFile(path, data, 0o644)
}

// Remove : suppression CIBLÉE (fichier, lien/jonction — le lien seul —, ou
// dossier vide). Déjà absent ⇒ nil (idempotent, contrat de l'op).
func (o *legacyCleanupOps) Remove(path string) error {
	if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
		return err
	}

	return nil
}

// RemoveAll : récursif — le handler ne l'appelle QUE sur C:\Netinst et
// %WINDIR%\Web\SE4 (piège #4, chemins exclusivement legacy).
func (o *legacyCleanupOps) RemoveAll(path string) error {
	return os.RemoveAll(path)
}

func (o *legacyCleanupOps) Stat(path string) (shared.LegacyPathInfo, error) {
	info, err := os.Lstat(path)
	if os.IsNotExist(err) {
		return shared.LegacyPathInfo{}, nil
	}
	if err != nil {
		return shared.LegacyPathInfo{}, err
	}

	// fsutil (spawn) UNIQUEMENT pour les entrées non régulières : les jonctions
	// visées (install/rapports/Netinst) ne sont jamais des fichiers réguliers —
	// éviter un spawn par fichier staté (profiles.ini de CHAQUE profil, chaque
	// convergence — review 38.3 #3).
	isReparse := info.Mode()&os.ModeSymlink != 0
	if !isReparse && !info.Mode().IsRegular() {
		isReparse = isLegacyReparsePoint(path)
	}

	return shared.LegacyPathInfo{
		Exists:    true,
		IsDir:     info.IsDir(),
		IsReparse: isReparse,
	}, nil
}

func (o *legacyCleanupOps) ListDir(path string) ([]shared.LegacyDirEntry, error) {
	entries, err := os.ReadDir(path)
	if os.IsNotExist(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := make([]shared.LegacyDirEntry, 0, len(entries))
	for _, entry := range entries {
		full := filepath.Join(path, entry.Name())
		reparse := entry.Type()&os.ModeSymlink != 0
		if !reparse && entry.IsDir() {
			// Les jonctions de répertoire n'apparaissent pas toujours en
			// ModeSymlink (iso provision_windows.go) : repli fsutil.
			reparse = isLegacyReparsePoint(full)
		}
		out = append(out, shared.LegacyDirEntry{
			Name:      entry.Name(),
			IsDir:     entry.IsDir(),
			IsReparse: reparse,
		})
	}

	return out, nil
}

// isLegacyReparsePoint : détection locale-agnostique d'un point d'analyse NTFS
// (jonction/symlink) — MÊME repli que provision_windows.go (`fsutil
// reparsepoint query`, code de sortie 0 = reparse point).
func isLegacyReparsePoint(path string) bool {
	cmd := exec.Command("fsutil", "reparsepoint", "query", path)
	cmd.Stdout = nil
	cmd.Stderr = nil
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}

	return cmd.Run() == nil
}

// --- Tâches planifiées ------------------------------------------------------------

// TaskAction : lit l'ACTION (Execute + Arguments) d'une tâche à la RACINE du
// Task Scheduler — la GARDE de contenu (référence gpo/applications.php|wpkg)
// est évaluée côté shared. Tâche absente ⇒ ("", false, nil).
func (o *legacyCleanupOps) TaskAction(name string) (string, bool, error) {
	script := `
$t = Get-ScheduledTask -TaskPath '\' -TaskName ` + psQuote(name) + ` -ErrorAction SilentlyContinue
if ($null -eq $t) { exit 3 }
foreach ($a in $t.Actions) { Write-Output ($a.Execute + ' ' + $a.Arguments) }
`
	out, code, err := runPowershellOutput(script)
	if code == 3 {
		return "", false, nil
	}
	if err != nil {
		return "", false, fmt.Errorf("lecture de la tâche %q : %w", name, err)
	}

	return strings.TrimSpace(out), true, nil
}

// DeleteTask : désenregistre la tâche (déjà absente ⇒ nil, idempotent).
func (o *legacyCleanupOps) DeleteTask(name string) error {
	script := `
if (Get-ScheduledTask -TaskPath '\' -TaskName ` + psQuote(name) + ` -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskPath '\' -TaskName ` + psQuote(name) + ` -Confirm:$false
}
`
	if err := runPowershell(script); err != nil {
		return fmt.Errorf("suppression de la tâche %q : %w", name, err)
	}

	return nil
}

// runPowershellOutput : variante de runPowershell qui CAPTURE stdout et le
// code de sortie (la lecture d'action de tâche a besoin des deux).
func runPowershellOutput(script string) (string, int, error) {
	cmd := exec.Command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	out, err := cmd.Output()
	if err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			return string(out), exitErr.ExitCode(), fmt.Errorf("powershell : code %d (%s)", exitErr.ExitCode(), strings.TrimSpace(string(exitErr.Stderr)))
		}

		return string(out), -1, fmt.Errorf("powershell : %w", err)
	}

	return string(out), 0, nil
}

// --- Registre (délégation au registryOps existant) ---------------------------------

func (o *legacyCleanupOps) RegistryRead(hive, path, name string) (shared.RegistryValue, bool, error) {
	return o.reg.Read(hive, path, name)
}

func (o *legacyCleanupOps) RegistryDelete(hive, path, name string) error {
	return o.reg.Delete(hive, path, name)
}
