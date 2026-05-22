:: conf applications startup (fixture 17.3 — URL native API v1 substituée)
:: Cas heureux : le template upstream a déjà été patché et utilise le
:: placeholder `###_APPLICATIONS_SCRIPTS_URL_###` substitué post-extraction
:: par `specialise_gpo` legacy au moment d'`import_gpo`.
if [%SE4FS%]==[] set SE4FS=###_SE4FS_NAME_###
if exist "%temp%\applications-startup.cmd" (del /f /q "%temp%\applications-startup.cmd")
curl.exe -o "%temp%\applications-startup.cmd" "###_APPLICATIONS_SCRIPTS_URL_###?os=windows&action=startup" >NUL
if exist "%temp%\applications-startup.cmd" (call "%temp%\applications-startup.cmd")
