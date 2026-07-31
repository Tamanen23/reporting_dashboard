#!/usr/bin/env sh
set -eu

project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
compose_file=${COMPOSE_FILE:-"$project_directory/compose.production.yml"}
environment_file=${ENV_FILE:-"$project_directory/.env.production"}

cd "$project_directory"

if [ ! -f "$environment_file" ]; then
    echo "Missing production environment file: $environment_file" >&2
    exit 1
fi

set -a
. "$environment_file"
set +a

postgres_container=$(docker compose --env-file "$environment_file" -f "$compose_file" ps -q postgres)
if [ -n "$postgres_container" ] \
    && [ "$(docker inspect -f '{{.State.Running}}' "$postgres_container")" = "true" ]; then
    "$project_directory/scripts/backup-production.sh"
else
    echo "No running production database was found; skipping the pre-deployment backup."
fi

docker compose --env-file "$environment_file" -f "$compose_file" build --pull
docker compose --env-file "$environment_file" -f "$compose_file" up -d postgres redis
docker compose --env-file "$environment_file" -f "$compose_file" run --rm app \
    php artisan migrate --force
docker compose --env-file "$environment_file" -f "$compose_file" run --rm app \
    php artisan reports:sync
docker compose --env-file "$environment_file" -f "$compose_file" up -d --remove-orphans
docker compose --env-file "$environment_file" -f "$compose_file" exec -T app \
    php artisan optimize
docker compose --env-file "$environment_file" -f "$compose_file" exec -T app \
    php artisan queue:restart

echo "Waiting for the application health endpoint..."
attempt=0
until curl --fail --silent --show-error \
    "http://${APP_BIND_ADDRESS:-127.0.0.1}:${APP_PORT:-8088}/up" >/dev/null; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Deployment health check failed." >&2
        exit 1
    fi
    sleep 2
done

docker compose --env-file "$environment_file" -f "$compose_file" ps
echo "Production deployment completed successfully."
