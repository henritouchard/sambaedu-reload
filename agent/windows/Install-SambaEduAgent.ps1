# =============================================================================
# Install-SambaEduAgent.ps1 — Installation MANUELLE du service (Story 24.2, AC8)
# =============================================================================
# Chemin d'installation MVP lab : execute par un admin sur le poste (la
# distribution automatique bootstrap/auto-update est la story 25.x).
#
#   .\Install-SambaEduAgent.ps1 -ServerUrl 'http://se5.mondomaine.lan'
#
# Ce que fait le script :
#   1. copie l'agent (SambaEduAgent.ps1 + ContractV1.ps1) vers
#      C:\Program Files\SambaEdu\Agent\ ;
#   2. ecrit C:\ProgramData\SambaEdu\Agent\config.json (server_url, interval) ;
#   3. compile un wrapper ServiceBase minimal (SambaEduAgentService.exe) via
#      Add-Type — un .ps1 ne parle pas le protocole SCM, New-Service sur
#      powershell.exe seul serait tue au timeout de demarrage ;
#   4. New-Service compte SYSTEM, demarrage automatique, relance auto 30 s
#      sur crash (sc.exe failure) ;
#   5. demarre le service (premiere boucle immediate).
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
Copy-Item -Path (Join-Path $PSScriptRoot 'SambaEduAgent.ps1') -Destination $installDir -Force
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

# --- 5. Demarrage : premiere boucle immediate ---------------------------------
Start-Service -Name $ServiceName
Write-Host "Service $ServiceName installe et demarre (SYSTEM, demarrage automatique, relance 30 s)."
Write-Host "Log local : C:\ProgramData\SambaEdu\Agent\logs\agent.log"
