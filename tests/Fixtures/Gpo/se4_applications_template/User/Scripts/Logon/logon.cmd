:: conf applications logon (fixture 17.3 — URL legacy hardcodée)
if [%SE4FS%]==[] set SE4FS=###_SE4FS_NAME_###
if exist "%temp%\applications-logon.cmd" (del /f /q "%temp%\applications-logon.cmd")
curl.exe -o "%temp%\applications-logon.cmd" -F "os=windows" -F "action=logon" -F "userprofile=%userprofile%" -F "user=%username%" -F "machine=%computername%" "http://%SE4FS%.###_DOMAIN_###/gpo/applications.php" >NUL
if exist "%temp%\applications-logon.cmd" (call "%temp%\applications-logon.cmd")
