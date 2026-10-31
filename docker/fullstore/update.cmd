@echo off
rem Runs update.ps1 with the same arguments, without changing Windows' script
rem policy (which blocks .ps1 files by default). From docker\fullstore:
rem   update.cmd -Branch claude/determined-bell-zzbfot   (PowerShell: .\update.cmd ...)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0update.ps1" %*
exit /b %ERRORLEVEL%
