#!/usr/bin/env bash
#
# Бэкап QAZAQ TAS: база и загруженные файлы.
#
# Фотографии товаров, категорий, объектов и 3D-модели лежат только в
# storage/app/public и в git не хранятся — без этого архива их не вернуть.
#
# Запуск:  ./scripts/backup.sh [папка-назначения]
# По расписанию:  0 3 * * *  /var/www/qazaqtas/scripts/backup.sh /var/backups/qazaqtas

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${1:-$ROOT/storage/backups}"
STAMP="$(date +%Y-%m-%d_%H%M)"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"

mkdir -p "$DEST"

# Читаем доступы из .env, не полагаясь на окружение оболочки.
env_value() {
    grep -E "^$1=" "$ROOT/.env" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'"
}

DB_CONNECTION="$(env_value DB_CONNECTION)"
DB_DATABASE="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"
DB_PASSWORD="$(env_value DB_PASSWORD)"
DB_HOST="$(env_value DB_HOST)"
DB_PORT="$(env_value DB_PORT)"

echo "→ база"
if [ "$DB_CONNECTION" = "sqlite" ]; then
    cp "$ROOT/database/database.sqlite" "$DEST/db_$STAMP.sqlite"
else
    MYSQL_PWD="$DB_PASSWORD" mysqldump \
        --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" \
        --user="$DB_USERNAME" \
        --single-transaction --quick --default-character-set=utf8mb4 \
        "$DB_DATABASE" | gzip > "$DEST/db_$STAMP.sql.gz"
fi

echo "→ файлы"
tar -czf "$DEST/files_$STAMP.tar.gz" -C "$ROOT/storage/app" public

echo "→ чистка старше $KEEP_DAYS дней"
find "$DEST" -maxdepth 1 -type f -name 'db_*' -mtime "+$KEEP_DAYS" -delete
find "$DEST" -maxdepth 1 -type f -name 'files_*' -mtime "+$KEEP_DAYS" -delete

echo "Готово: $DEST"
ls -lh "$DEST" | tail -4
