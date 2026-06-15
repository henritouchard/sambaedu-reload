package main

import (
	"fmt"
	"os"
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
	info, err := os.Stat(path)
	if err != nil {
		return fmt.Errorf("stat %s : %w", path, err)
	}
	// (OI)(CI) UNIQUEMENT sur les répertoires. Posés via icacls directement
	// sur un FICHIER, ces flags rendent les ACE inertes pour l'accès au
	// fichier lui-même : DACL effective VIDE, le rename de writeAtomic
	// échoue en Accès refusé — constaté lab ws 49 (T12 24.6, A/B icacls
	// avec/sans flags), invisible des tests hôte (icacls = Windows réel).
	if info.IsDir() {
		return runIcacls(path,
			"/inheritance:r",
			"/grant", "*S-1-5-18:(OI)(CI)F",
			"/grant", "*S-1-5-32-544:(OI)(CI)F",
		)
	}

	return runIcacls(path,
		"/inheritance:r",
		"/grant", "*S-1-5-18:F",
		"/grant", "*S-1-5-32-544:F",
	)
}

// setSessionCacheACL : ACL du répertoire de cache per-SID (Story 24.6,
// contrat 24.3) — le user LIT son état ((OI) propage le R aux fichiers),
// n'écrit rien, ne lit pas le cache d'un autre SID. Les fichiers héritent
// (jamais de ré-ACL des tmp : un icacls SYSTEM+Admins retirerait le R).
func setSessionCacheACL(path, sid string) error {
	return runIcacls(path,
		"/inheritance:r",
		"/grant", "*S-1-5-18:(OI)(CI)F",
		"/grant", "*S-1-5-32-544:(OI)(CI)F",
		"/grant", "*"+sid+":(OI)(CI)R",
	)
}

// setSessionReportACL : ACL du répertoire de drop per-SID (Story 24.6,
// contrat 24.4) — grant <SID>:(OI)(CI)M (Modify) : le user ÉCRIT son
// session-report.json (le M couvre création/rename/suppression — écriture
// atomique tmp PID + rename), ne lit pas les drops des autres SID.
func setSessionReportACL(path, sid string) error {
	return runIcacls(path,
		"/inheritance:r",
		"/grant", "*S-1-5-18:(OI)(CI)F",
		"/grant", "*S-1-5-32-544:(OI)(CI)F",
		"/grant", "*"+sid+":(OI)(CI)M",
	)
}

// setAssetsACL : ACL du cache d'assets (Story 24.6, contrat 24.4) —
// BUILTIN\Users (*S-1-5-32-545) LECTURE : un wallpaper n'est pas un secret
// et la session doit pouvoir l'afficher. (OI)(CI) : les fichiers héritent.
func setAssetsACL(path string) error {
	return runIcacls(path,
		"/inheritance:r",
		"/grant", "*S-1-5-18:(OI)(CI)F",
		"/grant", "*S-1-5-32-544:(OI)(CI)F",
		"/grant", "*S-1-5-32-545:(OI)(CI)R",
	)
}

// setOverlayFileACL : ACL du FICHIER overlay.json écrit par SYSTEM au logon
// (Story 27.1bis, D1/D2). SYSTEM + Administrators FULL, <SID>:R (Read) —
// héritage retiré : SYSTEM possède/écrit, l'élève LIT mais ne FALSIFIE JAMAIS
// la donnée affichée (NFR5). Posée sur le FICHIER (le dossier %LOCALAPPDATA%
// appartient au user) : PAS de (OI)(CI) — ces flags rendraient la DACL
// effective vide sur un fichier (acquis lab ws 49, T12 24.6 — cf. setAgentACL).
func setOverlayFileACL(path, sid string) error {
	return runIcacls(path,
		"/inheritance:r",
		"/grant", "*S-1-5-18:F",
		"/grant", "*S-1-5-32-544:F",
		"/grant", "*"+sid+":R",
	)
}

func runIcacls(path string, args ...string) error {
	cmd := exec.Command("icacls.exe", append([]string{path}, args...)...)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("icacls %s : %w (%s)", path, err, out)
	}

	return nil
}
