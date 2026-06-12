package main

import (
	"fmt"
	"os/exec"
	"strings"
	"syscall"
)

// Tâches planifiées at-logon du sous-système compagnon (Story 24.6,
// décision n° 3) — enregistrées par `agent.exe install`, supprimées par
// `uninstall` :
//
//   - SambaEduAgent-SessionFetch : principal SYSTEM (S-1-5-18, seul
//     détenteur du token), `agent.exe session-fetch`, ExecutionTimeLimit
//     10 min (bornée par les timeouts HTTP — 30 s/requête, plusieurs
//     sessions + réessais, review 24.3 #8) ;
//   - SambaEduAgent-SessionCompanion : principal groupe BUILTIN\Users
//     (S-1-5-32-545, traduit par API — jamais de nom localisé en dur),
//     `agent.exe companion`, SANS limite d'exécution — le compagnon est
//     RÉSIDENT (boucle 24.4 : poll mtime + re-test périodique), une limite
//     le tuerait après la première passe (piège 24.4 n° 9). Le processus
//     meurt au logoff ; MultipleInstances IgnoreNew empêche le doublon.
//
// Implémentation : shell-out `powershell Register-ScheduledTask`
// (échappatoire EXPLICITEMENT admise par l'addendum architecture — le Task
// Scheduler natif est du COM, exclu par la règle Rust/COM-WinRT, et
// schtasks.exe gère mal les principals de groupe). Idempotent : unregister
// si présentes — les tâches PS HOMONYMES héritées du spike 24.3 (poste lab
// ws 49) sont désenregistrées par la même voie (piège n° 21).
//
// NFR1 : déclencheur At log on = tâches ASYNCHRONES, en parallèle de
// l'ouverture de session — rien dans le chemin synchrone du logon (jamais
// Winlogon/Userinit/GPO logon script).

const (
	taskSessionFetch     = "SambaEduAgent-SessionFetch"
	taskSessionCompanion = "SambaEduAgent-SessionCompanion"
)

// psQuote : littéral PowerShell single-quoted (doublage des quotes).
func psQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", "''") + "'"
}

func runPowershell(script string) error {
	cmd := exec.Command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("powershell : %w (%s)", err, strings.TrimSpace(string(out)))
	}

	return nil
}

// registerSessionTasks enregistre (ou ré-enregistre) les 2 tâches at-logon,
// pointées sur le binaire exe (chemin définitif enregistré par install).
func registerSessionTasks(exe string) error {
	script := `
$ErrorActionPreference = 'Stop'

# Idempotence + reprise des tâches PS homonymes du spike : suppression avant
# recréation.
foreach ($name in @(` + psQuote(taskSessionFetch) + `, ` + psQuote(taskSessionCompanion) + `)) {
    if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $name -Confirm:$false
    }
}

$exe = ` + psQuote(exe) + `
$trigger = New-ScheduledTaskTrigger -AtLogOn

# SessionFetch : SYSTEM (S-1-5-18), borne 10 min (timeouts HTTP).
$action = New-ScheduledTaskAction -Execute $exe -Argument 'session-fetch'
$principal = New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries ` + "`" + `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName ` + psQuote(taskSessionFetch) + ` -Action $action -Trigger $trigger ` + "`" + `
    -Principal $principal -Settings $settings ` + "`" + `
    -Description 'SambaEdu SE5 : fetch SYSTEM de l''etat de session (GET /state?user=) au logon -> cache per-user (agent Go 24.6).' | Out-Null

# SessionCompanion : groupe BUILTIN\Users (SID traduit par API, jamais de nom
# localise), droits de la session. ExecutionTimeLimit ZERO = ILLIMITE,
# DELIBERE : le compagnon est RESIDENT (boucle poll/re-test), une limite le
# tuerait apres la premiere passe ; il meurt de lui-meme au logoff.
$usersSid = New-Object System.Security.Principal.SecurityIdentifier('S-1-5-32-545')
$usersGroup = $usersSid.Translate([System.Security.Principal.NTAccount]).Value
$action = New-ScheduledTaskAction -Execute $exe -Argument 'companion'
$principal = New-ScheduledTaskPrincipal -GroupId $usersGroup -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries ` + "`" + `
    -ExecutionTimeLimit (New-TimeSpan -Seconds 0) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName ` + psQuote(taskSessionCompanion) + ` -Action $action -Trigger $trigger ` + "`" + `
    -Principal $principal -Settings $settings ` + "`" + `
    -Description 'SambaEdu SE5 : compagnon de session (droits user, resident) — portees session + machine_user depuis le cache per-user (agent Go 24.6).' | Out-Null
`

	if err := runPowershell(script); err != nil {
		return fmt.Errorf("enregistrement des tâches planifiées : %w", err)
	}

	return nil
}

// unregisterSessionTasks supprime les 2 tâches (uninstall — les données du
// poste sont gérées par ailleurs, flag -purge inchangé).
func unregisterSessionTasks() error {
	script := `
foreach ($name in @(` + psQuote(taskSessionFetch) + `, ` + psQuote(taskSessionCompanion) + `)) {
    if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $name -Confirm:$false
    }
}
`

	if err := runPowershell(script); err != nil {
		return fmt.Errorf("suppression des tâches planifiées : %w", err)
	}

	return nil
}
