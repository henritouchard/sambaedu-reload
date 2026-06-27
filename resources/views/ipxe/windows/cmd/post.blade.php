{{-- Story 3.8 - D6 / AC4.5 - Port iso legacy/modules/ipxe/Win10/action.php cmd_post (LOC 198-231). --}}
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
REM  les fichiers n'ont  pas ete copies lors de l'installation
if not exist %WINDIR%\Web\SE4\ (md %WINDIR%\Web\SE4)
if not exist  "%PROGRAMFILES%\SambaEdu" (md "%PROGRAMFILES%\SambaEdu")
REM [LEGACY OFF 2026-06-12] helpers legacy non stages pour test agent Go (l'agent vit dans C:\ProgramData\SambaEdu\Agent, non impacte) - restaurer pour reactiver
REM [LEGACY OFF 2026-06-12] robocopy c:\Netinst "%PROGRAMFILES%\SambaEdu" /MOVE
echo 1> c:\Netinst\nowpkg.txt
echo OK> %windir%\gpo.txt
curl  -F "etape=post" -F "ret=0" -F "uuid=%UUID%"  -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
if exist %windir%\action.cmd (copy /Y %windir%\action.cmd %windir%\autorun.cmd)
%SystemRoot%\system32\shutdown.exe -r  -c "Le poste va redemarrer en {{ $se4installName }} pour finir sa configuration"
goto fin
:autologon
reg.exe delete "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run" /v "action" /F >NUL
reg.exe delete "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultPassword" /F>NUL
reg.exe delete "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoAdminLogon" /F >NUL
if not exist %windir%\action.cmd (curl --fail -F "etape=post" -F "name=%computername%" -o "%windir%\action.cmd" "http://{{ $se4fsName }}/ipxe/windows/action")
reg.exe add HKCU\Console /v QuickEdit /t REG_DWORD /d 0 /f
if exist %windir%\action.cmd (start %windir%\action.cmd)
reg.exe delete HKCU\Console /v QuickEdit /t REG_DWORD /d 0 /f
:fin
if  exist %windir%\gpo.txt (del /F /S /Q %windir%\gpo.txt)
if  exist c:\Netinst\nowpkg.txt (del /f /q c:\Netinst\nowpkg.txt)
