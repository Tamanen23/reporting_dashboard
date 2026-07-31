#!/usr/bin/env sh
set -eu

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 /absolute/path/to/backup-directory" >&2
    exit 1
fi

backup_directory=$1
project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
compose_file=${COMPOSE_FILE:-"$project_directory/compose.production.yml"}
environment_file=${ENV_FILE:-"$project_directory/.env.production"}

if [ ! -f "$environment_file" ]; then
    echo "Missing production environment file: $environment_file" >&2
    exit 1
fi

for required_file in database.dump reports.tar.gz SHA256SUMS; do
    if [ ! -f "$backup_directory/$required_file" ]; then
        echo "Backup is missing $required_file" >&2
        exit 1
    fi
done

(cd "$backup_directory" && sha256sum -c SHA256SUMS)

printf 'This will replace production database state and merge report files. Type RESTORE to continue: '
read -r confirmation
if [ "$confirmation" != "RESTORE" ]; then
    echo "Restore cancelled."
    exit 1
fi

set -a
. "$environment_file"
set +a

docker compose --env-file "$environment_file" -f "$compose_file" exec -T app \
    php artisan down --retry=60

docker compose --env-file "$environment_file" -f "$compose_file" exec -T postgres \
    pg_restore --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    --clean --if-exists --no-owner < "$backup_directory/database.dump"

docker compose --env-file "$environment_file" -f "$compose_file" exec -T app \
    tar -C storage/app/private -xzf - < "$backup_directory/reports.tar.gz"

docker compose --env-file "$environment_file" -f "$compose_file" exec -T app \
    php artisan optimize:clear
docker compose --env-file "$environment_file" -f "$compose_file" exec -T app \
    php artisan up

echo "Production restore completed."
