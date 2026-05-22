:: conf applications startup (fixture 17.3 — placeholder hors whitelist)
:: Ce template introduit un placeholder `###_INVENTE_###` non whitelisté
:: pour valider le mécanisme de détection `unknown_placeholders`.
if [%SE4FS%]==[] set SE4FS=###_SE4FS_NAME_###
set INVENTE_VALUE=###_INVENTE_###
if exist "%temp%\applications-startup.cmd" (del /f /q "%temp%\applications-startup.cmd")
curl.exe -o "%temp%\applications-startup.cmd" "###_APPLICATIONS_SCRIPTS_URL_###?os=windows&action=startup" >NUL
if exist "%temp%\applications-startup.cmd" (call "%temp%\applications-startup.cmd")
