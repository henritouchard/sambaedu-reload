{{-- Story 3.8 - D6 / AC4.6 - Port iso legacy/modules/ipxe/Win10/action.php cmd_wpkg (LOC 268-311). --}}
{{-- Securite critique : ce .cmd s'execute en SYSTEM cote Windows post-reboot. --}}
REM cmd
REM  script de demarrage genere automatiquement
REM  pour {{ $name }}, {{ $id }}, {{ $uuid }}, {{ $type }}, {{ $role }}, {{ $etape }}, {{ $script }}, {{ $ret }}
REM
REM
for /f "delims=" %%a in ('powershell -NoLogo -NoProfile -Command "(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID"') do (set "UUID=%%a"
goto uuid)
:uuid
if [%username%]==[{{ $se4installName }}] (goto autologon) else (goto gpo)
:gpo
if exist %windir%\gpo.txt(goto fin)
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultUserName" /d "{{ $se4installName }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultDomainName" /d "{{ $domain }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultPassword" /d "{{ $se4installPasswd }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoAdminLogon" /d 1 /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoLogonCount" /d 1 /F >NUL
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run" /v "action" /d  "powershell -Command start cmd -v runAs -f %windir%\autorun.cmd" /F >NUL
echo 1> c:\Netinst\nowpkg.txt
echo OK> %windir%\gpo.txt
curl  -F "etape=wpkg" -F "ret=0" -F "uuid=%UUID%"  -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
if exist %windir%\action.cmd (copy /Y %windir%\action.cmd %windir%\autorun.cmd)
%SystemRoot%\system32\shutdown.exe -r -f -c "Le poste va redemarrer en {{ $se4installName }} pour finir sa configuration"
rem if exist %windir%\action.cmd (start del /F /Q %windir%\action.cmd)
goto fin

:autologon
if exist %windir%\gpo.txt(goto fin)
%SystemRoot%\system32\shutdown.exe /a
reg.exe delete "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run" /v "action" /F >NUL
reg.exe delete "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultPassword" /F>NUL
reg.exe delete "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoAdminLogon" /F >NUL

if  exist %windir%\gpo.txt (del /F /S /Q %windir%\gpo.txt)
if  exist c:\Netinst\nowpkg.txt (del /f /q c:\Netinst\nowpkg.txt)
if exist  %windir%\wpkg-gpo.txt Del /F /Q %WinDir%\wpkg-client.vbs
if exist  %windir%\wpkg-gpo.txt Del /F /Q %WinDir%\wpkg-client.txt
if exist  %windir%\wpkg-gpo.txt Del /F /Q %WinDir%\wpkg-client.log
if not exist  %Windir%\install mklink /D %Windir%\install \\{{ $se4fsName }}\install
if not exist  %Windir%\rapports mklink /D %Windir%\rapports \\{{ $se4fsName }}\rapports
if exist z:\wpkg\wpkg-client.vbs net use z: /delete /y
if not exist %WinDir%\wpkg-client.vbs copy /Y /V /B %windir%\install\wpkg\wpkg-client.vbs %WinDir%\wpkg-client.vbs
if exist %WinDir%\wpkg-client.vbs echo forcage wpkg en admin le %date%>> %windir%\wpkg-gpo.txt
powershell -noprofile -noninteractive -executionpolicy bypass -file "%PROGRAMFILES%\SambaEdu\driversAuto.ps1"
powershell -noprofile -noninteractive -executionpolicy bypass -file "%PROGRAMFILES%\SambaEdu\winget-install.ps1"
start /wait "%Programfiles%\Sambaedu\Nettoyage WPKG.cmd"
curl -F "etape=wpkg" -F "ret=1" -F "uuid=%UUID%" -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
%SystemRoot%\system32\shutdown.exe /l
:fin
