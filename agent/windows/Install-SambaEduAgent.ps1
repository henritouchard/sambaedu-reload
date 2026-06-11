# =============================================================================
# Install-SambaEduAgent.ps1 — Installation MANUELLE du service (Story 24.2, AC8)
# =============================================================================
# Chemin d'installation MVP lab : execute par un admin sur le poste (la
# distribution automatique bootstrap/auto-update est la story 25.x).
#
#   .\Install-SambaEduAgent.ps1 -ServerUrl 'http://se5.mondomaine.lan'
#
# Ce que fait le script :
#   1. copie l'agent (SambaEduAgent.ps1 + ContractV1.ps1 + SessionStateFetch.ps1
#      + SessionCompanion.ps1) vers C:\Program Files\SambaEdu\Agent\
#      (lisible user par defaut — requis par SessionCompanion, piege n° 7) ;
#   2. ecrit C:\ProgramData\SambaEdu\Agent\config.json (server_url, interval) ;
#   3. compile un wrapper ServiceBase minimal (SambaEduAgentService.exe) via
#      Add-Type — un .ps1 ne parle pas le protocole SCM, New-Service sur
#      powershell.exe seul serait tue au timeout de demarrage ;
#   4. New-Service compte SYSTEM, demarrage automatique, relance auto 30 s
#      sur crash (sc.exe failure) ;
#   5. enregistre les 2 taches planifiees at-logon du compagnon de session
#      (Story 24.3) : SambaEduAgent-SessionFetch (SYSTEM) +
#      SambaEduAgent-SessionCompanion (BUILTIN\Users, droits de la session) —
#      asynchrones par construction, RIEN dans le chemin synchrone du logon
#      (NFR1) ;
#   6. demarre le service (premiere boucle immediate).
#
# Pre-requis : poste enrole (C:\ProgramData\SambaEdu\Agent\token pose par la
# chaine iPXE 23.3) + CA interne SambaEdu-RootCA deployee (23.3) si la
# politique d'execution exige des scripts signes (artefacts dist/ signes).
# =============================================================================

#Requires -Version 5.1
#Requires -RunAsAdministrator

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$ServerUrl,
    [int]$IntervalSeconds = 3600,
    [string]$ServiceName = 'SambaEduAgent'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$installDir = 'C:\Program Files\SambaEdu\Agent'
$dataDir = 'C:\ProgramData\SambaEdu\Agent'
$configPath = Join-Path $dataDir 'config.json'
$serviceExe = Join-Path $installDir 'SambaEduAgentService.exe'

# --- 0. Garde : le poste doit etre enrole (token 23.3 present) ---------------
$tokenPath = Join-Path $dataDir 'token'
if (-not (Test-Path $tokenPath)) {
    throw "Token agent introuvable ($tokenPath) : le poste n'est pas enrole (chaine iPXE 23.3). Installation interrompue."
}

# --- 1. Copie des fichiers de l'agent ----------------------------------------
if (-not (Test-Path $installDir)) {
    New-Item -ItemType Directory -Path $installDir -Force | Out-Null
}
foreach ($name in @('SambaEduAgent.ps1', 'SessionStateFetch.ps1', 'SessionCompanion.ps1')) {
    Copy-Item -Path (Join-Path $PSScriptRoot $name) -Destination $installDir -Force
}
# ContractV1.ps1 : a cote du script d'install (artefact dist/) ou ..\shared (repo).
$contract = Join-Path $PSScriptRoot 'ContractV1.ps1'
if (-not (Test-Path $contract)) {
    $contract = Join-Path (Split-Path $PSScriptRoot -Parent) 'shared\ContractV1.ps1'
}
Copy-Item -Path $contract -Destination $installDir -Force

# --- 2. Configuration locale (server_url + cadence, D7 : 3600 s par defaut) --
$config = [ordered]@{
    server_url       = $ServerUrl.TrimEnd('/')
    interval_seconds = $IntervalSeconds
} | ConvertTo-Json
Set-Content -Path $configPath -Value $config -Encoding UTF8
& icacls.exe $configPath /inheritance:r /grant '*S-1-5-18:F' '*S-1-5-32-544:F' | Out-Null

# --- 3. Wrapper ServiceBase (SCM) --------------------------------------------
# Lance powershell.exe -File SambaEduAgent.ps1 en fils, le tue a l'arret du
# service. AllSigned-compatible : le .ps1 signe est execute tel quel.
$wrapperSource = @"
using System;
using System.Diagnostics;
using System.ServiceProcess;

public class SambaEduAgentService : ServiceBase
{
    private Process _child;

    public SambaEduAgentService()
    {
        ServiceName = "$ServiceName";
        CanStop = true;
    }

    protected override void OnStart(string[] args)
    {
        var psi = new ProcessStartInfo
        {
            FileName = Environment.ExpandEnvironmentVariables(
                @"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"),
            Arguments = "-NoProfile -NonInteractive -File \"" + @"$installDir\SambaEduAgent.ps1" + "\"",
            UseShellExecute = false,
            CreateNoWindow = true
        };
        _child = Process.Start(psi);
    }

    protected override void OnStop()
    {
        if (_child != null && !_child.HasExited)
        {
            _child.Kill();
        }
    }

    public static void Main()
    {
        ServiceBase.Run(new SambaEduAgentService());
    }
}
"@

if (Test-Path $serviceExe) { Remove-Item $serviceExe -Force }
Add-Type -TypeDefinition $wrapperSource `
    -OutputAssembly $serviceExe `
    -OutputType ConsoleApplication `
    -ReferencedAssemblies 'System.ServiceProcess', 'System'

# --- 4. Enregistrement du service (SYSTEM, auto, relance 30 s) ----------------
$existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($null -ne $existing) {
    Write-Host "Service $ServiceName deja present : arret + suppression avant reinstallation."
    if ($existing.Status -ne 'Stopped') { Stop-Service -Name $ServiceName -Force }
    & sc.exe delete $ServiceName | Out-Null
    # sc.exe delete est asynchrone ("marked for deletion") : attendre la
    # disparition effective avant New-Service, sinon echec sur le meme nom.
    $deadline = (Get-Date).AddSeconds(30)
    while ((Get-Service -Name $ServiceName -ErrorAction SilentlyContinue) -and (Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 1
    }
    if (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue) {
        throw "Service $ServiceName toujours marque pour suppression apres 30 s (handle SCM ouvert ? fermer services.msc) : reinstallation impossible pour l'instant."
    }
}

New-Service -Name $ServiceName `
    -BinaryPathName "`"$serviceExe`"" `
    -DisplayName 'SambaEdu Agent (desired-state)' `
    -Description 'Agent SambaEdu SE5 : convergence etat cible + rapport de conformite (Epic 24).' `
    -StartupType Automatic | Out-Null

# Compte SYSTEM (New-Service utilise LocalSystem par defaut — explicite ici)
# + relance automatique 30 s sur crash (AC : service resilient).
& sc.exe config $ServiceName obj= 'LocalSystem' | Out-Null
& sc.exe failure $ServiceName reset= 86400 actions= 'restart/30000/restart/30000/restart/30000' | Out-Null

# --- 5. Taches planifiees du compagnon de session (Story 24.3) ----------------
# Deux taches at-logon, asynchrones a l'ouverture (NFR1 : AUCUN script
# Winlogon/Userinit/GPO — le logon n'attend jamais rien du reseau) :
#   - SessionFetch : SYSTEM (seul detenteur du token), GET /state?user= pour
#     chaque session interactive -> cache per-user ;
#   - SessionCompanion : groupe Users -> s'execute dans la session du user
#     qui ouvre, avec SES droits (frontiere de confiance NFR5).
# Principals par SID (jamais de nom localise) : S-1-5-18 = SYSTEM ;
# S-1-5-32-545 = BUILTIN\Users, traduit en nom local pour -GroupId.
$psExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$usersSid = New-Object System.Security.Principal.SecurityIdentifier('S-1-5-32-545')
$usersGroup = $usersSid.Translate([System.Security.Principal.NTAccount]).Value

$tasks = @(
    @{
        Name        = 'SambaEduAgent-SessionFetch'
        Script      = 'SessionStateFetch.ps1'
        Principal   = New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -LogonType ServiceAccount -RunLevel Highest
        # Borne par les timeouts HTTP (30 s/requete, plusieurs sessions + reessais).
        TimeLimit   = New-TimeSpan -Minutes 10
        Description = 'SambaEdu SE5 : fetch SYSTEM de l''etat de session (GET /state?user=) au logon -> cache per-user (Story 24.3).'
    },
    @{
        Name        = 'SambaEduAgent-SessionCompanion'
        Script      = 'SessionCompanion.ps1'
        Principal   = New-ScheduledTaskPrincipal -GroupId $usersGroup -RunLevel Limited
        # Poll 60 s max + parse/log : 2 min suffisent (review 24.3 #8).
        TimeLimit   = New-TimeSpan -Minutes 2
        Description = 'SambaEdu SE5 : compagnon de session (droits user) — portees session + machine_user depuis le cache per-user (Story 24.3).'
    }
)

foreach ($task in $tasks) {
    # Idempotence iso-service : suppression puis recreation.
    if (Get-ScheduledTask -TaskName $task.Name -ErrorAction SilentlyContinue) {
        Write-Host "Tache $($task.Name) deja presente : suppression avant recreation."
        Unregister-ScheduledTask -TaskName $task.Name -Confirm:$false
    }

    $action = New-ScheduledTaskAction -Execute $psExe `
        -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -File "{0}"' -f (Join-Path $installDir $task.Script))
    $trigger = New-ScheduledTaskTrigger -AtLogOn
    # Garde-fous : limite d'execution PAR TACHE (cf. TimeLimit ci-dessus),
    # pas de cumul d'instances.
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
        -ExecutionTimeLimit $task.TimeLimit -MultipleInstances IgnoreNew

    Register-ScheduledTask -TaskName $task.Name -Action $action -Trigger $trigger `
        -Principal $task.Principal -Settings $settings -Description $task.Description | Out-Null
    Write-Host "Tache planifiee $($task.Name) enregistree (declencheur : ouverture de session)."
}

# --- 6. Demarrage : premiere boucle immediate ---------------------------------
Start-Service -Name $ServiceName
Write-Host "Service $ServiceName installe et demarre (SYSTEM, demarrage automatique, relance 30 s)."
Write-Host "Log local : C:\ProgramData\SambaEdu\Agent\logs\agent.log"
Write-Host "Log compagnon (par user) : %LOCALAPPDATA%\SambaEdu\Agent\companion.log"
