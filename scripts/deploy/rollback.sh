#!/usr/bin/env bash
# Откат деплоя: возвращает образ app и (по желанию) базу из бэкапа deploy.sh.
# Запуск:  bash scripts/deploy/rollback.sh <папка_бэкапа> [--with-db]
set -euo pipefail

BK=${1:?Укажите папку бэкапа из deploy.sh, например ~/wushu_deploy_backups/2026-10-01_2100}
WITH_DB=${2:-}
APP=${APP_CONTAINER:-wushu_app}
DB=${DB_CONTAINER:-wushu_db}
ROOT=${ROOT:-/var/www/wushu}
cd "$ROOT"

IMAGE=$(cat "$BK/image_name.txt")
echo "==> Возвращаю образ wushu_app:rollback как $IMAGE"
docker tag "wushu_app:rollback" "$IMAGE"
docker compose up -d --no-deps --no-build --force-recreate app

echo "==> Возвращаю git на $(cat "$BK/git_head.txt")"
git checkout "$(cat "$BK/git_head.txt")"

if [ "$WITH_DB" = "--with-db" ]; then
  echo "==> ВНИМАНИЕ: восстанавливаю базу из $BK/db.sql.gz (данные после деплоя пропадут)"
  read -r -p "Введите YES для продолжения: " ok
  [ "$ok" = "YES" ] || { echo "Отменено"; exit 1; }
  docker exec "$DB" sh -c 'psql -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE \"$POSTGRES_DB\" WITH (FORCE)" -c "CREATE DATABASE \"$POSTGRES_DB\""'
  gunzip -c "$BK/db.sql.gz" | docker exec -i "$DB" sh -c 'psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
fi

docker exec "$APP" php artisan optimize:clear
echo "Откат выполнен."
