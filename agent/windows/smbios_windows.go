package main

import (
	"os/exec"
	"strings"
	"syscall"

	"sambaedu/agent/shared"
)

// smbiosUUID retourne le fournisseur d'UUID SMBIOS du rapport
// (workstation.uuid, envoyé VERBATIM — la normalisation minuscules est côté
// serveur).
//
// Implémentation : shell-out PowerShell Get-CimInstance — choix de la story
// (décision n° 3, option b) : échappatoire explicitement admise par
// l'addendum architecture, zéro dépendance Go supplémentaire (go-smbios
// écarté), et EXACTEMENT la source du spike 24.2 (Win32_ComputerSystemProduct,
// donc même valeur rapportée qu'avant la bascule Go).
//
// Échec (WinMgmt en réparation, CIM transitoirement indisponible — review
// 24.4 #1) → chaîne vide : le rapport part QUAND MÊME (champ déclaratif,
// l'identité réelle est le token).
func smbiosUUID(log *shared.Logger) func() string {
	return func() string {
		cmd := exec.Command("powershell.exe",
			"-NoProfile", "-NonInteractive", "-Command",
			"(Get-CimInstance -ClassName Win32_ComputerSystemProduct).UUID")
		cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
		out, err := cmd.Output()
		if err != nil {
			log.Warningf("Lecture UUID SMBIOS (CIM) en échec : %v — rapport envoyé avec uuid vide.", err)

			return ""
		}

		return strings.TrimSpace(string(out))
	}
}
