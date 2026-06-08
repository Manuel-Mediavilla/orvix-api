#!/bin/bash
set -e

export APP_ENV="${APP_ENV:-production}"
export APP_DEBUG="${APP_DEBUG:-false}"
export LOG_CHANNEL="${LOG_CHANNEL:-stderr}"
export CACHE_STORE="${CACHE_STORE:-file}"
export SESSION_DRIVER="${SESSION_DRIVER:-file}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"
export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="${DB_DATABASE:-database/database.sqlite}"

mkdir -p database storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
touch database/database.sqlite
chmod -R 775 storage bootstrap/cache database

php artisan migrate --force --no-interaction
php artisan config:clear
php artisan route:clear
php artisan view:clear

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
