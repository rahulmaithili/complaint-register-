@echo off
title Gas Agency CRM - Network Server
color 0A

echo.
echo  ============================================================
echo   Gas Agency CRM - Starting Network Server...
echo  ============================================================
echo.

:: Add Windows Firewall rule to allow port 8000 (runs silently, admin not required for user rules)
netsh advfirewall firewall show rule name="Gas Agency CRM Port 8000" >nul 2>&1
if errorlevel 1 (
    echo  [SETUP] Adding Windows Firewall rule for Port 8000...
    netsh advfirewall firewall add rule name="Gas Agency CRM Port 8000" dir=in action=allow protocol=TCP localport=8000 >nul 2>&1
    if errorlevel 1 (
        echo  [WARN] Could not add firewall rule automatically.
        echo  [WARN] If other devices can't connect, run this file as Administrator.
    ) else (
        echo  [OK] Firewall rule added - Port 8000 is now open for network access.
    )
) else (
    echo  [OK] Firewall rule already exists for Port 8000.
)

echo.
echo  [INFO] Starting server... Please wait.
echo.

node "%~dp0start.js"

pause
