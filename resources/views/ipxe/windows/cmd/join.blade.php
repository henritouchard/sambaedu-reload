{{-- Story 3.8 - D6 / AC4.3 - Port iso legacy/modules/ipxe/Win10/action.php cmd_join (LOC 358-406). --}}
{{-- Securite critique : ce .cmd s'execute en SYSTEM cote Windows post-reboot. --}}
{{-- Sanitization : tous les placeholders {{ $name }}, {{ $role }}, {{ $ou }} passent --}}
{{-- par WindowsXmlPlaceholders::sanitizeBatPlaceholder() dans le builder. --}}
{{-- CRLF strict : le builder re-ecrit les line endings via str_replace post-render. --}}
REM cmd
REM  script de demarrage genere automatiquement
REM  pour {{ $name }}, {{ $id }}, {{ $uuid }}, {{ $type }}, {{ $role }}, {{ $etape }}, {{ $script }}, {{ $ret }}
REM
REM
for /f "delims=" %%a in ('powershell -NoLogo -NoProfile -Command "(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID"') do (set "UUID=%%a"
goto uuid)

:uuid
if [%username%]==[{{ $se4installName }}] (goto autologon)
if [%username%]==[{{ $adminseName }}] (goto join) else (goto fin)
:join
if exist "%windir%\gpo.txt" (goto domaine)
powershell -command "Rename-Computer -NewName {{ $name }}"
echo OK> %windir%\gpo.txt
curl -F "etape=join" -F "ret=0" -F "uuid=%UUID%" -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
%SystemRoot%\system32\shutdown.exe -r  -t 5 -c "Le poste  {{ $name }} va redemarrer pour se mettre au domaine"
goto fin

:domaine
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultUserName" /d "{{ $se4installName }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultDomainName" /d "{{ $domain }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultPassword" /d "{{ $se4installPasswd }}" /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoAdminLogon" /d 1 /F >NUL
reg.exe add "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoLogonCount" /d 1 /F >NUL
reg.exe add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run" /v "action" /d  "powershell -Command start cmd -v runAs -f %windir%\autorun.cmd" /F >NUL
echo 1> c:\Netinst\nowpkg.txt
schtasks /end /tn wpkg4
schtasks /delete  /F /tn wpkg4
rem powershell -command "$User = '{{ $domain }}\{{ $se4installName }}';$PWord = ConvertTo-SecureString -String '{{ $se4installPasswd }}' -AsPlainText -Force;$Credential = New-Object -TypeName System.Management.Automation.PSCredential -ArgumentList $User, $PWord;Add-Computer -NewName {{ $name }} -Credential $Credential -DomainName {{ $domain }} -OUPath '{{ $ou }}' -Force"
powershell -command "$User = '{{ $domain }}\{{ $se4installName }}';$PWord = ConvertTo-SecureString -String '{{ $se4installPasswd }}' -AsPlainText -Force;$Credential = New-Object -TypeName System.Management.Automation.PSCredential -ArgumentList $User, $PWord;Add-Computer -Credential $Credential -DomainName {{ $domain }} -OUPath '{{ $ou }}' -Force"
RD /S /Q %WinDir%\System32\GroupPolicyUsers
RD /S /Q %WinDir%\System32\GroupPolicy
curl -F "etape=join" -F "ret=1" -F "uuid=%UUID%" -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
%SystemRoot%\system32\shutdown.exe -r  -t 5 -c "Le poste  {{ $name }} va redemarrer en {{ $se4installName }} pour terminer la mise au domaine"
goto fin

:autologon
%SystemRoot%\system32\shutdown.exe /a
reg.exe delete "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run" /v "action" /F >NUL
reg.exe delete "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "DefaultPassword" /F>NUL
reg.exe delete "HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon" /v "AutoAdminLogon" /F >NUL
REM [LEGACY OFF 2026-06-12] canal legacy coupe pour test agent Go - restaurer ces 3 lignes pour reactiver
REM [LEGACY OFF 2026-06-12] gpupdate /force /target:computer
REM  les fichiers ont ete copies lors de l'installation
if not exist %WINDIR%\Web\SE4\ (md %WINDIR%\Web\SE4)
REM [LEGACY OFF 2026-06-12] if exist "%PROGRAMFILES%\SambaEdu\SetWallpaper.ps1" (copy /y "%PROGRAMFILES%\SambaEdu\SetWallpaper.ps1" %WINDIR%\Web\SE4\SetWallpaper.ps1)
if  exist c:\Netinst\nowpkg.txt (del /f /q c:\Netinst\nowpkg.txt)
REM [LEGACY OFF 2026-06-12] schtasks /run /tn wpkg4
curl -F "etape=join" -F "ret=2" -F "uuid=%UUID%"  -F "name={{ $name }}" http://{{ $se4fsName }}/ipxe/windows/action
echo install finie>c:\Netinst\install.log
%SystemRoot%\system32\shutdown.exe /t 5 /r
:fin
rem if  exist %windir%\gpo.txt (del /F /S /Q %windir%\gpo.txt)
