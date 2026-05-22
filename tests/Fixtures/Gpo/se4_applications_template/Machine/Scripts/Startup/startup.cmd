:: conf applications startup (fixture 17.3 — URL legacy hardcodée)
if [%SE4FS%]==[] set SE4FS=###_SE4FS_NAME_###
if exist "%temp%\applications-startup.cmd" (del /f /q "%temp%\applications-startup.cmd")
curl.exe -o "%temp%\applications-startup.cmd" -F "os=windows" -F "action=startup" -F "user=%username%" -F "machine=%computername%" "http://%SE4FS%.###_DOMAIN_###/gpo/applications.php" >NUL
if exist "%temp%\applications-startup.cmd" (call "%temp%\applications-startup.cmd")
