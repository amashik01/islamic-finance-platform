@echo off
setlocal
cd /d "%~dp0"
title Amanah Capital - setup
echo.
echo === Amanah Capital: one-click setup (Windows) ===
echo.

where php >nul 2>&1 || (echo [X] PHP was not found. Add C:\xampp\php to your PATH, or install Laravel Herd. & pause & exit /b 1)
where composer >nul 2>&1 || (echo [X] Composer was not found. Install it from https://getcomposer.org/Composer-Setup.exe and run this again. & pause & exit /b 1)
where npm >nul 2>&1 || (echo [X] Node.js/npm was not found. Install it from https://nodejs.org and run this again. & pause & exit /b 1)

echo [1/6] Enabling required PHP extensions...
for /f "delims=" %%i in ('php -r "echo php_ini_loaded_file();"') do set "INI=%%i"
if "%INI%"=="" (echo [X] Could not find php.ini & pause & exit /b 1)
powershell -NoProfile -ExecutionPolicy Bypass -Command "$p='%INI%'; $c=Get-Content $p; $n=$c -replace '^\s*;\s*extension\s*=\s*(intl|zip|fileinfo|mbstring|pdo_sqlite|sqlite3|pdo_mysql|gd|curl|openssl)\s*$','extension=$1'; Set-Content -Path $p -Value $n -Encoding ASCII" || (echo [!] Could not edit %INI% - right-click setup.bat and choose "Run as administrator".)

echo [2/6] Installing PHP packages (a few minutes)...
php -r "exit(PHP_VERSION_ID>=80300?0:1);"
if errorlevel 1 (
  echo     PHP older than 8.3 detected: installing app packages only (test tools need PHP 8.3).
  call composer install --no-dev --no-interaction --prefer-dist
) else (
  call composer install --no-interaction --prefer-dist
)
if errorlevel 1 (echo [X] composer install failed. Copy the message above and send it to me. & pause & exit /b 1)

echo [3/6] Preparing settings...
if not exist .env copy .env.example .env >nul
php artisan key:generate --force

echo [4/6] Preparing the database (SQLite)...
set FRESH=0
if not exist database\database.sqlite (type nul > database\database.sqlite & set FRESH=1)
if "%FRESH%"=="1" (php artisan migrate --seed --force) else (php artisan migrate --force)
if errorlevel 1 (echo [X] Database setup failed. Copy the message above and send it to me. & pause & exit /b 1)

echo [5/6] Building the design assets...
if not exist public\build\manifest.json (call npm install & call npm run build)

echo [6/6] Starting the app...
echo.
echo   Open:  http://localhost:8000
echo   Logins (password: password):  admin@demo.test   investor1@demo.test   business1@demo.test
echo   Keep this window open. Press Ctrl+C to stop.
echo.
start "" http://localhost:8000
php artisan serve --port=8000
