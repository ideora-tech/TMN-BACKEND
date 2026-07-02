#!/bin/sh
set -e

echo "==> Generating APP_KEY if not set..."
php artisan key:generate --force

echo "==> Caching config & routes..."
php artisan config:cache
php artisan route:cache

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Linking storage..."
php artisan storage:link || true

echo "==> Starting Laravel on port 4019..."
exec php artisan serve --host=0.0.0.0 --port=4019
