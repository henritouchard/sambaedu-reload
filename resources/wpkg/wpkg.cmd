REM variables d'env
IF [%SE4FS%]==[] (SET SE4FS=se4fs
SETX SE4FS se4fs /m)

REM copies/liens WPKG
IF EXIST %windir%\wpkg-gpo.txt DEL /F /Q %WinDir%\wpkg-client.vbs
IF EXIST %windir%\wpkg-gpo.txt DEL /F /Q %WinDir%\wpkg-client.txt
IF EXIST %windir%\wpkg-gpo.txt DEL /F /Q %WinDir%\wpkg-client.log
IF NOT EXIST %Windir%\install MKLINK /D %Windir%\install \\%SE4FS%\install
REM amorçage helpers SambaEdu (bootstrap %PROGRAMFILES%, indép. chaine WPKG)
IF NOT EXIST "%ProgramFiles%\SambaEdu" MD "%ProgramFiles%\SambaEdu"
ROBOCOPY "%WinDir%\install\os\SambaEdu" "%ProgramFiles%\SambaEdu" /E
IF NOT EXIST %Windir%\rapports MKLINK /D %Windir%\rapports \\%SE4FS%\rapports
IF NOT EXIST %systemdrive%\Netinst MD %systemdrive%\Netinst
IF NOT EXIST %systemdrive%\Netinst\Logs MD %systemdrive%\Netinst\Logs
IF EXIST %systemdrive%\install RD %systemdrive%\install
IF EXIST %systemdrive%\rapports RD %systemdrive%\rapports
IF EXIST Z:\wpkg\wpkg-client.vbs NET USE Z: /DELETE

IF EXIST "C:\Program Files\Powershell\7\pwsh.exe" ("C:\Program Files\Powershell\7\pwsh.exe" -NoProfile -Mta -NonInteractive -ExecutionPolicy Bypass -File "%ProgramFiles%\Sambaedu\install.ps1")

REM WPKG (SambaEdu SE5 — story 27.5, D6/D8) : livraison NATIVE.
REM Le bundle WPKG (scripts + catalogue pre-substitue) est servi STATIQUEMENT par
REM Apache. On TELECHARGE en HTTP (au lieu du XCOPY SMB legacy) depuis l'URL du
REM bundle donnee par l'agent dans %SE4_WPKG_BUNDLE_URL% (defaut
REM http://%SE4FS%/wpkg/bundle). C'est le CLIENT qui telecharge (l'agent non).
IF [%SE4_WPKG_BUNDLE_URL%]==[] (SET SE4_WPKG_BUNDLE_URL=http://%SE4FS%/wpkg/bundle)
bitsadmin /transfer se4wpkg /download /priority normal "%SE4_WPKG_BUNDLE_URL%/wpkg-client.vbs" "%WinDir%\wpkg-client.vbs" >NUL 2>&1
IF NOT EXIST %WinDir%\wpkg-client.vbs (powershell -NoProfile -ExecutionPolicy Bypass -Command "try { Invoke-WebRequest -UseBasicParsing -Uri '%SE4_WPKG_BUNDLE_URL%/wpkg-client.vbs' -OutFile '%WinDir%\wpkg-client.vbs' } catch { exit 1 }" >NUL 2>&1)
IF EXIST %WinDir%\wpkg-client.vbs (ECHO gpo %date%>>%WinDir%\wpkg-gpo.txt)
IF EXIST %WinDir%\wpkg-client.vbs (%WinDir%\system32\cscript.exe //B //NoLogo %WinDir%\wpkg-client.vbs /NOTempo >NUL 2>&1)
