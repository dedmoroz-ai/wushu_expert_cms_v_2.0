#!/bin/bash
# Хотфикс 05.10.2026: фоновая генерация AI-отчёта (см. _server/HOTFIX_AI_ASYNC.md).
set -ex

cd ~/hotfix_2026-10-05
mkdir -p backup/ai-async

echo "=== 0. Бэкап текущих файлов из контейнера ==="
docker cp wushu_app:/var/www/html/app/Filament/Pages/ScoresSummary.php backup/ai-async/ScoresSummary.php
docker cp wushu_app:/var/www/html/app/Console/Commands/GenerateAnalyticsReport.php backup/ai-async/GenerateAnalyticsReport.php
docker cp wushu_app:/var/www/html/resources/views/filament/pages/scores-summary.blade.php backup/ai-async/scores-summary.blade.php

echo "=== 1. Распаковка файлов на хост (/var/www/wushu) ==="
tar xzf ai_async_hotfix.tar.gz -C /var/www/wushu

echo "=== 2. Копирование runtime-файлов в контейнер ==="
docker cp /var/www/wushu/app/Support/AiReportRunner.php wushu_app:/var/www/html/app/Support/
docker cp /var/www/wushu/app/Console/Commands/GenerateAnalyticsReport.php wushu_app:/var/www/html/app/Console/Commands/
docker cp /var/www/wushu/app/Filament/Pages/ScoresSummary.php wushu_app:/var/www/html/app/Filament/Pages/
docker cp /var/www/wushu/resources/views/filament/pages/scores-summary.blade.php wushu_app:/var/www/html/resources/views/filament/pages/

echo "=== 3. Autoload + кэши ==="
docker exec -u root -w /var/www/html wushu_app composer dump-autoload -o
docker exec -u www-data -w /var/www/html wushu_app php artisan config:clear
docker exec -u www-data -w /var/www/html wushu_app php artisan route:clear
docker exec -u www-data -w /var/www/html wushu_app php artisan view:clear

echo "=== 4. Таймаут nginx: 99s -> 300s (шаблон + отрендеренный conf) ==="
docker exec -u root wushu_app sed -i 's/fastcgi_read_timeout $PHP_MAX_EXECUTION_TIME;/fastcgi_read_timeout 300s;/' /etc/nginx/site-opts.d/http.conf.template /etc/nginx/site-opts.d/https.conf.template
docker exec -u root wushu_app sed -i 's/fastcgi_read_timeout 99;/fastcgi_read_timeout 300s;/' /etc/nginx/site-opts.d/http.conf /etc/nginx/site-opts.d/https.conf
docker exec -u root wushu_app grep -n fastcgi_read_timeout /etc/nginx/site-opts.d/http.conf /etc/nginx/site-opts.d/https.conf /etc/nginx/site-opts.d/http.conf.template /etc/nginx/site-opts.d/https.conf.template
docker exec -u root wushu_app nginx -t
docker exec -u root wushu_app nginx -s reload

echo "=== 5. Smoke: фоновый запуск по турниру 4 ==="
docker cp /var/www/wushu/_server/smoke_ai_async.php wushu_app:/tmp/
docker exec -u www-data -w /var/www/html wushu_app php /tmp/smoke_ai_async.php 4 start
echo "=== STATE RIGHT AFTER START ==="
docker exec -u www-data -w /var/www/html wushu_app php /tmp/smoke_ai_async.php 4 state

echo "=== DEPLOY DONE ==="