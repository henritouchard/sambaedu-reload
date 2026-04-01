<?php
/*
 * actions Windows (10)
 * génération d'un fichier action.cmd, qui est lancé depuis la gpo "se4_sysprep" qui execute un script startup
 * ou par l'autologon post-install ou sysprep
 *
 * curl -F "name=%computername%" "http://###_SE4FS_NAME_###/ipxe/Win10/action.php">%windir%\action.cmd
 * if exist %windir%\action.cmd (call %windir%\action.cmd)
 *
 * on passe le nom de la machine et ret, qui définit l'étape en cours (0 par défaut), et on récupère un fichier cmd
 *
 * problème, on ne peut pas redonner à la machine le meme nom qu'elle avait avant le sysprep ! il faut donc
 * procéder en deux fois :
 *
 * Important le système doit permettre le lancement de scripts avec les privilèges administrateur sans demander de confirmation
 * UAC désactivée. Il semblemerait malheureusement que cela soit pas toujours le cas malgré une gpo
 * 
 * 
 * Préparation :
 * -1- reboot
 * -1 - gpo startup - renomme => DL action.bat : renommer le poste + autologon admin + autorun action.cmd + reboot
 * 2 - sysprep + reboot
 * 3 - clonage sysrecuecd + modif sysprep.xml pour changer le nom
 * 4 - retour au domaine
 * 5 - autologon admin + time + wpkg + reboot
 *
 * post-integration manuelle ou migration :
 * 1 - reboot
 * 2 - gpo startup DL action.bat : autologon admin + autorun action.cmd + reboot
 * 3 -
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require "ipxe_functions.inc.php";
require "windows.inc.php";

if (isset($_POST['name'])) {
    $name = $_POST['name'];
    $type = "default";
    $ret = - 1;
    $machine = get_action($config, $name);
    if (count($machine) > 0 && isset($machine['dn'])) {
        // on considère que la machine doit être dans l'AD à ce stade ? à vérifier
        $id = $machine['id'] ?? "";
        $uuid = $machine['netbootguid'] ?? "";
        if (isset($machine['action'][$uuid]['script'])) {
            $script = $machine['action'][$uuid]['script'];
            $type = $machine['action']['type'] ?? 'default';
            $ret = $machine['action'][$uuid]['ret'] ?? - 1;
            $role = $machine['action'][$uuid]['role'] ?? "";
        } else {
            $script = "default";
            $role = "";
        }
        $etape = $_POST['etape'] ?? $machine['action'][$uuid]['etape'] ?? $type;
        $ou = ldap_dn2oudn($machine['dn']);
        $clone_name = substr($name, 0, 6) . "-" . random_int(0, 9999);

        // bouts de scripts cmd à envoyer

        $cmd_header = "REM cmd\r
REM  script de demarrage genere automatiquement\r
REM  pour $name, $id, $uuid, $type, $role, $etape, $script, $ret\r
REM \r\n";

        // actions preparation clonage lancée par gpo
        // etape 0 : mise en place autologon se4install,
        // etape 1 : autologon se4install et sysprep. Si erreur sysprep sortie du domaine et préparation autologon adminse post-clonage.
        //

        $cmd_sysprep = "REM \r
for /f \"delims=\" %%a in ('powershell -NoLogo -NoProfile -Command \"(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID\"') do (set \"UUID=%%a\"\r
goto uuid)\r
:uuid\r
if [%username%]==[" . $config['se4install_name'] . "] (goto autologon) else (goto gpo)\r
:gpo\r
if exist %windir%\\gpo.txt(goto fin)\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultUserName\" /d \"" . $config['se4install_name'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultDomainName\" /d \"" . $config['domain'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /d \"" . $config['se4install_passwd'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /d 1 /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoLogonCount\" /d 1 /F >NUL\r
reg.exe add \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /d  \"powershell -Command start cmd -v runAs -f %windir%\\autorun.cmd\" /F >NUL\r
echo 1> c:\\netinst\\nowpkg.txt\r
echo OK> %windir%\\gpo.txt\r
schtasks /end /tn wpkg4\r
schtasks /delete  /F /tn wpkg4\r
curl -F \"ret=0\" -F \"etape=sysprep\" -F \"uuid=%UUID%\"  -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
copy /Y %windir%\\action.cmd %windir%\\autorun.cmd\r
%SystemRoot%\\system32\\shutdown.exe -r\r
%SystemRoot%\\system32\\shutdown.exe -r  -c \"Le poste va redemarrer en admin sous le nom " . $clone_name . "\"\r
goto fin\r
\r
\r
\r
:autologon\r
%SystemRoot%\\system32\\shutdown.exe /a\r
reg.exe delete \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /F >NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /F>NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /F >NUL\r
powercfg.exe /hibernate off\r
echo 1> c:\\netinst\\nowpkg.txt\r
curl -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/sysprep.xml.php>%windir%\\sysprep.xml\r
if exist %windir%\\sysprep.xml (goto sysprep) else (goto erreur)\r
:sysprep\r
powershell -command \"Rename-Computer -NewName " . $clone_name . "\"\r
REM pause\r
REM  on est encore au domaine donc on peut se connecter sur install\r
if exist %windir%\\install\\os\\netinst\\sysprep.ps1 (powershell -noprofile -executionpolicy bypass -file %windir%\\install\\os\\netinst\\sysprep.ps1) else (goto erreur)\r
%windir%\\system32\\sysprep\\sysprep.exe /generalize /oobe /quiet /quit /unattend:%windir%\\sysprep.xml>%windir%\\sysprep.log\r
if exist %windir%\\system32\\sysprep\\sysprep_succeeded.tag (curl -F \"etape=sysprep\" -F \"ret=1\" -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
REM pwrconfig /h off\r
del /F /Q c:\\users\\Public\\Desktop\\sysprep.cmd\r
del /F /Q %windir%\\sysprep.xml\r
net user adminse3 /delete\r
%SystemRoot%\\system32\\shutdown.exe -r -t 20  -c \"Le poste est pret pour le clonage . Si vous voulez cloner avec un outil externe, ou capturer une image, surtout ne redemarrez pas Windows avant le clonage!\"\r
goto fin\r
) else (goto nosysprep)\r
REM\r
REM ############################# CLONAGE SANS SYSPREP ##############################\r
REM\r
:nosysprep\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultUserName\" /d \".\\" . $config['adminse_name'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /d \"" . $config['adminse_passwd'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /d 1 /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoLogonCount\" /d 1 /F >NUL\r
reg.exe add \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /d  \"powershell -Command start cmd -v runAs -f %windir%\\autorun.cmd\" /F >NUL\r
powershell -command \"\$User = '" . $config['domain'] . "\\" . $config['se4install_name'] . "';\$PWord = ConvertTo-SecureString -String \"" . $config['se4install_passwd'] . "\" -AsPlainText -Force;\$Credential = New-Object -TypeName System.Management.Automation.PSCredential -ArgumentList \$User, \$PWord;Remove-Computer -UnjoinDomaincredential \$Credential -WorkGroupName clone -Force\"\r
curl -F \"etape=sysprep\" -F \"ret=2\" -F \"uuid=%UUID%\" -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
%SystemRoot%\\system32\\shutdown.exe -r -t 20  -c \"Le poste est pret pour le clonage . Si vous voulez cloner avec un outil externe, ou capturer une image, surtout ne redemarrez pas Windows avant le clonage!\"\r
goto fin\r

echo ERREUR Le sysprep ne passe pas,\r
echo fermez la session, connectez vous sur chaque compte ayant un profil dans c:\\users\r
echo et lancez le script sysprep.cmd present sur le bureau\r
echo puis ouvrez a nouveau le compte se4install et lancez c:\\windows\\autorun.cmd\r
dir c:\\users\r
pause\r

:fin\r
if  exist %windir%\\gpo.txt (del /F /S /Q %windir%\\gpo.txt)\r
if  exist c:\\netinst\\nowpkg.txt (del /f /q c:\\netinst\\nowpkg.txt)\r\n";

        // actions preparation clonage lancée par gpo startup
        // etape 0 : mise en place autologon se4install,
        // etape 1 : autologon se4install et sortie du domaine et préparation autologon adminse post-clonage.
        //

        $cmd_nosysprep = "REM \r
for /f \"delims=\" %%a in ('powershell -NoLogo -NoProfile -Command \"(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID\"') do (set \"UUID=%%a\"\r
goto uuid)\r
:uuid\r
if [%username%]==[" . $config['se4install_name'] . "] (goto autologon) else (goto gpo)\r
:gpo\r
if exist %windir%\\gpo.txt(goto fin)\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultUserName\" /d \"" . $config['se4install_name'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultDomainName\" /d \"" . $config['domain'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /d \"" . $config['se4install_passwd'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /d 1 /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoLogonCount\" /d 1 /F >NUL\r
reg.exe add \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /d  \"powershell -Command start cmd -v runAs -f %windir%\\autorun.cmd\" /F >NUL\r
echo 1> c:\\netinst\\nowpkg.txt\r
echo OK> %windir%\\gpo.txt\r
schtasks /end /tn wpkg4\r
schtasks /delete  /F /tn wpkg4\r
net accounts /maxpwage:unlimited\r
curl -F \"ret=0\" -F \"etape=sysprep\" -F \"uuid=%UUID%\"  -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
copy /Y %windir%\\action.cmd %windir%\\autorun.cmd\r
%SystemRoot%\\system32\\shutdown.exe -r -t 5\r
goto fin\r
\r
\r
\r
:autologon\r
%SystemRoot%\\system32\\shutdown.exe /a\r
powercfg.exe /hibernate off\r
echo 1> c:\\netinst\\nowpkg.txt\r
rem powershell -command \"Rename-Computer -NewName " . $clone_name . "\"\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultUserName\" /d \".\\" . $config['adminse_name'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /d \"" . $config['adminse_passwd'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /d 1 /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoLogonCount\" /d 2 /F >NUL\r
reg.exe add \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /d  \"powershell -Command start cmd -v runAs -f %windir%\\autorun.cmd\" /F >NUL\r
powershell -command \"\$User = '" . $config['domain'] . "\\" . $config['se4install_name'] . "';\$PWord = ConvertTo-SecureString -String \"" . $config['se4install_passwd'] . "\" -AsPlainText -Force;\$Credential = New-Object -TypeName System.Management.Automation.PSCredential -ArgumentList \$User, \$PWord;Remove-Computer -UnjoinDomaincredential \$Credential -WorkGroupName clone -Force\"\r
curl -F \"etape=sysprep\" -F \"ret=2\" -F \"uuid=%UUID%\" -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
%SystemRoot%\\system32\\shutdown.exe -r -t 5  -c \"Le poste est pret pour le clonage . Si vous voulez cloner avec un outil externe, ou capturer une image, surtout ne redemarrez pas Windows avant le clonage!\"\r
goto fin\r
:fin\r
if  exist %windir%\\gpo.txt (del /F /S /Q %windir%\\gpo.txt)\r
if  exist c:\\netinst\\nowpkg.txt (del /f /q c:\\netinst\\nowpkg.txt)\r\n";

        // actions postinst lancés par gpo startup
        // etape 0 : mise en place autologon se4install,
        // etape 1 : autologon se4install, lancement autorun et recup un nouveau script et le lance en direct.

        $cmd_post = "REM \r
for /f \"delims=\" %%a in ('powershell -NoLogo -NoProfile -Command \"(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID\"') do (set \"UUID=%%a\"\r
goto uuid)\r
:uuid\r
if [%username%]==[" . $config['se4install_name'] . "] (goto autologon) else (goto gpo)\r
:gpo\r
if exist %windir%\\gpo.txt(goto fin)\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultUserName\" /d \"" . $config['se4install_name'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultDomainName\" /d \"" . $config['domain'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /d \"" . $config['se4install_passwd'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /d 1 /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoLogonCount\" /d 1 /F >NUL\r
reg.exe add \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /d  \"powershell -Command start cmd -v runAs -f %windir%\\autorun.cmd\" /F >NUL\r
REM  les fichiers n'ont  pas ete copies lors de l'installation\r
if not exist %WINDIR%\\Web\\SE4\\ (md %WINDIR%\\Web\\SE4)\r
if not exist  \"%PROGRAMFILES%\\SambaEdu\" (md \"%PROGRAMFILES%\\SambaEdu\")
robocopy c:\\netinst \"%PROGRAMFILES%\\SambaEdu\" /MOVE
echo 1> c:\\netinst\\nowpkg.txt\r
echo OK> %windir%\\gpo.txt\r
curl  -F \"etape=post\ -F \"ret=0\" -F \"uuid=%UUID%\"  -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
if exist %windir%\\action.cmd (copy /Y %windir%\\action.cmd %windir%\\autorun.cmd)\r
%SystemRoot%\\system32\\shutdown.exe -r  -c \"Le poste va redemarrer en " . $config['se4install_name'] . " pour finir sa configuration\"\r
goto fin\r
:autologon\r
reg.exe delete \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /F >NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /F>NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /F >NUL\r
if not exist %windir%\\action.cmd (curl --fail -F \"etape=post\" -F \"name=%computername%\" -o \"%windir%\\action.cmd\" \"http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\")\r
reg.exe add HKCU\Console /v QuickEdit /t REG_DWORD /d 0 /f\r
if exist %windir%\\action.cmd (start %windir%\\action.cmd)\r
reg.exe delete HKCU\Console /v QuickEdit /t REG_DWORD /d 0 /f\r
:fin\r
if  exist %windir%\\gpo.txt (del /F /S /Q %windir%\\gpo.txt)\r
if  exist c:\\netinst\\nowpkg.txt (del /f /q c:\\netinst\\nowpkg.txt)\r\n";

        // actions postinst lancés par l'installeur windows en passe oobe
        // etape 0 : autologon se4install et lancement wpkg

        $cmd_oobe = "for /f \"delims=\" %%a in ('powershell -NoLogo -NoProfile -Command \"(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID\"') do (set \"UUID=%%a\"\r
goto uuid)\r
:uuid\r
if [%username%]==[" . $config['se4install_name'] . "] (goto admin) else (goto fin)\r
:admin\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoLogonCount\" /d 0 /F >NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /F>NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /F >NUL\r
if exist %windir%\\autorun.cmd (del /F /S /Q %windir%\\autorun.cmd)\r
REM  pose probleme ?\r
REM  net time /set /yes\r
setx SE4FS " . $config['se4fs_name'] . " /m\r
setx SE4FS " . $config['se4fs_name'] . "\r
set SE4FS=" . $config['se4fs_name'] . "\r
gpupdate /force /target:computer\r
if exist \"%PROGRAMFILES%\\SambaEdu\\SetWallpaper.ps1\" (copy /y \"%PROGRAMFILES%\\SambaEdu\\SetWallpaper.ps1\" %WINDIR%\\Web\\SE4\\SetWallpaper.ps1)\r
powershell -noprofile -noninteractive -executionpolicy bypass -file \"%PROGRAMFILES%\\SambaEdu\\driversAuto.ps1\"\r
powershell -noprofile -noninteractive -executionpolicy bypass -file \"%PROGRAMFILES%\\SambaEdu\\winget-install.ps1\"\r
rem DISM /Online /Add-Capability /CapabilityName:WMIC~~~~\r
powercfg /hibernate off\r
schtasks /run /tn wpkg4\r
%windir%\\system32\\bcdboot c:\\windows /addlast\r
curl -F \"etape=oobe\" -F \"ret=0\" -F \"uuid=%UUID%\"  -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
echo install finie>c:\\netinst\\install.log\r
%SystemRoot%\\system32\\shutdown.exe -r\r
:fin\r
if  exist %windir%\\gpo.txt (del /F /S /Q %windir%\\gpo.txt)\r
if  exist c:\\netinst\\nowpkg.txt (del /f /q c:\\netinst\\nowpkg.txt)\r\n";

        // lancement wpkg en mode interactif
        // etape 0 : autologon se4install et lancement wpkg

        $cmd_wpkg = "REM \r
for /f \"delims=\" %%a in ('powershell -NoLogo -NoProfile -Command \"(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID\"') do (set \"UUID=%%a\"\r
goto uuid)\r
:uuid\r
if [%username%]==[" . $config['se4install_name'] . "] (goto autologon) else (goto gpo)\r
:gpo\r
if exist %windir%\\gpo.txt(goto fin)\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultUserName\" /d \"" . $config['se4install_name'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultDomainName\" /d \"" . $config['domain'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /d \"" . $config['se4install_passwd'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /d 1 /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoLogonCount\" /d 1 /F >NUL\r
reg.exe add \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /d  \"powershell -Command start cmd -v runAs -f %windir%\\autorun.cmd\" /F >NUL\r
echo 1> c:\\netinst\\nowpkg.txt\r
echo OK> %windir%\\gpo.txt\r
curl  -F \"etape=wpkg\ -F \"ret=0\" -F \"uuid=%UUID%\"  -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
if exist %windir%\\action.cmd (copy /Y %windir%\\action.cmd %windir%\\autorun.cmd)\r
%SystemRoot%\\system32\\shutdown.exe -r -f -c \"Le poste va redemarrer en " . $config['se4install_name'] . " pour finir sa configuration\"\r
rem if exist %windir%\\action.cmd (start del /F /Q %windir%\\action.cmd)\r
goto fin\r

:autologon\r
if exist %windir%\\gpo.txt(goto fin)\r
%SystemRoot%\\system32\\shutdown.exe /a\r
reg.exe delete \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /F >NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /F>NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /F >NUL\r

if  exist %windir%\\gpo.txt (del /F /S /Q %windir%\\gpo.txt)\r
if  exist c:\\netinst\\nowpkg.txt (del /f /q c:\\netinst\\nowpkg.txt)\r
if exist  %windir%\\wpkg-gpo.txt Del /F /Q %WinDir%\\wpkg-client.vbs\r
if exist  %windir%\\wpkg-gpo.txt Del /F /Q %WinDir%\\wpkg-client.txt\r
if exist  %windir%\\wpkg-gpo.txt Del /F /Q %WinDir%\\wpkg-client.log\r
if not exist  %Windir%\\install mklink /D %Windir%\\install \\\\" . $config['se4fs_name'] . "\\install\r
if not exist  %Windir%\\rapports mklink /D %Windir%\\rapports \\\\" . $config['se4fs_name'] . "\\rapports\r
if exist z:\\wpkg\\wpkg-client.vbs net use z: /delete /y\r
if not exist %WinDir%\\wpkg-client.vbs copy /Y /V /B %windir%\\install\\wpkg\\wpkg-client.vbs %WinDir%\\wpkg-client.vbs\r
if exist %WinDir%\\wpkg-client.vbs echo forcage wpkg en admin le %date%>> %windir%\\wpkg-gpo.txt\r
powershell -noprofile -noninteractive -executionpolicy bypass -file \"%PROGRAMFILES%\\SambaEdu\\driversAuto.ps1\"\r
powershell -noprofile -noninteractive -executionpolicy bypass -file \"%PROGRAMFILES%\\SambaEdu\\winget-install.ps1\"\r
start /wait \"%Programfiles%\\Sambaedu\\Nettoyage WPKG.cmd\"\r
curl -F \"etape=wpkg\" -F \"ret=1\" -F \"uuid=%UUID%\" -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
%SystemRoot%\\system32\\shutdown.exe /l\r
:fin\r\n";

        // renommage d'un poste au domaine lancé par gpo
        // etape 0 : mise en place autologon se4install,
        // etape 1 : autologon se4install et renommage

        $cmd_renomme = "REM \r
for /f \"delims=\" %%a in ('powershell -NoLogo -NoProfile -Command \"(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID\"') do (set \"UUID=%%a\"\r
goto uuid)\r
:uuid\r
if [%username%]==[" . $config['se4install_name'] . "] (goto autologon) else (goto gpo)\r
:gpo\r
rem if exist %windir%\\gpo.txt(goto fin)\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultUserName\" /d \"" . $config['se4install_name'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultDomainName\" /d \"" . $config['domain'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /d \"" . $config['se4install_passwd'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /d 1 /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoLogonCount\" /d 1 /F >NUL\r
reg.exe add \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /d  \"powershell -Command start cmd -v runAs -f %windir%\\autorun.cmd\" /F >NUL\r
echo 1> c:\\netinst\\nowpkg.txt\r
echo OK> %windir%\\gpo.txt\r
schtasks /end /tn wpkg4\r
schtasks /delete  /F /tn wpkg4\r
curl -F \"etape=renomme\" -F \"ret=0\" -F \"uuid=%UUID%\" -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
copy /Y %windir%\\action.cmd %windir%\\autorun.cmd\r
%SystemRoot%\\system32\\shutdown.exe  -t 5 -r  -c \"Le poste va redemarrer en " . $config['se4install_name'] . " pour etre renomme " . $role . "\"\r
goto fin\r
\r
:autologon\r
%SystemRoot%\\system32\\shutdown.exe /a\r
reg.exe delete \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /F >NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /F>NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /F >NUL\r
echo 1> c:\\netinst\\nowpkg.txt\r
powershell -command \"Rename-Computer -NewName " . $role . "\"\r
curl -F \"etape=renomme\" -F \"ret=1\" -F \"uuid=%UUID%\" -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
if  exist %windir%\\gpo.txt (del /F /S /Q %windir%\\gpo.txt)\r
%SystemRoot%\\system32\\shutdown.exe -r -t 5  -c \"Le poste est renomme " . $role . "\"\r
:fin\r
if  exist %windir%\\gpo.txt (del /F /S /Q %windir%\\gpo.txt)\r
if  exist c:\\netinst\\nowpkg.txt (del /f /q c:\\netinst\\nowpkg.txt)\r\n";

        // mise au domaine post-clonage sans sysprep
        // script autorun.cmd téléchargé depuis sysrescuecd
        // etape 0 : autologon adminse, et mise au domaine
        // etape 1 : autologon se4install et postinst

        $cmd_join = "REM \r
for /f \"delims=\" %%a in ('powershell -NoLogo -NoProfile -Command \"(Get-CimInstance -Class Win32_ComputerSystemProduct).UUID\"') do (set \"UUID=%%a\"\r
goto uuid)\r

:uuid\r
if [%username%]==[" . $config['se4install_name'] . "] (goto autologon)\r
if [%username%]==[" . $config['adminse_name'] . "] (goto join) else (goto fin)\r
:join\r
if exist \"%windir%\\gpo.txt\" (goto domaine)\r
powershell -command \"Rename-Computer -NewName " . $name . "\"\r
echo OK> %windir%\\gpo.txt\r
curl -F \"etape=join\" -F \"ret=0\" -F \"uuid=%UUID%\" -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
%SystemRoot%\\system32\\shutdown.exe -r  -t 5 -c \"Le poste  " . $name . " va redemarrer pour se mettre au domaine\"\r
goto fin\r
\r
:domaine\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultUserName\" /d \"" . $config['se4install_name'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultDomainName\" /d \"" . $config['domain'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /d \"" . $config['se4install_passwd'] . "\" /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /d 1 /F >NUL\r
reg.exe add \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoLogonCount\" /d 1 /F >NUL\r
reg.exe add \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /d  \"powershell -Command start cmd -v runAs -f %windir%\\autorun.cmd\" /F >NUL\r
echo 1> c:\\netinst\\nowpkg.txt\r
schtasks /end /tn wpkg4\r
schtasks /delete  /F /tn wpkg4\r
rem powershell -command \"\$User = '" . $config['domain'] . "\\" . $config['se4install_name'] . "';\$PWord = ConvertTo-SecureString -String '" . $config['se4install_passwd'] . "' -AsPlainText -Force;\$Credential = New-Object -TypeName System.Management.Automation.PSCredential -ArgumentList \$User, \$PWord;Add-Computer -NewName " . $name . " -Credential \$Credential -DomainName " . $config['domain'] . " -OUPath '" . $ou . "' -Force\"\r
powershell -command \"\$User = '" . $config['domain'] . "\\" . $config['se4install_name'] . "';\$PWord = ConvertTo-SecureString -String '" . $config['se4install_passwd'] . "' -AsPlainText -Force;\$Credential = New-Object -TypeName System.Management.Automation.PSCredential -ArgumentList \$User, \$PWord;Add-Computer -Credential \$Credential -DomainName " . $config['domain'] . " -OUPath '" . $ou . "' -Force\"\r
RD /S /Q %WinDir%\System32\GroupPolicyUsers\r
RD /S /Q %WinDir%\System32\GroupPolicy\r
curl -F \"etape=join\" -F \"ret=1\" -F \"uuid=%UUID%\" -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
%SystemRoot%\\system32\\shutdown.exe -r  -t 5 -c \"Le poste  " . $name . " va redemarrer en " . $config['se4install_name'] . " pour terminer la mise au domaine\"\r
goto fin\r
\r
:autologon\r
%SystemRoot%\\system32\\shutdown.exe /a\r
reg.exe delete \"HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run\" /v \"action\" /F >NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"DefaultPassword\" /F>NUL\r
reg.exe delete \"HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\" /v \"AutoAdminLogon\" /F >NUL\r
gpupdate /force /target:computer\r
REM  les fichiers ont ete copies lors de l'installation\r
if not exist %WINDIR%\\Web\\SE4\\ (md %WINDIR%\\Web\\SE4)\r
if exist \"%PROGRAMFILES%\\SambaEdu\\SetWallpaper.ps1\" (copy /y \"%PROGRAMFILES%\\SambaEdu\\SetWallpaper.ps1\" %WINDIR%\\Web\\SE4\\SetWallpaper.ps1)\r
if  exist c:\\netinst\\nowpkg.txt (del /f /q c:\\netinst\\nowpkg.txt)\r
schtasks /run /tn wpkg4\r
curl -F \"etape=join\" -F \"ret=2\" -F \"uuid=%UUID%\"  -F \"name=" . $name . "\" http://" . $config['se4fs_name'] . "/ipxe/Win10/action.php\r
echo install finie>c:\\netinst\\install.log\r
%SystemRoot%\\system32\\shutdown.exe /t 5 /r\r
:fin\r
rem if  exist %windir%\\gpo.txt (del /F /S /Q %windir%\\gpo.txt)\r\n";

        if (! isset($_POST['ret'])) {

            // pas de code retour, envoi d'un script si le compteur n'a pas été incrémenté.

            header("Content-type: text/plain");
            $out = $cmd_header;
            if ($ret < 0) {
                switch ($etape) {
                    case "sysprep":
                        // configuration autorun en se4install lancé par gpo
                        // changement du nom du poste avant sysprep pour pouvoir reprendre le nom ensuite !
                        if ($type == "clonage" || $type == "clonage2") {
                            $out .= $cmd_nosysprep;
                            set_action($config, $uuid, [
                                'type' => $type,
                                'role' => "modele",
                                'etape' => $etape
                            ]);
                            set_statut($id, "préparation 1er boot");
                        }
                        set_progress($id, "0%");
                        break;
                    case "nosysprep":
                        // configuration autorun en se4install lancé par gpo
                        // changement du nom du poste avant pour pouvoir reprendre le nom ensuite !
                        set_progress($id, "50%");
                        break;

                    case "join":
                        // autorun adminse avec script de changement de nom et retour au domaine lancé par script sysprep en cas d'erreur
                        $out .= $cmd_join;
                        set_action($config, $uuid, [
                            'role' => "windows",
                            'etape' => $etape
                        ]);
                        set_statut($id, "mise au domaine v2");
                        set_progress($id, "0%");

                        break;
                    case "renomme":
                        // configuration autorun en se4install, avec 1 reboot lancé par gpo
                        // changement du nom du poste
                        $out .= $cmd_renomme;
                        set_action($config, $uuid, [
                            'etape' => $etape
                        ]);
                        set_statut($id, "renommage au domaine");
                        set_progress($id, "20%");
                        break;
                    case "post":
                        // configuration autorun en se4install lancé par gpo
                        $out .= $cmd_post;
                        set_action($config, $uuid, [
                            'etape' => $etape
                        ]);
                        set_statut($id, "post-mise au domaine manuelle");
                        set_progress($id, "20%");
                        break;
                    case "wpkg":
                        // configuration autorun en se4install lancé par gpo
                        $out .= $cmd_wpkg;
                        set_action($config, $uuid, [
                            'etape' => $etape
                        ]);
                        set_progress($id, "10%");
                        set_statut($id, "lancement de wpkg en mode interactif");
                        break;
                    case "oobe":
                        // autologon se4install après une install ou un clonage+sysprep, l'autologon est configuré dans le xml
                        // mise à l'heure, lancement wpkg, gpupdate et fermeture session
                        $out .= $cmd_oobe;
                        set_action($config, $uuid, [
                            'role' => "windows",
                            'etape' => 'oobe'
                        ]);
                        set_statut($id, "mise au domaine v1");
                        set_progress($id, "90%");
                        break;
                    default:
                        http_response_code(403);
                }
            } elseif ($ret == 0) {
                switch ($etape) {
                    case "post":
                        // generation d'un second script ?
                        $out .= $cmd_post;
                        break;
                    case "join":
                        // autorun adminse avec script de changement de nom et retour au domaine lancé par script sysprep en cas d'erreur
                        $out .= $cmd_join;
                        set_statut($id, "mise au domaine v2");
                        set_progress($id, "0%");
                        break;
                    default:
                        http_response_code(403);
                }
            } else {
                switch ($etape) {
                    case "join":
                        // autorun adminse avec script de changement de nom et retour au domaine lancé par script sysprep en cas d'erreur
                        $out .= $cmd_join;
                        set_statut($id, "mise au domaine v2");
                        set_progress($id, "0%");
                        break;
                    default:
                        http_response_code(403);
                }
            }
            echo $out;
            file_put_contents("/tmp/actions-" . $type . "-" . $name . "-" . $etape . ".log", $out);
        } else { // if ($_POST['ret'] > $ret) {

            // validation de l'etape, le compteur est incrémenté

            $ret = $_POST['ret'];
            // fin du processus
            switch ($etape) {
                case "sysprep":
                    switch ($ret) {
                        case 0:
                            // préparation ok
                            set_action($config, $uuid, [
                                // 'type' => "clonage",
                                'type' => "clonage2",
                                'role' => "modele",
                                'script' => "windows",
                                'ret' => $ret
                            ]);
                            set_statut($id, "preparation image");
                            set_progress($id, "50%");
                            break;
                        case 1:
                            // sysprep ok
                            set_action($config, $uuid, [
                                // 'type' => "clonage",
                                'role' => "modele",
                                'script' => "rescuecd",
                                'ret' => - 1,
                                'etape' => "init-modele"
                            ]);
                            set_statut($id, "sysprep generalisation");
                            set_progress($id, "50%");
                            break;
                        case 2:
                            // pas de sysprep, mode clonage2
                            set_action($config, $uuid, [
                                'type' => "clonage2",
                                'role' => "modele",
                                'script' => "rescuecd",
                                'ret' => - 1,
                                'etape' => 'init-modele'
                            ]);
                            set_statut($id, "clonage sans sysprep");
                            set_progress($id, "100%");
                            break;
                    }
                    break;
                case "join": // retour au domaine après un clonage
                    switch ($ret) {
                        case 0:
                            // script adminse et mise au domaine ok
                            set_action($config, $uuid, [
                                'type' => "clonage2",
                                'role' => "windows",
                                'script' => "default",
                                'ret' => $ret
                            ]);
                            set_statut($id, "renommage sans sysprep OK");
                            set_progress($id, "30%");
                            break;
                        case 1:
                            // script adminse et mise au domaine ok
                            set_action($config, $uuid, [
                                'type' => "clonage2",
                                'role' => "windows",
                                'script' => "default",
                                'ret' => $ret
                            ]);
                            set_statut($id, "mise au domaine sans sysprep OK");
                            set_progress($id, "60%");
                            break;
                        case 2:
                            // action post integration ok
                            set_action($config, $uuid, [
                                'type' => "clonage2",
                                'role' => "windows",
                                'script' => "default",
                                'etape' => "default",
                                'ret' => - 1
                            ]);
                            set_progress($id, "100%");
                            set_statut($id, "clonage terminé");
                            break;
                    }
                    break;
                case "oobe": // script lancé par unattended.xml suite clonage sysprep / install
                    switch ($ret) {
                        case 0:
                            // mise au domaine ok
                            set_action($config, $uuid, [
                                'role' => "windows",
                                'script' => "default",
                                'ret' => - 1,
                                'etape' => "default"
                            ]);
                            set_statut($id, "script de demarrage post-install OK");
                            set_progress($id, "100%");
                            break;
                    }
                    break;
                case "post": // script lancé par
                    switch ($ret) {
                        case 0:
                            // autologon ok
                            set_action($config, $uuid, [
                                'role' => "windows",
                                'script' => "default",
                                'ret' => $ret
                            ]);
                            set_statut($id, "script de demarrage post-install OK");
                            set_progress($id, "50%");
                            break;
                        case 1:
                            // script imbriqué ok
                            set_action($config, $uuid, [
                                'role' => "windows",
                                'script' => "default",
                                'etape' => "default",
                                'ret' => - 1
                            ]);
                            set_statut($id, "script de demarrage post-install OK");
                            set_progress($id, "100%");
                            break;
                    }
                    break;
                case "wpkg": // script lancé par gpo
                    switch ($ret) {
                        case 0:
                            // exec de wpkg ok
                            set_action($config, $uuid, [
                                'role' => "windows",
                                'script' => "default",
                                'etape' => "wpkg",
                                'ret' => 0
                            ]);
                            set_statut($id, "lancement de wpkg interactif");
                            set_progress($id, "50%");
                            break;
                        case 1:
                            // exec de wpkg ok
                            set_action($config, $uuid, [
                                'role' => "windows",
                                'script' => "default",
                                'etape' => "default",
                                'ret' => - 1
                            ]);
                            set_statut($id, "script de d'exec de wpkg fini");
                            set_progress($id, "100%");
                            break;
                    }
                    break;

                case "renomme":
                    switch ($ret) {
                        case 0:
                            // on renomme la machine dans l'AD avant son retour au domaine.
                            if (! empty($role)) {
                                apcu_store("ldap_cache_invalid", true, 60);
                                $machine = get_action($config, $name);
                                $new_dn = "cn=" . $role . "," . ldap_dn2oudn($machine['dn']);
                                if (move_ad($config, $machine['cn'], $new_dn, "machine")) {
                                    $html = "";
                                    dns_delete($config, $machine['cn'], "", $html);
                                    $machine = get_action($config, $role);
                                    dns_add($config, $machine['cn'], $machine['iphostnumber'], true);
                                    set_progress($id, "60%");
                                    set_statut($id, "renommage dans l'AD OK et" . $html);
                                } else {
                                    set_progress($id, "40%");
                                    set_statut($id, "ERREUR renommage AD impossible");
                                }
                                set_action($config, $uuid, [
                                    'type' => "renomme",
                                    'id' => $id,
                                    'role' => $role,
                                    'script' => "default",
                                    'etape' => "default",
                                    'ret' => 0
                                ]);
                            } else {
                                set_progress($id, "20%");
                                set_statut($id, "ERREUR pas de nouveau nom");
                            }
                            break;
                        case 1:
                            // renommage windows ok
                            set_action($config, $uuid, [
                                'type' => "default",
                                'script' => "default",
                                'etape' => "default",
                                'ret' => - 1
                            ]);
                            set_progress($id, "100%");
                            set_statut($id, "Renommage terminé");
                            break;
                    }
                    break;

                default:
                    // fini
                    set_action($config, $uuid, [
                        'type' => "default",
                        'script' => "default",
                        'etape' => "default",
                        'ret' => - 1
                    ]);
                    set_os($config, $machine['cn'], "windows");
                    set_progress($id, "100%");
                    set_statut($id, "terminé");
            }
        }
    }
} else {
    file_put_contents("/tmp/actions_err.log", "erreur, -" . $script . "-" . $name . "-" . $type . " pas d'action pour cette machine\n", FILE_APPEND);
    http_response_code(403);
}
?>