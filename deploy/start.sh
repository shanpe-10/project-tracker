#!/bin/sh
set -eu

: "${DB_URL:?Set the Neon connection string in the private Render environment}"
: "${TRACKER_MEDIA_DISK:?Set the private cloud media disk}"
if [ -z "${APP_KEY:-}" ]; then
    : "${APP_KEY_BASE64:?Set the persistent APP_KEY_BASE64 secret}"
    export APP_KEY="base64:${APP_KEY_BASE64}"
fi
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost:10000}}"
port="${PORT:-10000}"
case "$port" in ''|*[!0-9]*) echo 'Invalid PORT' >&2; exit 1;; esac
sed -i "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/\*:10000/*:${port}/" /etc/apache2/sites-available/000-default.conf

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
php artisan config:cache
php artisan migrate --force --no-interaction
php artisan tracker:initialize-source-accounts --no-interaction
php artisan tracker:bootstrap-cloud --no-interaction
php artisan view:cache
# Catch up date-driven statuses after a free-instance sleep, before serving users.
php artisan tracker:project-status-sync --no-interaction
chown -R www-data:www-data storage bootstrap/cache
runuser -u www-data -- php artisan schedule:work --no-interaction &
exec apache2-foreground
