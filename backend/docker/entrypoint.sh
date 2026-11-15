#!/usr/bin/env bash
set -e

cd /app

# Ensure an .env exists (kept out of the image for secrets hygiene)
if [ ! -f .env ]; then
  cp .env.example .env
fi

# App key resolution. A pinned APP_KEY env var (docker-compose) keeps encrypted
# data decryptable across container recreation. Persist it into .env too, so the
# app has a key on every boot path regardless of how the container is launched.
if [ -n "${APP_KEY:-}" ]; then
  if grep -q '^APP_KEY=' .env; then
    sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env
  else
    echo "APP_KEY=${APP_KEY}" >> .env
  fi
elif ! grep -q '^APP_KEY=base64:' .env; then
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

# Background consumer — persists campaign agent events from RabbitMQ. Retries if
# the broker isn't up yet. (STRATEGY.md section 5.1.)
echo "Starting RabbitMQ events consumer…"
( while true; do
    php artisan phishguard:consume >> storage/logs/consumer.log 2>&1
    echo "[consumer] exited — restarting in 5s" >> storage/logs/consumer.log
    sleep 5
  done ) &

exec "$@"
