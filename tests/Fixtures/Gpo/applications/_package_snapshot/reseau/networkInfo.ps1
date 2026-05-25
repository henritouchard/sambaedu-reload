# Lance en tâche de fond la capture des trames LLDP ou CDP durant 62s pour
# récupérer les infos port, vlan et ip du switch
# Les infos sont ensuite envoyés au SE4FS pour être ajoutés dans la table machines

$moduleDefinition = {
    function Write-Log {
        [CmdletBinding()]
        param(
            [Parameter(ValueFromPipeline = $True, Mandatory = $True)][String]$Message,
            [Parameter(Mandatory = $True)][String]$FilePath,
            [Switch]$New
        )
        If ($New) {
            New-Item -Path $FilePath -ItemType File -Force
        }
        $Timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
        Add-Content -Force -Path $FilePath -Value "[$Timestamp] $Message" -PassThru
    }
}
New-Module -Name Logging -ScriptBlock $moduleDefinition

$Path = "C:\netinst\"
# Création du dossier si inexistant
If (-Not (Test-Path $Path)) {
    New-Item -Path $Path -ItemType Directory -Force
}
$LogFile = $Path + "lldp.log"

Write-Output "Lancement de networkInfo et du log OK" | Write-Log -FilePath $LogFile -New

#Write-Output "Lancement du job networkInfo" | Write-Log -FilePath $LogFile
# Start-Job -Name "networkInfo" -ScriptBlock {
# $LogFile = $Using:LogFile
# $moduleDefinition = [ScriptBlock]::Create($Using:moduleDefinition)
# New-Module -Name Logging -ScriptBlock $moduleDefinition
# Write-Output "Job démarré" | Write-Log -FilePath $LogFile
# Write-Output "Variable/Module chargés" | Write-Log -FilePath $LogFile
Try {
    # Vérifie et installe le module pour tous les utilisateurs si besoin
    If (-Not (Get-Module -ListAvailable -Name PSDiscoveryProtocol)) {
        If ($PSVersionTable.PSVersion.Major -lt 7) {
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            Write-Output "Powershell classique, NuGet nécessaire..." | Write-Log -FilePath $LogFile
            Install-PackageProvider -Name NuGet -MinimumVersion 2.8.5.201 -Force -Scope AllUsers
        }
        Write-Output "Module PSDiscoveryProtocol absent, installation..." | Write-Log -FilePath $LogFile
        Install-Module -Name PSDiscoveryProtocol -Force -Scope AllUsers
        Write-Output "Installation du module terminée" | Write-Log -FilePath $LogFile
    }

    # Charge le module
    Import-Module PSDiscoveryProtocol
    Write-Output "Module PSDiscoveryProtocol chargé" | Write-Log -FilePath $LogFile

    Write-Output "Lancement Invoke-DiscoveryProtocolCapture" | Write-Log -FilePath $LogFile
    # -Type non précisé pour que les deux types, LLDP et CDP, de paquets soient capturés
    $Packet = Invoke-DiscoveryProtocolCapture -Force

    If ($Packet) {
        Write-Output "Lancement Get-DiscoveryProtocolData sur les paquets récupérés" | Write-Log -FilePath $LogFile
        $Info = Get-DiscoveryProtocolData -Packet $Packet
        Write-Output ("Info :`n" + [String]($Info | Out-String)) | Write-Log -FilePath $LogFile

        $Uri = "http://${env:SE4FS}/logs.php"
        Write-Output "URI : $Uri" | Write-Log -FilePath $LogFile
        $Form = @{
            action       = "lldp"
            os           = "windows"
            computername = $env:ComputerName
            port         = $Info.Port
            switchName   = $Info.Device
            switchIP     = $Info.IPAddress[0]
            vlan         = $Info.VLAN
        }
        Write-Output ("Form :`n" + [String]($Form | Out-String)) | Write-Log -FilePath $LogFile

        Invoke-RestMethod -Uri $Uri -Method Post -Body $Form
        Write-Output "Upload réussi vers $Uri" | Write-Log -FilePath $LogFile
    }
    Else {
        Write-Output "Pas de paquets LLDP ou CDP récupérés..." | Write-Log -FilePath $LogFile
    }
}
Catch {
    Write-Output "ERREUR dans le job networkInfo :`n$($_.Exception.Message)" | Write-Log -FilePath $LogFile
}
Finally {
    Write-Output "Job terminé" | Write-Log -FilePath $LogFile
}
#}
# Débug :
# Receive-Job -Name "networkInfo" | Out-Null
