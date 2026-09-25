#!/usr/bin/env bash
# Этап 2. Что в контейнере отличается от хоста (/var/www/wushu). НИЧЕГО НЕ МЕНЯЕТ.
# Запуск:  bash scripts/deploy/compare.sh <папка_снимка_из_snapshot.sh>
set -euo pipefail

SNAP=${1:?Укажите папку снимка, например ~/wushu_snapshots/2026-09-26_2100}
ROOT=${ROOT:-/var/www/wushu}
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

tar xzf "$SNAP/container_code.tar.gz" -C "$TMP"

EXCL=(--exclude=vendor --exclude=storage --exclude=.git --exclude=node_modules
      --exclude=bootstrap/cache)

echo "=== Файлы, которые ОТЛИЧАЮТСЯ (контейнер новее хоста?) ==="
diff -rq "${EXCL[@]}" "$TMP" "$ROOT" | grep ' differ$' \
  | sed -e "s#$TMP/##" -e "s# and $ROOT/.*##" -e 's#^Files ##' || echo "(нет)"

echo
echo "=== Файлы ТОЛЬКО В КОНТЕЙНЕРЕ (пропадут при пересборке!) ==="
diff -rq "${EXCL[@]}" "$TMP" "$ROOT" | grep "^Only in $TMP" \
  | sed -e "s#^Only in $TMP/\?##" -e 's#: #/#' || echo "(нет)"

echo
echo "Правило: код приложения — брать из контейнера; Dockerfile, docker-compose.yml,"
echo "Caddyfile, .env — брать с хоста."
