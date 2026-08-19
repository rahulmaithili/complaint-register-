@echo off
title Gas Agency CRM - Network Server
color 0A

echo.
echo  ============================================================
echo   Gas Agency CRM - Starting Network Server...
echo  ============================================================
echo.

:: Kill any orphaned processes from previous runs
echo  [SYSTEM] Cleaning up old background processes...
taskkill /IM php.exe /F >nul 2>&1
taskkill /IM node.exe /F >nul 2>&1

:: Add Windows Firewall rule to allow port 8000 (HTTP fallback)
netsh advfirewall firewall show rule name="Gas Agency CRM Port 8000" >nul 2>&1
if errorlevel 1 (
    netsh advfirewall firewall add rule name="Gas Agency CRM Port 8000" dir=in action=allow protocol=TCP localport=8000 >nul 2>&1
    echo  [OK] Firewall rule added for Port 8000 HTTP.
) else (
    echo  [OK] Firewall rule already exists for Port 8000.
)

:: Add Windows Firewall rule to allow port 8443 (HTTPS)
netsh advfirewall firewall show rule name="Gas Agency CRM Port 8443" >nul 2>&1
if errorlevel 1 (
    netsh advfirewall firewall add rule name="Gas Agency CRM Port 8443" dir=in action=allow protocol=TCP localport=8443 >nul 2>&1
    echo  [OK] Firewall rule added for Port 8443 HTTPS.
) else (
    echo  [OK] Firewall rule already exists for Port 8443.
)

echo.
echo  [INFO] Starting server... Please wait.
echo.

node "%~dp0start.js"

pause
