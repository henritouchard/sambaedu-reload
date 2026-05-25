{{-- Story 3.8 - D6 / AC4.4 - Port iso legacy/modules/ipxe/Win10/action.php cmd_renomme (LOC 317-351). --}}
{{-- Securite critique : ce .cmd s'execute en SYSTEM cote Windows post-reboot. --}}
{{-- Sanitization : tous les placeholders passent par sanitizeBatPlaceholder() dans le builder. --}}
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
rem if exist %windir%\gpo.txt(goto fin)
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultUserName" /d "{{ $se4installName }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultDomainName" /d "{{ $domain }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultPassword" /d "{{ $se4installPasswd }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoAdminLogon" /d 1 /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoLogonCount" /d 1 /F >NUL
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run" /v "action" /d  "powershell -Command start cmd -v runAs -f %windir%\autorun.cmd" /F >NUL
echo 1> c:\Netinst\nowpkg.txt
echo OK> %windir%\gpo.txt
schtasks /end /tn wpkg4
schtasks /delete  /F /tn wpkg4
curl -F "etape=renomme" -F "ret=0" -F "uuid=%UUID%" -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
copy /Y %windir%\action.cmd %windir%\autorun.cmd
%SystemRoot%\system32\shutdown.exe  -t 5 -r  -c "Le poste va redemarrer en {{ $se4installName }} pour etre renomme {{ $role }}"
goto fin

:autologon
%SystemRoot%\system32\shutdown.exe /a
reg.exe delete "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run" /v "action" /F >NUL
reg.exe delete "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultPassword" /F>NUL
reg.exe delete "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoAdminLogon" /F >NUL
echo 1> c:\Netinst\nowpkg.txt
powershell -command "Rename-Computer -NewName {{ $role }}"
curl -F "etape=renomme" -F "ret=1" -F "uuid=%UUID%" -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
if  exist %windir%\gpo.txt (del /F /S /Q %windir%\gpo.txt)
%SystemRoot%\system32\shutdown.exe -r -t 5  -c "Le poste est renomme {{ $role }}"
:fin
if  exist %windir%\gpo.txt (del /F /S /Q %windir%\gpo.txt)
if  exist c:\Netinst\nowpkg.txt (del /f /q c:\Netinst\nowpkg.txt)
