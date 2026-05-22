:: conf applications shutdown (fixture 17.3 — URL legacy hardcodée)
if [%SE4FS%]==[] set SE4FS=###_SE4FS_NAME_###
if exist "%temp%\applications-shutdown.cmd" (del /f /q "%temp%\applications-shutdown.cmd")
curl.exe -o "%temp%\applications-shutdown.cmd" -F "os=windows" -F "action=shutdown" -F "user=%username%" -F "machine=%computername%" "http://%SE4FS%.###_DOMAIN_###/gpo/applications.php" >NUL
if exist "%temp%\applications-shutdown.cmd" (call "%temp%\applications-shutdown.cmd")
