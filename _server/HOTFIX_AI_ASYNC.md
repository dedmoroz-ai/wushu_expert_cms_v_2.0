# Хотфикс: фоновая генерация AI-отчёта (504 → статус в UI)

Дата: 05.10.2026. Проблема: кнопка «AI-аналитика» делала синхронный POST, LLM думал
до ~3 минут, nginx в контейнере (`serversideup/php:8.4-fpm-nginx`) обрывал ответ по
`fastcgi_read_timeout` (рендерился в 99с) — пользователь получал **504**, хотя отчёт
потом дописывался. Решение (утверждённый Вариант 1): генерация уходит в фоновый
CLI-процесс, в UI — статус с обновлением по `wire:poll.5s`.

## Что меняется

| Файл | Куда | Зачем |
|---|---|---|
| `app/Support/AiReportRunner.php` (новый) | контейнер + хост | Фоновый запуск `analytics:generate {id}` (`nohup … &`, вывод в `storage/logs/ai-report.log`), статус в cache (`ai-report:state:{id}`: running/done/error, url, TTL 1 ч; running старше 10 мин = «прервано») |
| `app/Console/Commands/GenerateAnalyticsReport.php` | контейнер + хост | Пишет итог в cache: `markDone`/`markError` |
| `app/Filament/Pages/ScoresSummary.php` | контейнер + хост | Кнопка быстро запускает раннер + уведомление; `getAiReportState()` для UI. Заодно исправлен импорт `Filament\Support\Notifications\Notification` → `Filament\Notifications\Notification` (латентный баг, маскировался под 504) |
| `resources/views/filament/pages/scores-summary.blade.php` | контейнер + хост | Блок статуса (⏳/✅/⚠️), `wire:poll.5s` пока идёт генерация, кнопка заблокирована |
| `Dockerfile`, `docker-compose.yml` | хост | `PHP_MAX_EXECUTION_TIME=300` — шаблон nginx рендерит из него `fastcgi_read_timeout` (страховка для прочих долгих запросов) |
| `tests/Feature/AiReportRunnerTest.php`, `docs/ANALYTICS.md` | хост | Тесты (6 шт.) и документация |

## Процедура (как выполнено 05.10.2026)

```bash
# 0. Бэкап текущих файлов (контейнер + хост)
mkdir -p ~/hotfix_2026-10-05/backup/ai-async
docker cp wushu_app:/var/www/html/app/Filament/Pages/ScoresSummary.php ~/hotfix_2026-10-05/backup/ai-async/
docker cp wushu_app:/var/www/html/app/Console/Commands/GenerateAnalyticsReport.php ~/hotfix_2026-10-05/backup/ai-async/
docker cp wushu_app:/var/www/html/resources/views/filament/pages/scores-summary.blade.php ~/hotfix_2026-10-05/backup/ai-async/

# 1. Залить файлы (tar с локали → /var/www/wushu/), затем в контейнер:
docker cp app/Support/AiReportRunner.php wushu_app:/var/www/html/app/Support/
docker cp app/Console/Commands/GenerateAnalyticsReport.php wushu_app:/var/www/html/app/Console/Commands/
docker cp app/Filament/Pages/ScoresSummary.php wushu_app:/var/www/html/app/Filament/Pages/
docker cp resources/views/filament/pages/scores-summary.blade.php wushu_app:/var/www/html/resources/views/filament/pages/

# 2. Autoload (новый класс) + кэши. vendor принадлежит root → dump-autoload от root:
docker exec -u root -w /var/www/html wushu_app composer dump-autoload -o
docker exec -u www-data -w /var/www/html wushu_app php artisan config:clear
docker exec -u www-data -w /var/www/html wushu_app php artisan route:clear
docker exec -u www-data -w /var/www/html wushu_app php artisan view:clear

# 3. Таймаут nginx: ПРАВИТЬ location-блок (server-opts.d не поможет —
#    location-level перекрывает server-level). Шаблон рендерится envsubst-ом
#    из $PHP_MAX_EXECUTION_TIME (=99 в образе). Меняем и шаблон, и отрендеренный
#    conf (чтобы держалось и после рестарта контейнера):
docker exec -u root wushu_app sed -i 's/fastcgi_read_timeout \$PHP_MAX_EXECUTION_TIME;/fastcgi_read_timeout 300s;/' /etc/nginx/site-opts.d/http.conf.template /etc/nginx/site-opts.d/https.conf.template
docker exec -u root wushu_app sed -i 's/fastcgi_read_timeout 99;/fastcgi_read_timeout 300s;/' /etc/nginx/site-opts.d/http.conf /etc/nginx/site-opts.d/https.conf
docker exec -u root wushu_app nginx -t && docker exec -u root wushu_app nginx -s reload

# 4. Smoke (реальный запуск, LLM-запрос ~1–2 ₽):
docker cp /tmp/smoke_ai_async.php wushu_app:/tmp/
docker exec -u www-data -w /var/www/html wushu_app php /tmp/smoke_ai_async.php 4 start   # должен вернуться < 1 с
docker exec -u www-data -w /var/www/html wushu_app php /tmp/smoke_ai_async.php 4 state   # running
sleep 90
docker exec -u www-data -w /var/www/html wushu_app php /tmp/smoke_ai_async.php 4 state   # done + url
docker exec -u www-data -w /var/www/html wushu_app tail -5 /var/www/html/storage/logs/ai-report.log
```

Проверка UI: «Сводка оценок» → соревнование → **AI-аналитика** → ответ мгновенный
(без 504), под кнопками ⏳ «генерируется…», через 1–3 мин — ✅ со ссылкой; отчёт
в «Аналитика». Кнопка во время генерации заблокирована, повторный клик —
предупреждение «Генерация уже идёт».

## Откат

1. `docker cp` из `~/hotfix_2026-10-05/backup/ai-async/` обратно в контейнер +
   копии в `/var/www/wushu/`;
2. `docker exec -u root -w /var/www/html wushu_app composer dump-autoload -o` +
   `config:clear`/`route:clear`/`view:clear`;
3. таймаут nginx: вернуть `99`/`$PHP_MAX_EXECUTION_TIME` в `/etc/nginx/site-opts.d/*`
   и `nginx -s reload` (или просто `docker restart wushu_app` — шаблон отрендерится
   заново, но учтите: вместе с этим пропадут и docker-cp-хотфиксы PHP!);
4. ядерный откат образа: `wushu_app:rollback-ai-2026-10-05_0611` (снимок ДО
   текущего хотфикса — в нём уже есть первый AI-хотфикс кнопки).

## Примечания

- Кэш статуса — `CACHE_STORE=database` (таблица `cache`), TTL 1 ч; ключ
  `ai-report:state:{id}`. Очередь не нужна: `nohup php artisan … &` отвязывает
  процесс от PHP-FPM (важно: `Process::start()` непригоден — `Process::__destruct()`
  убивает процесс при коне запроса).
- Если контейнер `wushu_app` будет пересоздан (а не перезапущен) — docker-cp-правки
  пропадают. Правки `Dockerfile`/`docker-compose.yml` в `/var/www/wushu/` защищают
  от этого при следующей сборке: `docker compose build app && docker compose up -d`.
- Лог фонового процесса: `/var/www/html/storage/logs/ai-report.log` (в контейнере).
