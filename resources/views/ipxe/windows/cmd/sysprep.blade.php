{{-- Story 3.8 - D6 / AC4.1 - Port iso legacy/modules/ipxe/Win10/action.php cmd_sysprep (LOC 73-144). --}}
{{-- Securite critique : ce .cmd s'execute en SYSTEM cote Windows post-reboot. --}}
{{-- Note _README.md fixtures : le legacy ne sert jamais cmd_sysprep tel quel comme body de --}}
{{-- reponse (dispatcher legacy renvoie cmd_nosysprep pour etape=sysprep&type=clonage). --}}
{{-- Neanmoins ce bloc est porte pour servir la logique sysprep+generalize complete utilisee --}}
{{-- au 2e boot via :autologon (registry autoLogon se4install + sysprep.exe /generalize /oobe --}}
{{-- /unattend:sysprep.xml + curl ret=1). --}}
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
curl -F "ret=0" -F "etape=sysprep" -F "uuid=%UUID%"  -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
copy /Y %windir%\action.cmd %windir%\autorun.cmd
%SystemRoot%\system32\shutdown.exe -r
%SystemRoot%\system32\shutdown.exe -r  -c "Le poste va redemarrer en admin sous le nom {{ $cloneName }}"
goto fin



:autologon
%SystemRoot%\system32\shutdown.exe /a
reg.exe delete "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run" /v "action" /F >NUL
reg.exe delete "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultPassword" /F>NUL
reg.exe delete "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoAdminLogon" /F >NUL
powercfg.exe /hibernate off
echo 1> c:\Netinst\nowpkg.txt
curl -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/sysprep.xml>%windir%\sysprep.xml
if exist %windir%\sysprep.xml (goto sysprep) else (goto erreur)
:sysprep
powershell -command "Rename-Computer -NewName {{ $cloneName }}"
REM pause
REM  on est encore au domaine donc on peut se connecter sur install
if exist %windir%\install\os\netinst\sysprep.ps1 (powershell -noprofile -executionpolicy bypass -file %windir%\install\os\netinst\sysprep.ps1) else (goto erreur)
%windir%\system32\sysprep\sysprep.exe /generalize /oobe /quiet /quit /unattend:%windir%\sysprep.xml>%windir%\sysprep.log
if exist %windir%\system32\sysprep\sysprep_succeeded.tag (curl -F "etape=sysprep" -F "ret=1" -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
REM pwrconfig /h off
del /F /Q c:\users\Public\Desktop\sysprep.cmd
del /F /Q %windir%\sysprep.xml
net user adminse3 /delete
%SystemRoot%\system32\shutdown.exe -r -t 20  -c "Le poste est pret pour le clonage . Si vous voulez cloner avec un outil externe, ou capturer une image, surtout ne redemarrez pas Windows avant le clonage!"
goto fin
) else (goto nosysprep)
REM
REM ############################# CLONAGE SANS SYSPREP ##############################
REM
:nosysprep
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultUserName" /d ".\{{ $adminseName }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultPassword" /d "{{ $adminsePasswd }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoAdminLogon" /d 1 /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoLogonCount" /d 1 /F >NUL
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run" /v "action" /d  "powershell -Command start cmd -v runAs -f %windir%\autorun.cmd" /F >NUL
powershell -command "$User = '{{ $domain }}\{{ $se4installName }}';$PWord = ConvertTo-SecureString -String '{{ $se4installPasswd }}' -AsPlainText -Force;$Credential = New-Object -TypeName System.Management.Automation.PSCredential -ArgumentList $User, $PWord;Remove-Computer -UnjoinDomaincredential $Credential -WorkGroupName clone -Force"
curl -F "etape=nosysprep" -F "ret=0" -F "uuid=%UUID%" -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
%SystemRoot%\system32\shutdown.exe -r -t 20  -c "Le poste est pret pour le clonage . Si vous voulez cloner avec un outil externe, ou capturer une image, surtout ne redemarrez pas Windows avant le clonage!"
goto fin

echo ERREUR Le sysprep ne passe pas,
echo fermez la session, connectez vous sur chaque compte ayant un profil dans c:\users
echo et lancez le script sysprep.cmd present sur le bureau
echo puis ouvrez a nouveau le compte se4install et lancez c:\windows\autorun.cmd
dir c:\users
pause

:fin
if  exist %windir%\gpo.txt (del /F /S /Q %windir%\gpo.txt)
if  exist c:\Netinst\nowpkg.txt (del /f /q c:\Netinst\nowpkg.txt)
