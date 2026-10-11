@echo off
rem Subprocess wrapper: the §43 sign-up test runs the real wizard in a child
rem process because setup.php finishes with redirect()+exit. The scratch
rem database name has to reach that child through the environment.
setlocal
set "DBNAME=%~1"
if "%DBNAME%"=="" exit /b 2
set "BEAUTY_DB_NAME=%DBNAME%"
"C:\xampp\php\php.exe" "%~dp0_wizard_probe.php" 2>&1
exit /b %ERRORLEVEL%