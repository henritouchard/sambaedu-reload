REM cmd
REM script de configuration des applications windows pour pc-test
SET DOMAINSID=S-1-5-21-2490123726-324139054-1260770439
SET TAG=salle01,0000000x
FOR /f "delims=" %%s IN ('powershell -NoLogo -NoProfile -Command "(Get-CimInstance -ClassName Win32_NetworkAdapter | Where-Object { $_.NetEnabled -eq $true } | Select-Object -ExpandProperty Speed)"') DO (
    SET SPEED=%%s
    GOTO speed
)
:speed
for /f "delims=" %%a in ('powershell -NoLogo -NoProfile -Command "(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID"') do (set "UUID=%%a"
goto uuid)
:uuid
SET id=bb28a6b4b3480d159d803c740968ea0c
IF [%SE4FS%]==[] (
    SET SE4FS=se4fs
    SETX SE4FS se4fs /m
)

REM script [associations]
REM Debut des associations des applications par defaut
IF NOT EXIST "%WINDIR%\Web\SE4\" (MKDIR "%WINDIR%\Web\SE4")
REM ancien mode via gpo
IF EXIST "%WINDIR%\install\packages\associations\associations.xml" (
    COPY /Y "%WINDIR%\install\packages\associations\associations.xml" "%WINDIR%\Web\SE4\associations.xml"
) ELSE (
    DEL /F /S /Q "%WINDIR%\Web\SE4\associations.xml"
)
REM desactivation protection associations

sc config UCPD start=disabled
sc stop UCPD
schtasks /change /Disable /TN "\Microsoft\Windows\AppxDeploymentClient\UCPD velocity"

REM Fin des associations des applications par defaut

REM script [chrome]
REM Chrome Cache en local HKLM
reg.exe add "HKLM\Software\Policies\Google\Chrome" /v "DiskCacheDir" /d "${local_app_data}\GoogleCache" /t REG_EXPAND_SZ /f

REM script [edge]
REM desactivation de l'assistant de demarrage Edge
reg.exe add "HKLM\SOFTWARE\Policies\Microsoft\Edge" /v "HideFirstRunExperience" /d 1 /t REG_DWORD /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\Edge" /v HubsSidebarEnabled /d 0 /t REG_DWORD /f
REG ADD "HKLM\SOFTWARE\Policies\Microsoft\Edge\Recommended" /V "ScarewareBlockerProtectionEnabled" /D 0 /T REG_DWORD /F
REG ADD "HKLM\SOFTWARE\Policies\Microsoft\Edge\Recommended" /V "GenAILocalFoundationalModelSettings" /D 1 /T REG_DWORD /F
REM Edge Cache en local HKLM
reg.exe add "HKLM\Software\Policies\Microsoft\Edge" /v "DiskCacheDir" /d "${local_app_data}\EdgeCache" /t REG_EXPAND_SZ /f

REM script [firefox]
REM Debut de la configuration au demarrage de Firefox
IF EXIST "%PROGRAMFILES%\Mozilla Firefox\" (
    IF NOT EXIST "%PROGRAMFILES%\Mozilla Firefox\distribution\" (MD "%PROGRAMFILES%\Mozilla Firefox\distribution")
    IF EXIST "%PROGRAMFILES%\Mozilla Firefox\distribution\policies.json" (DEL /F /Q "%PROGRAMFILES%\Mozilla Firefox\distribution\policies.json")
    curl -o "%PROGRAMFILES%\Mozilla Firefox\distribution\policies.json" -F "id=%id%" -F "os=windows" "http://se4fs.localdev.fr/gpo/firefox_out.php">NUL
)
IF EXIST "%PROGRAMFILES(x86)%\Mozilla Firefox\" (
    IF NOT EXIST "%PROGRAMFILES(x86)%\Mozilla Firefox\distribution\" (MD "%PROGRAMFILES(x86)%\Mozilla Firefox\distribution")
    IF EXIST "%PROGRAMFILES(x86)%\Mozilla Firefox\distribution\policies.json" (DEL /F /Q "%PROGRAMFILES(x86)%\Mozilla Firefox\distribution\policies.json")
    curl -o "%PROGRAMFILES(x86)%\Mozilla Firefox\distribution\policies.json" -F "id=%id%" -F "os=windows" "http://se4fs.localdev.fr/gpo/firefox_out.php">NUL
)
REM Fin de la configuration au demarrage de Firefox

REM script [folders]
REM effacement si besoin des dossiers cache pour les postes en resau samba
FOR /D %%U IN (C:\Users\*) DO (
    IF /I NOT [%%U]==[C:\Users\Default] (
        IF /I NOT [%%U]==[C:\Users\adminse] (
            IF /I NOT [%%U]==[%USERPROFILE%] (
                IF /I NOT [%%U]==[C:\Users\Public] (
                    RMDIR /S /Q %%U
                )
            )
        )
    )
)

setlocal enabledelayedexpansion
FOR /f "tokens=*" %%A IN ('reg query "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList" ^| findstr /r "!DOMAINSID!-.*"') DO (
    ECHO Deleting %%A
    reg delete "%%A" /f
)
endlocal

REM script [folders]
REM cache les dossiers en doublon dans explorateur
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{0ddd015d-b06c-45d5-8c4c-f59713854639}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{35286a68-3c57-41a1-bbb1-0eae73d76c95}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{7d83ee9b-2244-4e70-b1f5-5393042af1e4}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{a0c69a99-21c8-4671-8703-7934162fcf1d}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{f42ee2d3-909f-4907-8871-4c22fc0bf756}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{B4BFCC3A-DB2C-424C-B029-7FE99A87C641}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Wow6432Node\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{0ddd015d-b06c-45d5-8c4c-f59713854639}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Wow6432Node\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{35286a68-3c57-41a1-bbb1-0eae73d76c95}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Wow6432Node\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{7d83ee9b-2244-4e70-b1f5-5393042af1e4}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Wow6432Node\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{a0c69a99-21c8-4671-8703-7934162fcf1d}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Wow6432Node\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{f42ee2d3-909f-4907-8871-4c22fc0bf756}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Wow6432Node\Microsoft\Windows\CurrentVersion\Explorer\FolderDescriptions\{B4BFCC3A-DB2C-424C-B029-7FE99A87C641}\PropertyBag" /v ThisPCPolicy /d "hide" /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{B4BFCC3A-DB2C-424C-B029-7FE99A87C641}" /v HideIfEnabled /t REG_DWORD /d 0x022ab9b9 /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{d3162b92-9365-467a-956b-92703aca08af}" /v HideIfEnabled /t REG_DWORD /d 0x022ab9b9 /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{088e3905-0323-4b02-9826-5d99428e115f}" /v HideIfEnabled /t REG_DWORD /d 0x022ab9b9 /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{3dfdf296-dbec-4fb4-81d1-6a3438bcf4de}" /v HideIfEnabled /t REG_DWORD /d 0x022ab9b9 /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{24ad3ad4-a569-4530-98e1-ab02f9417aa8}" /v HideIfEnabled /t REG_DWORD /d 0x022ab9b9 /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{f86fa3ab-70d2-4fc7-9c99-fcbf05467f3a}" /v HideIfEnabled /t REG_DWORD /d 0x022ab9b9 /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{B4BFCC3A-DB2C-424C-B029-7FE99A87C641}" /v HiddenByDefault /t REG_DWORD /d 0x00000001 /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{d3162b92-9365-467a-956b-92703aca08af}" /v HiddenByDefault /t REG_DWORD /d 0x00000001 /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{088e3905-0323-4b02-9826-5d99428e115f}" /v HiddenByDefault /t REG_DWORD /d 0x00000001 /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{3dfdf296-dbec-4fb4-81d1-6a3438bcf4de}" /v HiddenByDefault /t REG_DWORD /d 0x00000001 /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{24ad3ad4-a569-4530-98e1-ab02f9417aa8}" /v HiddenByDefault /t REG_DWORD /d 0x00000001 /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer\MyComputer\NameSpace\{f86fa3ab-70d2-4fc7-9c99-fcbf05467f3a}" /v HiddenByDefault /t REG_DWORD /d 0x00000001 /f

REM reg add "HKLM\SYSTEM\CurrentControlSet\Control\Lsa" /v "Security Packages" /d "mdnsNSP.dll" /t REG_MULTI_SZ /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\CloudContent" /v DisableSoftLanding /d 1 /t REG_DWORD /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\Windows Search" /v AllowCloudSearch /d 0 /t REG_DWORD /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\WindowsCopilot" /v TurnOffWindowsCopilot /d 1 /t REG_DWORD /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\office\16.0\common\privacy" /v usercontentdisabled /d 2 /t REG_DWORD /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\WindowsAI" /v DisableAIDataAnalysis /d 1 /t REG_DWORD /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\WindowsAI" /v AllowRecallEnablement /d 0 /t REG_DWORD /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\Explorer" /v DisableSearchBoxSuggestions /d 1 /t REG_DWORD /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows Defender Security Center\Notifications" /v DisableNotifications /d 1 /t REG_DWORD /f

REM suppr widgets barre des taches
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\Dsh" /v AllowNewsAndInterests /d 0 /t REG_DWORD /f
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\Explorer\Advanced"  /v TaskbarDa /d 0 /t REG_DWORD /f

reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\Appx" /v AllowDeploymentInSpecialProfiles /d 1 /t REG_DWORD /f

REM script [glpi]
reg.exe add "HKLM\SOFTWARE\GLPI-Agent" /v "tag" /D %TAG% /F

REM script [printers]
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows NT\Printers\RPC" /v RpcUseNamedPipeProtocol /t REG_DWORD /d 1 /f

REM script [rdp]
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%PROGRAMFILES%\SambaEdu\rdpvideo.ps1" -Action "enable"

REM script [shortcuts]
rem Debut de la configuration raccourcis startup
curl -o "%TEMP%\shortcuts.cmd" -F "os=windows" -F "id=%id%" -F "action=startup" "http://se4fs/gpo/shortcuts_out.php">NUL
if exist "%TEMP%\shortcuts.cmd" (
	call "%TEMP%\shortcuts.cmd"
	del /f /q "%TEMP%\shortcuts.cmd"
)
rem Fin de la configuration des raccourcis startup

REM script [thunderbird]
REM Debut de la configuration au demarrage de Thunderbird
IF EXIST "%PROGRAMFILES%\Mozilla Thunderbird\" (
    IF NOT EXIST "%PROGRAMFILES%\Mozilla Thunderbird\distribution\" (MD "%PROGRAMFILES%\Mozilla Thunderbird\distribution")
    IF EXIST "%PROGRAMFILES%\Mozilla Thunderbird\distribution\policies.json" (DEL /F /Q "%PROGRAMFILES%\Mozilla Thunderbird\distribution\policies.json")
    curl -o "%PROGRAMFILES%\Mozilla Thunderbird\distribution\policies.json" -F "id=%id%" "http://se4fs/gpo/thunderbird_out.php">NUL
)
IF EXIST "%PROGRAMFILES(x86)%\Mozilla Thunderbird\" (
    IF NOT EXIST "%PROGRAMFILES(x86)%\Mozilla Thunderbird\distribution\" (MD "%PROGRAMFILES(x86)%\Mozilla Thunderbird\distribution")
    IF EXIST "%PROGRAMFILES(x86)%\Mozilla Thunderbird\distribution\policies.json" (DEL /F /Q "%PROGRAMFILES(x86)%\Mozilla Thunderbird\distribution\policies.json")
    curl -o "%PROGRAMFILES(x86)%\Mozilla Thunderbird\distribution\policies.json" -F "id=%id%" "http://se4fs/gpo/thunderbird_out.php">NUL
)
REM Fin de la configuration au demarrage de Thunderbird

REM script [veyon]
REM Debut de la configuration au demarrage de Veyon
IF EXIST "%PROGRAMFILES%\Veyon\veyon-cli.exe" (
    "%PROGRAMFILES%\Veyon\veyon-cli.exe" config clear
    curl -o "%TEMP%\global.json" -F "id=%id%" "http://se4fs/gpo/veyon_out.php">NUL
    IF EXIST "%TEMP%\global.json" "%PROGRAMFILES%\Veyon\veyon-cli.exe" config import "%TEMP%\global.json"
    REM Gestion d"une eventuelle license de plugin
    curl -o "%TEMP%\licence.vlf" -F "licence=1" "http://se4fs/gpo/veyon_out.php">NUL
    IF EXIST "%TEMP%\licence.vlf" (for /f %%i in ("%TEMP%\licence.vlf") do if %%~zi gtr 0 "%PROGRAMFILES%\Veyon\veyon-cli.exe" licensing add "%TEMP%\licence.vlf")
    REM Redemarrage du service
    "%PROGRAMFILES%\Veyon\veyon-cli.exe" service restart
)
REM ajout des remoteapps pour rebond
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList" /v "CentralLicensing" /t REG_DWORD /d 0 /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList" /v "CustomRDPSettings" /d "authentication level:i:2" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList" /v "fDisabledAllowList" /t REG_DWORD /d 0 /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList" /v "fHasCertificate" /t REG_DWORD /d 0 /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList" /v "LicensingType" /t REG_DWORD /d 5 /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList" /v "LicenseServers" /t REG_BINARY /d 00 /f

reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Master" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Master" /v "Name" /d "Veyon Master" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Master" /v "Path" /d "Powershell.exe" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Master" /v "VPath" /d "Powershell.exe" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Master" /v "RequiredCommandLine" /d "-ExecutionPolicy bypass -File \"C:\Program Files\Sambaedu\veyon-master.ps1\"" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Master" /v "CommandLineSetting" /d 2 /t REG_DWORD /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Master" /v "IconPath" /d "C:\Program Files\Veyon\veyon-master.exe" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Master" /v "IconIndex" /d 0 /t REG_DWORD /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Master" /v "ShowInTSWA" /d 0 /t REG_DWORD /f

reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Poste" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Poste" /v "Name" /d "Veyon Poste" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Poste" /v "Path" /d "C:\Program Files\Veyon\veyon-cli.exe" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Poste" /v "VPath" /d "C:\Program Files\Veyon\veyon-cli.exe" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Poste" /v "RequiredCommandLine" /d "" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Poste" /v "CommandLineSetting" /d 1 /t REG_DWORD /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Poste" /v "IconPath" /d "C:\Program Files\Veyon\veyon-cli.exe" /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Poste" /v "IconIndex" /d 0 /t REG_DWORD /f
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications\Veyon Poste" /v "ShowInTSWA" /d 0 /t REG_DWORD /f

REM Fin de la configuration au demarrage de Veyon

REM script [wakeonlan]
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%PROGRAMFILES%\SambaEdu\wakeonlan.ps1"

REM script [wallpaper]
REM Debut de la configuration des fonds d'ecrans startup
REM LockScreen
IF NOT EXIST "%WINDIR%\Web\SE4\" (MD "%WINDIR%\Web\SE4")
IF NOT EXIST "%WINDIR%\System32\oobe\info\backgrounds\" (MD "%WINDIR%\System32\oobe\info\backgrounds")
IF NOT EXIST "%WINDIR%\Web\SE4\SetWallpaper.ps1" (IF EXIST "%WINDIR%\install\os\SambaEdu\SetWallpaper.ps1" (COPY /Y "%WINDIR%\install\os\SambaEdu\SetWallpaper.ps1" "%WINDIR%\Web\SE4\SetWallpaper.ps1"))
IF EXIST "%WINDIR%\install\os\SambaEdu\SetWallpaper.ps1" (COPY /Y "%WINDIR%\install\os\SambaEdu\SetWallpaper.ps1" "%PROGRAMFILES%\Sambaedu\SetWallpaper.ps1")
curl.exe -o "%WINDIR%\Web\SE4\lockscreen.jpg" -F "action=lockscreen" -F "id=%id%" "http://se4fs/gpo/wallpaper_out.php">NUL
COPY /Y "%WINDIR%\Web\SE4\lockscreen.jpg" "%WINDIR%\System32\oobe\info\backgrounds\backgroundDefault.jpg"
REM fond d'ecran sans nom d'utilisateur pour l'ouverture initiale de session
DEL /S /F /Q "%WINDIR%\Web\SE4\wallpaper.txt"
curl.exe -o "%WINDIR%\Web\SE4\wallpaper.jpg" -F "action=wallpaper-wait" -F "id=%id%" "http://se4fs/gpo/wallpaper_out.php">NUL
COPY /Y "%WINDIR%\Web\SE4\wallpaper.jpg" "%WINDIR%\Web\SE4\veyon.jpg"
icacls "%WINDIR%\Web\SE4\wallpaper.jpg" /grant *S-1-5-32-545:F
icacls "%WINDIR%\Web\SE4\veyon.jpg" /grant *S-1-5-32-545:F
REM Fin de la configuration des fonds d'ecrans startup

REM script [winget]
REM pour les applications portables installees avec winget, il faut reset les acls sur le dossier d'install
IF EXIST "%ProgramFiles%\WinGet\Packages" (icacls "%ProgramFiles%\WinGet\Packages\*" /reset /T /C /Q)

REM script [wpkg]
REM scripts divers utiles pour sambaedu
IF NOT EXIST "%ProgramFiles%\SambaEdu" (MD "%ProgramFiles%\SambaEdu")
ROBOCOPY "%WinDir%\install\os\SambaEdu" "%ProgramFiles%\SambaEdu"
REM DL et exec powershell
IF EXIST "%TEMP%\applications-startup-system.ps1" (
    DEL /F /Q "%TEMP%\applications-startup-system.ps1"
)
curl -o "%TEMP%\applications-startup-system.ps1" -F "interpreter=powershell" -F "id=bb28a6b4b3480d159d803c740968ea0c"  "http://se4fs/gpo/applications.php">NUL
IF EXIST "%TEMP%\applications-startup-system.ps1" (
    IF EXIST "%ProgramFiles%\Powershell\7\pwsh.exe" (
        pwsh.exe -NoProfile -ExecutionPolicy Bypass -File "%TEMP%\applications-startup-system.ps1"
    ) ELSE (
        powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%TEMP%\applications-startup-system.ps1"
    )
)

curl -F "os=windows" -F "uuid=%UUID%" -F "speed=%SPEED%" -F "id=bb28a6b4b3480d159d803c740968ea0c"  -F "ret=0"  "http://se4fs/gpo/applications.php">NUL
