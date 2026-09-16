#!/bin/sh
#
# Runs before the container's main process (Apache, or an artisan command on
# the worker/scheduler services).
set -e

# Render assigns the port at runtime and expects the service to bind to it.
# Apache's baked-in default is 80, so rewrite both places it appears.
: "${PORT:=80}"
sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" \
    /etc/apache2/sites-available/000-default.conf

# Caching has to happen here, not at build time: every value comes from
# Render's environment, which doesn't exist while the image is being built.
# Baking a cache during build would freeze in the *absence* of those values.
php artisan config:cache
php artisan route:cache

# Only matters when UPLOAD_DISK is left on the local disk. Harmless on S3/R2,
# and tolerated if it fails so a missing symlink can't take the service down.
php artisan storage:link --force >/dev/null 2>&1 || true

# Opt-in, so an ordinary redeploy never silently migrates the database.
# Set RUN_MIGRATIONS=true for the first deploy, then remove it.
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    echo "==> Running migrations"
    php artisan migrate --force
fi

# ── In-container queue worker and scheduler ──────────────────────────────────
# Render's free tier has no Background Worker or Cron Job, so these run here
# beside Apache. It is not how you'd scale this, but it beats the alternative:
# on QUEUE_CONNECTION=sync a job failure propagates into the HTTP request, so
# one rejected email turns a successful registration into a 500.
#
# --max-time recycles the process hourly (bounds any memory leak) and the loop
# restarts it, so a crashed worker doesn't silently stop processing jobs.
if [ "${RUN_WORKER}" = "true" ]; then
    echo "==> Starting queue worker"
    while true; do
        php artisan queue:work --tries=3 --timeout=90 --max-time=3600 --quiet || true
        sleep 5
    done &
fi

# Replaces the Cron Job that would otherwise run `schedule:run` every minute.
if [ "${RUN_SCHEDULER}" = "true" ]; then
    echo "==> Starting scheduler"
    while true; do
        php artisan schedule:run --quiet || true
        sleep 60
    done &
fi

# Seeds demo accounts and listings. Same opt-in treatment, and it should be
# removed immediately after — re-running it duplicates data.
if [ "${RUN_SEEDERS}" = "true" ]; then
    echo "==> Seeding database"
    php artisan db:seed --force
fi

exec "$@"
