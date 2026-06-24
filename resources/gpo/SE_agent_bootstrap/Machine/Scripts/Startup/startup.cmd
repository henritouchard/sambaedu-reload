@echo off
:: ============================================================================
:: SambaEdu -- GPO-dispatcher figee "SE_agent_bootstrap" (Story 25.4 / 27.16, FR25 + #27)
:: ----------------------------------------------------------------------------
:: LE DERNIER ARTEFACT AD, JAMAIS RE-EDITE. Script GENERIQUE (event -> install/
:: reparation), AUCUNE logique metier. Seule specialisation : ###_SE4FS_NAME_###
:: / ###_DOMAIN_### (nom serveur, fige 1x a la publication). Machine/user
:: resolus au runtime.
::
:: Execute en SYSTEM au demarrage (Machine/Scripts/Startup). Il :
::   (a) deploie la racine CA dans LocalMachine\Root (certutil, idempotent) ;
::   (b) telecharge le binaire stable a son emplacement DEFINITIF ;
::   (c) lance "agent.exe install" (idempotent : installe OU repare un agent
::       brique/supprime -- le filet eternel #27). Le token survit (hors perimetre
::       install) : un poste deja enrole repart en convergence directe ;
::  (c2) pose le client WPKG wpkg-client.vbs en %WinDir% depuis le bundle HTTP
::       (l'agent le declenche mais ne le telecharge pas -- D7) ;
::   (d) (re)cree une tache planifiee de refresh (SYSTEM, periodique) qui rejoue
::       (a)-(c2) -- le filet du "poste jamais eteint". Auto-reparation : la tache
::       est recreee si absente.
::
:: Une fois pose, l'agent DEMANDE son enrolement (porte 2) et converge des
:: l'approbation un-clic de l'admin (25.3). La GPO ne decide rien, elle pose et
:: repare.
:: ============================================================================

if [%SE4FS%]==[] set SE4FS=###_SE4FS_NAME_###

set "AGENT_DIR=%ProgramFiles%\SambaEdu\Agent"
set "AGENT_EXE=%AGENT_DIR%\agent.exe"
set "CA_TMP=%TEMP%\se4-ca.crt"
set "TASK_NAME=SambaEduAgent-Bootstrap-Refresh"

:: --- (a) racine CA (idempotent, dedup par empreinte) ------------------------
if not exist "%AGENT_DIR%" md "%AGENT_DIR%"
curl.exe -s -o "%CA_TMP%" "http://%SE4FS%/api/v1/agent/ca"
if exist "%CA_TMP%" certutil -addstore -f Root "%CA_TMP%" >nul 2>&1

:: --- (b) binaire stable a l'emplacement DEFINITIF ---------------------------
curl.exe -s -o "%AGENT_EXE%" "http://%SE4FS%/api/v1/agent/stable/download"

:: --- (c) install/reparation du service (idempotent) -------------------------
if exist "%AGENT_EXE%" "%AGENT_EXE%" install -server-url "http://%SE4FS%"

:: --- (c2) client WPKG : wpkg-client.vbs + wpkg-se4.js locaux (self-heal SYSTEM)
:: L'agent DECLENCHE wpkg-client.vbs mais ne le telecharge pas (choix D7). La
:: copie SMB a l'install (autologon se4install + auth ADS) est fragile ; on pose
:: donc le client ICI, en SYSTEM (compte machine -> auth fiable), depuis le bundle
:: HTTP. La vbs localise le moteur a c:\windows\install\wpkg\wpkg-se4.js (MapZ,
:: jadis symlink SMB pose par wpkg.cmd) ; sur greenfield ce symlink n'existe pas
:: -> exit 13 (« wpkg-se4.js absent »). On pose donc le moteur LOCALEMENT au meme
:: chemin (repertoire reel, pas de SMB) ; il est HTTP-aware (lit SE4_WPKG_BUNDLE_URL
:: au runtime, fourni par l'agent au declenchement). Telechargement en .tmp +
:: bascule SEULEMENT si taille plausible (>1000 o) : un corps d'erreur 404 (~50 o)
:: n'ecrase jamais un fichier sain (lecon du faux agent.exe de 49 o).
curl.exe -s -o "%TEMP%\wpkg-client.vbs.tmp" "http://%SE4FS%/wpkg/bundle/wpkg-client.vbs"
for %%A in ("%TEMP%\wpkg-client.vbs.tmp") do if %%~zA GTR 1000 copy /Y "%TEMP%\wpkg-client.vbs.tmp" "%WINDIR%\wpkg-client.vbs" >nul
if exist "%TEMP%\wpkg-client.vbs.tmp" del /f /q "%TEMP%\wpkg-client.vbs.tmp"
if not exist "%WINDIR%\install\wpkg" md "%WINDIR%\install\wpkg" 2>nul
curl.exe -s -o "%TEMP%\wpkg-se4.js.tmp" "http://%SE4FS%/wpkg/bundle/wpkg-se4.js"
for %%A in ("%TEMP%\wpkg-se4.js.tmp") do if %%~zA GTR 1000 copy /Y "%TEMP%\wpkg-se4.js.tmp" "%WINDIR%\install\wpkg\wpkg-se4.js" >nul
if exist "%TEMP%\wpkg-se4.js.tmp" del /f /q "%TEMP%\wpkg-se4.js.tmp"

:: --- (d) (re)creation de la tache de refresh (SYSTEM, toutes les 240 min) ----
:: Auto-reparation : si la tache est absente, on la recree (le filet eternel).
schtasks /Query /TN "%TASK_NAME%" >nul 2>&1
if errorlevel 1 schtasks /Create /TN "%TASK_NAME%" /TR "\"%~f0\"" /SC MINUTE /MO 240 /RU SYSTEM /RL HIGHEST /F >nul 2>&1

exit /b 0
