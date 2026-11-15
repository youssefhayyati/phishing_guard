#!/usr/bin/env bash
set -e

cd /app

# Ensure an .env exists (kept out of the image for secrets hygiene)
if [ ! -f .env ]; then
  cp .env.example .env
fi

# Generate an app key if one isn't set
if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

# Ensure the SQLite database file exists
mkdir -p database
if [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
fi

# Run migrations + seed (seeder is idempotent — uses updateOrCreate)
php artisan migrate --force --seed || php artisan migrate --force

# Cache config/routes for a bit of speed
php artisan config:clear

# Background queue worker — processes campaign deployments (SSH provisioning).
# --timeout matches DeployCampaign::$timeout; retry_after (config) is set higher
# via DB_QUEUE_RETRY_AFTER so a long deploy is never duplicated mid-run.
echo "Starting queue worker…"
php artisan queue:work --sleep=1 --tries=1 --timeout=3600 >> storage/logs/worker.log 2>&1 &

exec "$@"
