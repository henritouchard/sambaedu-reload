:: conf applications logoff (fixture 17.3 — URL legacy hardcodée)
if [%SE4FS%]==[] set SE4FS=###_SE4FS_NAME_###
if exist %temp%\applications-logoff.cmd (del /f /q %temp%\applications-logoff.cmd)
curl.exe -o %temp%\applications-logoff.cmd -F "os=windows" -F "action=logoff" -F "user=%username%" -F "userprofile=%userprofile%" -F "machine=%computername%" "http://%SE4FS%.###_DOMAIN_###/gpo/applications.php" >NUL
if exist %temp%\applications-logoff.cmd (call %temp%\applications-logoff.cmd)
