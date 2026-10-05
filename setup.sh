#!/usr/bin/env bash
# One-command setup for macOS/Linux
set -e
cd "$(dirname "$0")"
command -v php >/dev/null || { echo "PHP 8.2+ is required"; exit 1; }
command -v composer >/dev/null || { echo "Composer is required"; exit 1; }
command -v npm >/dev/null || { echo "Node.js is required"; exit 1; }
if php -r 'exit(PHP_VERSION_ID>=80300?0:1);'; then composer install --no-interaction --prefer-dist; else composer install --no-dev --no-interaction --prefer-dist; fi
[ -f .env ] || cp .env.example .env
php artisan key:generate --force
if [ ! -f database/database.sqlite ]; then touch database/database.sqlite; php artisan migrate --seed --force; else php artisan migrate --force; fi
[ -f public/build/manifest.json ] || { npm install && npm run build; }
echo "Open http://localhost:8000  (admin@demo.test / investor1@demo.test / business1@demo.test, password: password)"
php artisan serve --port=8000
