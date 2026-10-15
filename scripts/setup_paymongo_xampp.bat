@echo off
setlocal EnableExtensions
title PayMongo QR Ph setup (XAMPP)

REM Run from repo root: scripts\setup_paymongo_xampp.bat
cd /d "%~dp0.."
if not exist "lending_system\tools\paymongo_migrate_cli.php" (
    echo ERROR: Run this from the Lending Management System repository root.
    exit /b 1
)

set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" (
    echo ERROR: XAMPP PHP not found at %PHP%
    echo Edit scripts\setup_paymongo_xampp.bat if XAMPP is installed elsewhere.
    exit /b 1
)

if not exist ".env" (
    echo.
    echo .env is missing. Copy .env.example to .env and set DB_* and PayMongo test keys.
    if exist ".env.example" copy /Y ".env.example" ".env" >nul
    echo Created .env from .env.example — edit it before continuing.
    notepad .env
    pause
)

echo.
echo === Step 1: Inspect database (lending_management on localhost) ===
"%PHP%" lending_system\tools\paymongo_migrate_cli.php --inspect
if errorlevel 1 (
    echo.
    echo Fix DB connection in .env and ensure XAMPP MySQL is running, then run this script again.
    exit /b 1
)

echo.
echo === Step 2: Show planned SQL ===
"%PHP%" lending_system\tools\paymongo_migrate_cli.php --plan

echo.
echo === Step 3: Apply migrations (requires verified backup) ===
set /p CONFIRM=Have you exported a backup of lending_management in phpMyAdmin? Type YES to apply: 
if /I not "%CONFIRM%"=="YES" (
    echo Skipped apply. Run again when ready.
    goto smoke
)

"%PHP%" lending_system\tools\paymongo_migrate_cli.php --apply --backup-confirmed
if errorlevel 1 (
    echo Migration apply failed. See messages above.
    exit /b 1
)

echo.
"%PHP%" lending_system\tools\paymongo_migrate_cli.php --inspect

:smoke
echo.
echo === Step 4: Webhook logic smoke test (no PayMongo network) ===
"%PHP%" lending_system\tools\paymongo_webhook_smoke_test.php
if errorlevel 1 exit /b 1

echo.
echo === Done (database + smoke test) ===
echo.
echo You still need to do manually on your PC:
echo   1. PayMongo Dashboard TEST mode: sk_test_ / pk_test_ in .env
echo   2. PAYMONGO_MODE=test and PAYMONGO_WEBHOOK_SECRET=whsk_...
echo   3. HTTPS tunnel (ngrok) to paymongo_webhook_lending.php
echo   4. Log in as COLLECTOR - Payments - Bank Transfer/E-Wallet - Generate QR Ph
echo.
pause
endlocal
