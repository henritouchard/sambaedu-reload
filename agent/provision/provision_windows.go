//go:build windows

package provision

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// KindWpkgTool est le `kind` des outils partagés WPKG (7za.exe, nircmd.exe,
// tooltip/*) — cf. `manifest.json` servi sous l'alias `/wpkg/tools`.
const KindWpkgTool = "wpkg-tool"

// WindowsResolver place les ressources aux emplacements ATTENDUS par le poste
// Windows. Pour `kind:"wpkg-tool"` : `%WinDir%\install\wpkg\tools\<RelPath>` —
// EXACTEMENT le chemin que `wpkg-se4.js` résout pour `%Z%\wpkg\tools` (MapZ()
// de `wpkg-client.vbs` renvoie `c:\windows\install`, sans montage SMB — vérifié).
//
// La sous-arborescence (`tooltip/…`) est préservée via RelPath. Le resolver
// GARANTIT l'existence du dossier cible (MkdirAll) et MATÉRIALISE `%Z%`
// (`c:\windows\install`) en VRAI dossier local : sur un poste MIGRÉ, ce chemin
// pouvait être un reparse point SMB pendouillant (legacy `MKLINK /D` vers
// `\\%SE4FS%\install`, partage débranché en SE5) — on le détecte et on le retire
// avant de créer le dossier local (sinon MkdirAll échoue ou pose sous un lien mort).
// Sur greenfield SE5, `c:\windows\install\wpkg` existe déjà (créé par le GPO
// bootstrap startup.cmd) en dossier réel : la détection est alors un no-op.
type WindowsResolver struct {
	// WinDir : racine Windows (`%WinDir%`, défaut `C:\Windows`). Injectable pour
	// les tests ; en production = os.Getenv("WinDir").
	WinDir string
	// Log : callback de log optionnel (warnings de matérialisation %Z%). nil = silencieux.
	Log func(format string, args ...any)
}

// NewWindowsResolver construit le resolver de production (WinDir depuis %WinDir%).
func NewWindowsResolver(log func(format string, args ...any)) *WindowsResolver {
	winDir := os.Getenv("WinDir")
	if winDir == "" {
		winDir = `C:\Windows`
	}

	return &WindowsResolver{WinDir: winDir, Log: log}
}

func (w *WindowsResolver) logf(format string, args ...any) {
	if w.Log != nil {
		w.Log(format, args...)
	}
}

// installRoot : `%WinDir%\install` (= `%Z%`).
func (w *WindowsResolver) installRoot() string {
	return filepath.Join(w.winDir(), "install")
}

func (w *WindowsResolver) winDir() string {
	if w.WinDir != "" {
		return w.WinDir
	}

	return `C:\Windows`
}

// Resolve renvoie le chemin absolu local de la ressource et garantit son dossier
// parent. Seul `kind:"wpkg-tool"` est connu (les autres kinds → erreur explicite,
// jamais un placement deviné).
func (w *WindowsResolver) Resolve(r Resource) (string, error) {
	if r.Kind != KindWpkgTool {
		return "", fmt.Errorf("kind non supporté par WindowsResolver : %q (attendu %q)", r.Kind, KindWpkgTool)
	}

	// Matérialiser %Z% (= install root) en vrai dossier local avant tout dépôt.
	if err := w.materializeInstallRoot(); err != nil {
		return "", err
	}

	rel := filepath.FromSlash(strings.TrimPrefix(r.RelPath, "/"))
	abs := filepath.Join(w.installRoot(), "wpkg", "tools", rel)
	if err := ensureParentDir(abs); err != nil {
		return "", fmt.Errorf("création du dossier cible pour %s : %w", r.ID, err)
	}

	return abs, nil
}

// materializeInstallRoot garantit que `%WinDir%\install` est un VRAI dossier
// local. Sur un poste migré, ce chemin peut être un reparse point (symlink SMB
// legacy pendouillant) : on le détecte (FileInfo mode reparse) et on le retire
// (os.Remove ne supprime que le lien, pas la cible distante) avant de recréer un
// dossier local. Idempotent : un dossier réel existant n'est pas touché.
func (w *WindowsResolver) materializeInstallRoot() error {
	root := w.installRoot()

	info, err := os.Lstat(root)
	switch {
	case os.IsNotExist(err):
		// Absent : créer le dossier local.
		return os.MkdirAll(root, 0o755)
	case err != nil:
		return fmt.Errorf("inspection de %s : %w", root, err)
	}

	// Présent : est-ce un reparse point (symlink/jonction SMB legacy) ?
	if info.Mode()&os.ModeSymlink != 0 || isReparsePoint(root) {
		w.logf("⚠ %s est un reparse point legacy (montage SMB débranché) — matérialisation en dossier local", root)
		if err := os.Remove(root); err != nil {
			return fmt.Errorf("retrait du reparse point %s : %w", root, err)
		}

		return os.MkdirAll(root, 0o755)
	}

	// Déjà un vrai dossier (ou un fichier — improbable) : MkdirAll est idempotent.
	return os.MkdirAll(root, 0o755)
}

// isReparsePoint détecte un point d'analyse NTFS (symlink/jonction) là où Lstat
// seul ne suffit pas toujours (jonctions de répertoire). Repli locale-agnostique
// via `fsutil reparsepoint query` (code de sortie 0 = c'est un reparse point).
func isReparsePoint(path string) bool {
	cmd := exec.Command("fsutil", "reparsepoint", "query", path)
	cmd.Stdout = nil
	cmd.Stderr = nil

	return cmd.Run() == nil
}
