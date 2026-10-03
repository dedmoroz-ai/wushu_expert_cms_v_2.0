#!/usr/bin/env bash
# Этап 5. Деплой из git с точкой отката. Запускать ТОЛЬКО без идущего турнира.
# Запуск:  bash scripts/deploy/deploy.sh <ветка_или_тег>
set -euo pipefail

REF=${1:?Укажите ветку или тег, например release/2026-10}
APP=${APP_CONTAINER:-wushu_app}
DB=${DB_CONTAINER:-wushu_db}
ROOT=${ROOT:-/var/www/wushu}
D=$(date +%F_%H%M)
BK=$HOME/wushu_deploy_backups/$D
mkdir -p "$BK"
cd "$ROOT"

# --- Предохранители ---------------------------------------------------------
ACTIVE=$(docker exec "$DB" sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "select count(*) from competitions where status_code = 1"')
if [ "$ACTIVE" != "0" ]; then
  echo "СТОП: есть активный турнир (status_code = 1). Деплой запрещён."; exit 1
fi
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "СТОП: на хосте есть незакоммиченные изменения. Сначала зафиксируйте их (этап 3)."
  git status --short --untracked-files=no; exit 1
fi

# --- Точка отката -----------------------------------------------------------
IMAGE=$(docker inspect -f '{{.Config.Image}}' "$APP")
echo "==> Образ сервиса app: $IMAGE"
echo "$IMAGE" > "$BK/image_name.txt"
git rev-parse HEAD > "$BK/git_head.txt"
docker commit "$APP" "wushu_app:rollback" >/dev/null
docker tag "wushu_app:rollback" "wushu_app:rollback-$D"
docker exec "$DB" sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > "$BK/db.sql.gz"
gzip -t "$BK/db.sql.gz"
echo "==> Бэкап: $BK  (образ wushu_app:rollback-$D)"

# --- Код ---------------------------------------------------------------------
git fetch --all --tags
git checkout "$REF"
git pull --ff-only || true

# --- Сборка: старый контейнер продолжает работать ------------------------------
docker compose build app

# --- Замена ТОЛЬКО app; db и redis не трогаются ----------------------------------
docker compose up -d --no-deps app
sleep 5

docker exec "$APP" php artisan migrate --force
# public/ в образе принадлежит root (COPY), а artisan работает от www-data —
# без chown filament:assets падает с Permission denied (деплой 2026-10-03).
docker exec -u root "$APP" chown -R www-data:www-data /var/www/html/public
docker exec "$APP" php artisan filament:assets
docker exec "$APP" php artisan storage:link || true
docker exec "$APP" php artisan optimize

# --- Дымовая проверка ---------------------------------------------------------
docker exec "$APP" php artisan migrate:status | tail -n 10
URL=$(grep -E '^APP_URL=' .env | cut -d= -f2- || true)
if [ -n "$URL" ]; then
  for p in / /admin/login; do
    printf '%-14s %s\n' "$p" "$(curl -sk -o /dev/null -w '%{http_code}' "$URL$p")"
  done
fi
echo
echo "Готово. Проверьте вручную: пульты, PDF, аналитику, QR, публичные результаты."
echo "Откат: bash scripts/deploy/rollback.sh $BK"
