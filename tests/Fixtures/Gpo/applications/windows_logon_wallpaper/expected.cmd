REM cmd
REM logon pour pc-test et testuser 
REM script de configuration des applications windows profile=C:\Users\testuser
SET id=1b43438debac13c8109c74a1de43a1f5
IF [%SE4FS%]==[] (
    SET SE4FS=se4fs
)

REM script [redirect-thunderbird]

REM script [redirect-Firefox]

REM script [redirect-Edge]

REM script [redirect-GoogleChrome]

REM script [redirect-OpenBoard]

REM script [redirect-OnlyOffice]

REM script [redirect-Filius]

REM script [associations]
powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%programfiles%\SambaEdu\associations.ps1"

REM script [chrome]
REM Chrome Cache en local HKCU
reg.exe add "HKCU\Software\Google\Chrome" /v "DiskCacheDir" /d "${local_app_data}\GoogleCache" /t REG_EXPAND_SZ /f

REM script [edge]
REM Edge Cache en local HKCU
reg.exe add "HKCU\Software\Microsoft\Edge" /v "DiskCacheDir" /d "${local_app_data}\EdgeCache" /t REG_SZ /f
reg.exe add "HKEY_CURRENT_USER\SOFTWARE\Microsoft\Edge" /v HubsSidebarEnabled /d 0 /t REG_DWORD /f
reg.exe add "HKEY_CURRENT_USER\SOFTWARE\Microsoft\Edge\Recommended" /V "ScarewareBlockerProtectionEnabled" /D 0 /T REG_DWORD /F
reg.exe add "HKEY_CURRENT_USER\SOFTWARE\Microsoft\Edge" /V "GenAILocalFoundationalModelSettings" /D 1 /T REG_DWORD /F
REM script [firefox]
REM Mise en place du profil utilisateur de Firefox pour Windows
IF NOT EXIST "%userprofile%\AppData\Local\cacheFirefox\" (MD "%userprofile%\AppData\Local\cacheFirefox\")
IF NOT EXIST "%userprofile%\AppData\Roaming\Mozilla\Firefox" (MD "%userprofile%\AppData\Roaming\Mozilla\Firefox")
rem IF NOT EXIST "%userprofile%\AppData\Roaming\Mozilla\Firefox\sambaedu.default" (MD "%userprofile%\AppData\Roaming\Mozilla\Firefox\sambaedu.default")
rem IF NOT EXIST "\\%SE4FS%\users\%username%\.mozilla\firefox\sambaedu.default" (MD "\\%SE4FS%\users\%username%\.mozilla\firefox\sambaedu.default")
(
ECHO [Install308046B0AF4A39CB]
ECHO Default=sambaedu.default
ECHO Locked=1
ECHO:
ECHO [Profile0]
ECHO Name=sambaedu
ECHO IsRelative=1
ECHO Path=sambaedu.default
ECHO Default=1
ECHO:
ECHO [General]
ECHO StartWithLastProfile=1
ECHO Version=2
)>%userprofile%\AppData\Roaming\Mozilla\Firefox\profiles.ini
(
ECHO [308046B0AF4A39CB]
ECHO Default=sambaedu.default
ECHO Locked=1
)>%userprofile%\AppData\Roaming\Mozilla\Firefox\installs.ini
REM Fin de la mise en place du profil utilisateur de Firefox pour Windows

REM script [folders]
reg add "HKCU\SOFTWARE\Policies\Windows\WindowsCopilot" /v TurnOffWindowsCopilot /d 1 /t REG_DWORD /f
reg add "HKCU\SOFTWARE\Microsoft\Windows\CurrentVersion\SearchSettings" /v IsDynamicSearchBoxEnabled /d 0 /t REG_DWORD /f
reg add "HKCU\SOFTWARE\Microsoft\Windows\CurrentVersion\Search" /v BingSearchEnabled /d 0 /t REG_DWORD /f
reg add "HKCU\SOFTWARE\Microsoft\Windows\CurrentVersion\Explorer" /v DisableSearchBoxSuggestions /d 1 /t REG_DWORD /f
reg add "HKCU\SOFTWARE\Microsoft\office\16.0\common\privacy" /v usercontentdisabled /d 2 /t REG_DWORD /f
reg add "HKCU\SOFTWARE\Microsoft\Windows\WindowsAI" /v DisableAIDataAnalysis /d 1 /t REG_DWORD /f
reg add "HKCU\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsAI" /v AllowRecallEnablement /d 0 /t REG_DWORD /f

REM suppr widgets taskbar
reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\Advanced" /v TaskbarDa /t REG_DWORD /d 0 /f
reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\PushNotifications" /v ToastEnabled /t REG_DWORD /d 0 /f

REM cache lien onedrive
reg add "HKCR\CLSID\{018D5C66-4533-4307-9B53-224DE2ED1FE6}" /v System.IsPinnedToNameSpaceTree /t REG_DWORD /d 0 /f

REM script [folders]
REM bureau et DL sur le serveur se4fs
REM creation des dossiers
IF NOT EXIST "\\%se4fs%\users\%USERNAME%\Telechargements" (MD "\\%se4fs%\users\%USERNAME%\Telechargements")
IF NOT EXIST "\\%se4fs%\users\%USERNAME%\Bureau" (MD "\\%se4fs%\users\%USERNAME%\Bureau")

REM conf des dossiers
reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\User Shell Folders" /v Downloads /d "\\%se4fs%\users\%USERNAME%\Telechargements" /t REG_EXPAND_SZ /f
reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\User Shell Folders" /v Desktop /d "\\%se4fs%\users\%USERNAME%\Bureau" /t REG_EXPAND_SZ /f

REM cache les .desktop
attrib.exe +H "\\%se4fs%\users\%USERNAME%\Bureau\*.desktop"

REM script [folders]
REM creation des dossiers sur le serveur se4 (sans cloud actif pour l'utilisateur)
IF NOT EXIST "\\%se4fs%\users\%USERNAME%\Docs" (MD "\\%se4fs%\users\%USERNAME%\Docs")
IF NOT EXIST "\\%se4fs%\users\%USERNAME%\Images" (MD "\\%se4fs%\users\%USERNAME%\Images")
IF NOT EXIST "\\%se4fs%\users\%USERNAME%\Videos" (MD "\\%se4fs%\users\%USERNAME%\Videos")

REM conf des dossiers
reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\User Shell Folders" /v Personal /d "\\%se4fs%\users\%USERNAME%\Docs" /t REG_EXPAND_SZ /f
reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\User Shell Folders" /v "My Pictures" /d "\\%se4fs%\users\%USERNAME%\Images" /t REG_EXPAND_SZ /f
reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\User Shell Folders" /v "My Video" /d "\\%se4fs%\users\%USERNAME%\Videos" /t REG_EXPAND_SZ /f

REM pin bureau et telechargements
pwsh -noprofile -executionpolicy bypass -command "$Q = New-Object -ComObject shell.application;$Path = $Q.Namespace('shell:Personal');$Path.Self.Path;if(-not ($Q.Namespace('shell:::{679f85cb-0220-4080-b29b-5540cc05aab6}').Items() | ? {$_.Path -eq $Path.Self.Path})){$Path.Self.InvokeVerb('pintohome')}"

REM script [logs]
START /B powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%PROGRAMFILES%\SambaEdu\PingSE4.ps1"

REM script [rclone]
REM montage auto des partages de l'utilisateur dans un répertoire du profil local
SET rc_config=\\%SE4FS%\users\%username%\.config\rclone\rclone.conf
IF EXIST %rc_config% (
    curl -o %temp%\rclone.cmd -F "os=windows" -F "id=%id%" "http://se4fs/partages/cloud_out.php">NUL
    IF EXIST %temp%\rclone.cmd (
        START /B %temp%\rclone.cmd
    )
)

REM script [shortcuts]
REM Debut de la configuration des raccourcis logon
curl -o "%TEMP%\shortcuts.cmd" -F "os=windows" -F "id=%id%" -F "action=logon"  "http://se4fs/gpo/shortcuts_out.php">NUL
IF EXIST "%TEMP%\shortcuts.cmd" (
    CALL "%TEMP%\shortcuts.cmd"
    DEL /F /Q "%TEMP%\shortcuts.cmd"
)
REM Fin de la configuration des raccourcis logon

REM script [thunderbird]
REM Mise en place du profil utilisateur de Thunderbird pour Windows
IF NOT EXIST "%userprofile%\AppData\Roaming\Thunderbird" (MD "%userprofile%\AppData\Roaming\Thunderbird")
rem IF NOT EXIST "\\%SE4FS%\users\%username%\.thunderbird\Profiles\sambaedu.default" (MD "\\%SE4FS%\users\%username%\.thunderbird\Profiles\sambaedu.default")
(
ECHO [InstallD78BF5DD33499EC2]
ECHO Default=sambaedu.default
ECHO Locked=1
ECHO:
ECHO [Profile0]
ECHO Name=sambaedu
ECHO IsRelative=1
ECHO Path=sambaedu.default
ECHO:
ECHO [General]
ECHO StartWithLastProfile=1
ECHO Version=2
ECHO:
)>%userprofile%\AppData\Roaming\Thunderbird\profiles.ini
(
ECHO [D78BF5DD33499EC2]
ECHO Default=sambaedu.default
ECHO Locked=1
)>%userprofile%\AppData\Roaming\Thunderbird\installs.ini
REM Fin de la mise en place du profil utilisateur de Thunderbird pour Windows

REM script [wallpaper]
REM Debut de la configuration des fonds d'ecrans logon
curl.exe -o "%WINDIR%\Web\SE4\wallpaper.jpg" -F "action=wallpaper" -F "id=%id%" "http://se4fs/gpo/wallpaper_out.php">NUL
curl.exe -o "%WINDIR%\Web\SE4\veyon.jpg" -F "action=veyon" -F "id=%id%" "http://se4fs/gpo/wallpaper_out.php">NUL
REM Pas de taskkill pour SE4INSTALL
IF EXIST "%PROGRAMFILES%\SambaEdu\SetWallpaper.ps1" (
    START /B powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%PROGRAMFILES%\SambaEdu\SetWallpaper.ps1"
) ELSE (
    (taskkill /F /IM explorer.exe /FI "USERNAME ne se4install") & (explorer.exe)
)
REM Fin de la configuration des fonds d'ecrans logon
REM DL et exec powershell
IF EXIST "%TEMP%\applications-logon.ps1" (
    DEL /F /Q "%TEMP%\applications-logon.ps1"
)
curl -o "%TEMP%\applications-logon.ps1" -F "interpreter=powershell" -F "id=1b43438debac13c8109c74a1de43a1f5"  "http://se4fs/gpo/applications.php">NUL
IF EXIST "%TEMP%\applications-logon.ps1" (
    IF EXIST "%ProgramFiles%\Powershell\7\pwsh.exe" (
        pwsh.exe -NoProfile -ExecutionPolicy Bypass -File "%TEMP%\applications-logon.ps1"
    ) ELSE (
        powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%TEMP%\applications-logon.ps1"
    )
)

curl -F "os=windows" -F "uuid=%UUID%" -F "speed=%SPEED%" -F "id=1b43438debac13c8109c74a1de43a1f5"  -F "ret=0"  "http://se4fs/gpo/applications.php">NUL
