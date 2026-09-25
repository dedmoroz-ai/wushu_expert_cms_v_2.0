# Регламент деплоя Wushu Expert

## Главное правило
Код попадает на сервер **только через git** и пересборку образа.
`docker cp` и WinSCP в контейнер — только для аварийных хотфиксов, и такая правка
**в тот же день** коммитится в git. Иначе следующая пересборка её удалит.

## Где что живёт
| Что | Где | Что уничтожит |
|---|---|---|
| Код приложения | Образ + слой контейнера `wushu_app` | `up --build`, `down`, `rm`, пересоздание |
| База | Том `db_data` | **`docker compose down -v`** (запрещено) |
| Загрузки | `./storage/app/public` (хост) | ничего |
| `.env`, `docker-compose.yml`, `Caddyfile` | `/var/www/wushu` (хост) | ничего |

Безопасно всегда: `docker restart wushu_app`, `php artisan optimize:clear`.

## Скрипты (`scripts/deploy/`)
| Скрипт | Этап | Меняет сервер? |
|---|---|---|
| `snapshot.sh` | Снимок: образ, база, код из контейнера, конфиги | Нет (только создаёт образ-тег и файлы в `~/wushu_snapshots`) |
| `compare.sh <снимок>` | Что в контейнере отличается от хоста | Нет |
| `deploy.sh <ветка>` | Деплой из git, точка отката, миграции | Да |
| `rollback.sh <бэкап> [--with-db]` | Откат образа и git, по желанию базы | Да |

`deploy.sh` откажется работать, если есть активный турнир (`status_code = 1`)
или на хосте есть незакоммиченные изменения.

## Первый переход на деплой через git (однократно, после турнира)
1. `bash scripts/deploy/snapshot.sh`, скачать папку снимка через WinSCP.
2. `bash scripts/deploy/compare.sh ~/wushu_snapshots/<дата>` и сохранить вывод.
3. Локально: от `202c36c0` создать ветку `server-snapshot`, положить в неё код
   из `container_code.tar.gz` и `Dockerfile`/`docker-compose.yml`/`Caddyfile` с хоста.
   Мусор (`*.bak`, `*.backup`, `welcome.blade1.php`, `auto_backup.sql`) не коммитить.
   Выполнить `git config core.fileMode false`.
4. Слить `server-snapshot` с локальной веткой A/B и разрешить конфликты
   (`SuperJudgePad`, `CompetitionPdfController`, `User`/`UserResource`, `routes/web.php`,
   `composer.json`/`lock`).
5. Репетиция: развернуть `db.sql.gz` в локальную БД, выполнить `migrate`, прогнать
   тесты, открыть пульты, PDF турниров 2 и 3, аналитику, QR, публичные результаты.
6. Запушить `release/<дата>`, на сервере выполнить `bash scripts/deploy/deploy.sh release/<дата>`.

## .dockerignore
В образ не попадают `.git`, дампы, `*.bak`, `vendor` (ставится в Dockerfile), `_server`.
`.env` **пока попадает**: часть переменных приходит только из него. Исключать его
можно лишь после переноса переменных в `docker-compose.yml` (`env_file: .env`).
