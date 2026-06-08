#!/bin/bash
set -e

mkdir -p database storage/framework/{cache,sessions,views} storage/logs
touch database/database.sqlite

php artisan migrate --force --no-interaction || true
php artisan config:clear
php artisan cache:clear

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
