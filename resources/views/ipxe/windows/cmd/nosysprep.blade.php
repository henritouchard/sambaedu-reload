{{-- Story 3.8 - D6 / AC4.2 - Port iso legacy/modules/ipxe/Win10/action.php cmd_nosysprep (LOC 151-192). --}}
{{-- Securite critique : ce .cmd s'execute en SYSTEM cote Windows post-reboot. --}}
{{-- Q-2 REFACTO CLARTE (decision Henri 2026-05-25) : le SE5 emet `etape=nosysprep` distinct --}}
{{-- (PAS `etape=sysprep&ret=2` comme legacy lignes 169, 187 - ambiguite legacy levee pour --}}
{{-- la clarte de la state machine SE5). --}}
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
schtasks /end /tn wpkg4
schtasks /delete  /F /tn wpkg4
net accounts /maxpwage:unlimited
curl -F "ret=0" -F "etape=nosysprep" -F "uuid=%UUID%"  -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
copy /Y %windir%\action.cmd %windir%\autorun.cmd
%SystemRoot%\system32\shutdown.exe -r -t 5
goto fin



:autologon
%SystemRoot%\system32\shutdown.exe /a
powercfg.exe /hibernate off
echo 1> c:\Netinst\nowpkg.txt
rem powershell -command "Rename-Computer -NewName {{ $cloneName }}"
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultUserName" /d ".\{{ $adminseName }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultPassword" /d "{{ $adminsePasswd }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoAdminLogon" /d 1 /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoLogonCount" /d 2 /F >NUL
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run" /v "action" /d  "powershell -Command start cmd -v runAs -f %windir%\autorun.cmd" /F >NUL
powershell -command "$User = '{{ $domain }}\{{ $se4installName }}';$PWord = ConvertTo-SecureString -String '{{ $se4installPasswd }}' -AsPlainText -Force;$Credential = New-Object -TypeName System.Management.Automation.PSCredential -ArgumentList $User, $PWord;Remove-Computer -UnjoinDomaincredential $Credential -WorkGroupName clone -Force"
curl -F "etape=nosysprep" -F "ret=0" -F "uuid=%UUID%" -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
%SystemRoot%\system32\shutdown.exe -r -t 5  -c "Le poste est pret pour le clonage . Si vous voulez cloner avec un outil externe, ou capturer une image, surtout ne redemarrez pas Windows avant le clonage!"
goto fin
:fin
if  exist %windir%\gpo.txt (del /F /S /Q %windir%\gpo.txt)
if  exist c:\Netinst\nowpkg.txt (del /f /q c:\Netinst\nowpkg.txt)
