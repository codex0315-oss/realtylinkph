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

# Always. `migrate` only applies migrations that haven't run yet, so this is a
# no-op on a routine deploy and exactly what's needed after a schema change.
# It used to be opt-in behind RUN_MIGRATIONS, which meant every new migration
# required remembering to flip a flag — and forgetting produced 500s with
# "column does not exist" until someone worked out why. Set
# SKIP_MIGRATIONS=true to opt out for a specific deploy.
if [ "${SKIP_MIGRATIONS}" != "true" ]; then
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

# One-off backfill for photos uploaded before resize-on-upload existed:
# writes card thumbnails and shrinks oversized originals. Idempotent, but it
# re-scans every photo on each boot, so remove the flag once it has run.
if [ "${RUN_PHOTO_OPTIMIZE}" = "true" ]; then
    echo "==> Optimising existing listing photos"
    php artisan photos:optimize || true
fi

# One-off: move applicant documents from the public bucket to the private one
# after DOCUMENT_DISK=s3-private is set. Idempotent; remove the flag after.
if [ "${RUN_DOCS_MOVE}" = "true" ]; then
    echo "==> Moving applicant documents to the private disk"
    php artisan documents:move-private || true
fi

# ── Keep-alive ───────────────────────────────────────────────────────────────
# Render spins a free web service down after 15 minutes without *inbound*
# traffic, and waking it takes 30–60 s — long enough that the home page's
# server render on Vercel gives up (10 s) and shows a 504. The worker and
# scheduler loops above don't count as traffic. Requesting our own public URL
# does, because it goes through Render's load balancer like any visitor.
# RENDER_EXTERNAL_HOSTNAME is set by Render on every service. /up is
# Laravel's health route: it boots the framework but touches no database, so
# it doesn't keep Neon's compute awake (that would burn its free quota).
# KEEP_ALIVE=false disables it, e.g. to let the service sleep after the demo.
if [ -n "${RENDER_EXTERNAL_HOSTNAME}" ] && [ "${KEEP_ALIVE}" != "false" ]; then
    echo "==> Keep-alive pinging https://${RENDER_EXTERNAL_HOSTNAME}/up every 10 minutes"
    while true; do
        sleep 600
        curl -fsS -o /dev/null --max-time 30 "https://${RENDER_EXTERNAL_HOSTNAME}/up" || true
    done &
fi

# Seeds demo accounts and listings. Same opt-in treatment, and it should be
# removed immediately after — re-running it duplicates data.
if [ "${RUN_SEEDERS}" = "true" ]; then
    echo "==> Seeding database"
    php artisan db:seed --force
fi

exec "$@"
