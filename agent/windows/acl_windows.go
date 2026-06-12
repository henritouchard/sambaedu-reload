package main

import (
	"fmt"
	"os/exec"
	"syscall"
)

// setAgentACL pose l'ACL du canal agent sur un fichier/répertoire :
// NT AUTHORITY\SYSTEM + BUILTIN\Administrators UNIQUEMENT, héritage retiré —
// un utilisateur standard ne lit NI n'écrit (frontière de confiance,
// convention 23.3).
//
// Shell-out icacls.exe : échappatoire DOCUMENTÉE et acceptée (iso-24.2,
// addendum architecture 2026-06-12 — les API ACL de golang.org/x/sys sont
// inutilement pénibles pour ce besoin). SIDs bruts, jamais de noms localisés :
// *S-1-5-18 = SYSTEM, *S-1-5-32-544 = Administrators. (OI)(CI) : les
// fichiers/sous-répertoires héritent.
func setAgentACL(path string) error {
	cmd := exec.Command("icacls.exe", path,
		"/inheritance:r",
		"/grant", "*S-1-5-18:(OI)(CI)F",
		"/grant", "*S-1-5-32-544:(OI)(CI)F",
	)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("icacls %s : %w (%s)", path, err, out)
	}

	return nil
}
