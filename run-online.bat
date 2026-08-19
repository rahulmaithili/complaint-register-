@echo off
setlocal EnableDelayedExpansion
echo ========================================================
echo       Gas Agency CRM - CUSTOM ONLINE LINK
echo ========================================================
echo.

set NAME_FILE=custom_link_name.txt

if exist "%NAME_FILE%" (
    set /p custom_name=<"%NAME_FILE%"
    echo Aapka pehle se set kiya hua naam mila: !custom_name!
    echo (Agar naya naam dalna ho toh 'custom_link_name.txt' file ko delete kar dein)
) else (
    set /p custom_name="Enter a custom name for your link (e.g. shivshakti): "
    echo !custom_name!> "%NAME_FILE%"
)

echo.
echo Starting local server on port 8000...
start "" "run-app.bat"

echo.
echo ========================================================
echo 🎉 AAPKA CUSTOM LINK TAIYAAR HO RAHA HAI...
echo ========================================================
echo Aapka link hoga: https://!custom_name!.loca.lt
echo.
echo Note: Pehli baar kholne par 'Click to Continue' bol 
echo sakta hai, wo normal hai. Bas click kar dijiyega.
echo ========================================================
echo.

echo Tunnel connect ho raha hai, kripya 5 second rukiye...
start /B npx localtunnel --port 8000 --local-host 127.0.0.1 --subdomain !custom_name! >nul 2>&1

timeout /t 5 /nobreak >nul

echo Browser me link open ho raha hai...
start "" "https://!custom_name!.loca.lt"

echo Tunnel successfully connected. Keep this window open!
pause
