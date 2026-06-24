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
::   (b) telecharge le binaire stable SI besoin (GET conditionnel par hash :
::       304 si deja a jour, 200 si perime/corrompu) a son emplacement DEFINITIF ;
::   (c) lance "agent.exe install" (idempotent : installe OU repare un agent
::       brique/supprime -- le filet eternel #27). Le token survit (hors perimetre
::       install) : un poste deja enrole repart en convergence directe ;
::  (c2) pose le client WPKG (wpkg-client.vbs + wpkg-se4.js) depuis le bundle HTTP
::       (l'agent les declenche mais ne les telecharge pas -- D7) ;
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

:: --- (b) binaire stable a l'emplacement DEFINITIF (GET conditionnel par HASH) -
:: On calcule le SHA-256 de NOTRE agent.exe et on l'envoie en If-None-Match.
:: Serveur : ETag = hash de la stable -> 304 (aucun octet) si on est deja a jour,
:: 200 + binaire sinon. Avantage vs date : un binaire CORROMPU/ALTERE a un hash
:: different -> re-telecharge (un If-Modified-Since le raterait). Zero transfert
:: en regime etabli (7,7 Mo economises a chaque boot). Bascule via .tmp + garde
:: taille (>100 Ko) : ni un corps 404 (~50 o) ni un 304 (vide) n'ecrasent le .exe.
set "AGENT_SHA="
if exist "%AGENT_EXE%" for /f "skip=1 delims=" %%H in ('certutil -hashfile "%AGENT_EXE%" SHA256 2^>nul') do if not defined AGENT_SHA set "AGENT_SHA=%%H"
set "AGENT_SHA=%AGENT_SHA: =%"
if defined AGENT_SHA (curl.exe -fs -H "If-None-Match: \"%AGENT_SHA%\"" -o "%TEMP%\agent.tmp" "http://%SE4FS%/api/v1/agent/stable/download") else (curl.exe -fs -o "%TEMP%\agent.tmp" "http://%SE4FS%/api/v1/agent/stable/download")
for %%A in ("%TEMP%\agent.tmp") do if %%~zA GTR 100000 move /Y "%TEMP%\agent.tmp" "%AGENT_EXE%" >nul
if exist "%TEMP%\agent.tmp" del /f /q "%TEMP%\agent.tmp"

:: --- (c) install/reparation du service (idempotent) -------------------------
if exist "%AGENT_EXE%" "%AGENT_EXE%" install -server-url "http://%SE4FS%"

:: --- (c2) client WPKG : wpkg-client.vbs + wpkg-se4.js locaux (self-heal SYSTEM)
:: L'agent DECLENCHE wpkg-client.vbs mais ne le telecharge pas (choix D7). La
:: copie SMB a l'install (autologon se4install + auth ADS) est fragile ; on pose
:: donc le client ICI, en SYSTEM, depuis le bundle HTTP. La vbs localise le moteur
:: a c:\windows\install\wpkg\wpkg-se4.js (MapZ, jadis symlink SMB pose par
:: wpkg.cmd) ; sur greenfield ce symlink n'existe pas -> exit 13 (« wpkg-se4.js
:: absent »). On pose donc le moteur LOCALEMENT au meme chemin (repertoire reel,
:: pas de SMB) ; il est HTTP-aware (lit SE4_WPKG_BUNDLE_URL au runtime).
::
:: GET CONDITIONNEL (curl -z = If-Modified-Since) : en regime etabli le serveur
:: repond 304 -> AUCUN transfert (pas de re-telechargement a chaque boot). On ne
:: tire que si le fichier est ABSENT (self-heal) ou si le bundle a CHANGE (auto-
:: update). Bascule .tmp + garde taille >1000 o : un corps d'erreur 404 (~50 o)
:: n'ecrase jamais un fichier sain (lecon du faux agent.exe de 49 o). -f : pas de
:: corps sur erreur HTTP.
if exist "%WINDIR%\wpkg-client.vbs" (curl.exe -fs -z "%WINDIR%\wpkg-client.vbs" -o "%TEMP%\wcv.tmp" "http://%SE4FS%/wpkg/bundle/wpkg-client.vbs") else (curl.exe -fs -o "%TEMP%\wcv.tmp" "http://%SE4FS%/wpkg/bundle/wpkg-client.vbs")
for %%A in ("%TEMP%\wcv.tmp") do if %%~zA GTR 1000 copy /Y "%TEMP%\wcv.tmp" "%WINDIR%\wpkg-client.vbs" >nul
if exist "%TEMP%\wcv.tmp" del /f /q "%TEMP%\wcv.tmp"
if not exist "%WINDIR%\install\wpkg" md "%WINDIR%\install\wpkg" 2>nul
if exist "%WINDIR%\install\wpkg\wpkg-se4.js" (curl.exe -fs -z "%WINDIR%\install\wpkg\wpkg-se4.js" -o "%TEMP%\wse4.tmp" "http://%SE4FS%/wpkg/bundle/wpkg-se4.js") else (curl.exe -fs -o "%TEMP%\wse4.tmp" "http://%SE4FS%/wpkg/bundle/wpkg-se4.js")
for %%A in ("%TEMP%\wse4.tmp") do if %%~zA GTR 1000 copy /Y "%TEMP%\wse4.tmp" "%WINDIR%\install\wpkg\wpkg-se4.js" >nul
if exist "%TEMP%\wse4.tmp" del /f /q "%TEMP%\wse4.tmp"

:: --- (d) (re)creation de la tache de refresh (SYSTEM, toutes les 240 min) ----
:: Auto-reparation : si la tache est absente, on la recree (le filet eternel).
schtasks /Query /TN "%TASK_NAME%" >nul 2>&1
if errorlevel 1 schtasks /Create /TN "%TASK_NAME%" /TR "\"%~f0\"" /SC MINUTE /MO 240 /RU SYSTEM /RL HIGHEST /F >nul 2>&1

exit /b 0
