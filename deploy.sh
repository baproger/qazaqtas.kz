#!/usr/bin/env bash
#
# Деплой BAIA ERP на сервер: подтягивает master и обновляет приложение.
# Первый запуск: скопируйте .env.example → .env, заполните и запустите с --first.
#
# Использование:
#   ./deploy.sh            # обычное обновление (git pull + миграции + сборка)
#   ./deploy.sh --first    # первичная установка (key:generate + storage:link)
#
set -euo pipefail
cd "$(dirname "$0")"

FIRST=false
[[ "${1:-}" == "--first" ]] && FIRST=true

echo "→ Включаю режим обслуживания"
php artisan down || true
trap 'php artisan up || true' EXIT

echo "→ git pull origin master"
git pull origin master

echo "→ composer install (prod)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "→ npm ci && build"
npm ci
npm run build

if [ "$FIRST" = true ]; then
  echo "→ Первичная настройка"
  php artisan key:generate --force
  php artisan storage:link || true
fi

echo "→ Миграции"
php artisan migrate --force

echo "→ Кэш конфигов/роутов/вью"
php artisan optimize:clear
php artisan optimize

echo "✓ Готово"
