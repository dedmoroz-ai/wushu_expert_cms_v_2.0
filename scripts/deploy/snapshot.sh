#!/usr/bin/env bash
# Этап 1. Страховочный снимок боевого сервера. НИЧЕГО НЕ МЕНЯЕТ.
# Запуск на сервере:  bash scripts/deploy/snapshot.sh   (из /var/www/wushu)
set -euo pipefail

APP=${APP_CONTAINER:-wushu_app}
DB=${DB_CONTAINER:-wushu_db}
ROOT=${ROOT:-/var/www/wushu}
D=$(date +%F_%H%M)
OUT=${OUT:-$HOME/wushu_snapshots/$D}
mkdir -p "$OUT"
cd "$ROOT"

echo "==> 1/5 Образ контейнера (все правки docker cp): $APP -> wushu_app:snapshot-$D"
docker commit "$APP" "wushu_app:snapshot-$D" >/dev/null
docker save "wushu_app:snapshot-$D" | gzip > "$OUT/image.tar.gz"

echo "==> 2/5 Дамп базы"
docker exec "$DB" sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > "$OUT/db.sql.gz"
gzip -t "$OUT/db.sql.gz"

echo "==> 3/5 Код, реально работающий в контейнере"
docker exec "$APP" tar czf - -C /var/www/html \
  --exclude=./vendor --exclude=./node_modules --exclude=./storage --exclude=./.git . \
  > "$OUT/container_code.tar.gz"

echo "==> 4/5 Конфиги хоста"
tar czf "$OUT/host_config.tar.gz" --ignore-failed-read \
  .env docker-compose.yml Caddyfile Dockerfile 2>/dev/null || true

echo "==> 5/5 Служебная информация"
{
  echo "date: $D"
  echo "git HEAD: $(git rev-parse HEAD)"
  docker diff "$APP" | grep -v -E ' /(tmp|root|var/www/html/storage)' || true
} > "$OUT/info.txt"
docker exec "$APP" php artisan migrate:status > "$OUT/migrate_status.txt" 2>&1 || true
docker exec "$APP" composer show --no-ansi > "$OUT/composer_show.txt" 2>&1 || true

( cd "$OUT" && sha256sum ./*.gz > SHA256SUMS )
ls -lh "$OUT"
echo
echo "Готово: $OUT"
echo "СКАЧАЙТЕ папку через WinSCP себе на компьютер."
