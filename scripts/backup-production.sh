#!/usr/bin/env sh
set -eu

project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
compose_file=${COMPOSE_FILE:-"$project_directory/compose.production.yml"}
environment_file=${ENV_FILE:-"$project_directory/.env.production"}
backup_root=${BACKUP_ROOT:-"$project_directory/backups"}
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_directory="$backup_root/$timestamp"

if [ ! -f "$environment_file" ]; then
    echo "Missing production environment file: $environment_file" >&2
    exit 1
fi

set -a
. "$environment_file"
set +a

mkdir -p "$backup_directory"
chmod 700 "$backup_root" "$backup_directory"

docker compose --env-file "$environment_file" -f "$compose_file" exec -T postgres \
    pg_dump --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --format=custom \
    > "$backup_directory/database.dump"

docker compose --env-file "$environment_file" -f "$compose_file" exec -T app \
    tar -C storage/app/private -czf - reports \
    > "$backup_directory/reports.tar.gz"

git -C "$project_directory" rev-parse HEAD > "$backup_directory/commit.txt"
sha256sum "$backup_directory/database.dump" "$backup_directory/reports.tar.gz" \
    > "$backup_directory/SHA256SUMS"

echo "Production backup created at $backup_directory"
