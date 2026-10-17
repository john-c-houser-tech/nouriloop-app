#!/bin/sh
# Starts the web server or the scheduler. Both wait for the database; only the
# web container (RUN_MIGRATIONS=1) applies migrations, so the two never race.
set -e

cd /app

if [ -z "${DATABASE_URL:-}" ] || [ -z "${APP_SECRET:-}" ]; then
    echo "DATABASE_URL and APP_SECRET must be set (run scripts/selfhost-init.sh; see docs/self-hosting.md)." >&2
    exit 1
fi

tries=0
until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 60 ]; then
        echo "The database didn't answer after 2 minutes. Check 'docker compose logs database'." >&2
        php bin/console dbal:run-sql "SELECT 1" || true
        exit 1
    fi
    sleep 2
done

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

php bin/console cache:warmup --no-interaction >/dev/null

exec "$@"
