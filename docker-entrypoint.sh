#!/bin/sh
set -e

echo "==> Writing .env from Docker environment..."
cat > /var/www/html/.env <<EOF
APP_NAME="${APP_NAME:-TMN-Transport}"
APP_ENV=${APP_ENV:-production}
APP_KEY=
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL:-http://localhost:4019}
APP_TIMEZONE=${APP_TIMEZONE:-Asia/Jakarta}

LOG_CHANNEL=stderr
LOG_LEVEL=error

DB_CONNECTION=${DB_CONNECTION:-mysql}
DB_HOST=${DB_HOST:-mysql}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE:-tmn_transport}
DB_USERNAME=${DB_USERNAME:-hruser}
DB_PASSWORD=${DB_PASSWORD:-hrpassword123}

CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

SANCTUM_STATEFUL_DOMAINS=localhost
EOF

echo "==> Generating APP_KEY..."
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
