# Установка на сервер 25.09.2026: хотфикс 8.4/8.8 + новый диплом

Инструкция для встроенного терминала WinSCP: **каждая команда — одна строка**.
Все пути полные, `cd` и переменные не используются. Выполнять сверху вниз,
после каждой команды сверять результат с колонкой «Ожидается».

Заменяется **2 файла** в контейнере `wushu_app`, добавляется 1 (бэкап шаблона).
Миграций нет, база не меняется.

| Файл в проекте | md5 сейчас (сервер) | md5 после |
|---|---|---|
| `app/Filament/Pages/SuperJudgePad.php` | `affdd4a3e74b42291aef001797e121f8` | `943d766cfd077889d6794d2699b8be38` |
| `resources/views/pdf/diplomas_blank.blade.php` | `801f7af8465c7191cae177ea9c4137e1` | `8a02127e3f9efc621c7c1fb556700e4e` |
| `resources/views/pdf/diplomas_blank_old.blade.php` | — (нет) | `801f7af8465c7191cae177ea9c4137e1` |

Запрещено: `docker compose down` (особенно `-v`), `docker compose up --build`,
`docker rm`. Разрешено: `docker restart wushu_app`.

---

## Шаг 0. Папка и загрузка файлов

```
mkdir -p ~/hotfix_2026-09-25/backup && ls -la ~/hotfix_2026-09-25
```
Затем в WinSCP перетащить в `~/hotfix_2026-09-25/` из локальной папки `_server/`
**два файла**: `SuperJudgePad.hotfix.php` и `diplomas_blank.new.blade.php`.

```
md5sum ~/hotfix_2026-09-25/SuperJudgePad.hotfix.php ~/hotfix_2026-09-25/diplomas_blank.new.blade.php
```
Ожидается: `943d766c…` и `8a02127e…`. Если другое — файл испорчен при загрузке
(режим «Текст» в WinSCP). Перезалить в режиме **«Двоичный» (Binary)**.

## Шаг 1. Проверка, что на сервере то, что мы ожидаем

```
docker ps --format '{{.Names}}  {{.Status}}'
```
Ожидается: `wushu_app`, `wushu_db` (и caddy) — `Up`.

```
docker exec wushu_app md5sum /var/www/html/app/Filament/Pages/SuperJudgePad.php /var/www/html/resources/views/pdf/diplomas_blank.blade.php
```
Ожидается: `affdd4a3…` и `801f7af8…`.
**Если хоть один не совпал — СТОП.** Ничего не ставить, прислать мне вывод.

```
md5sum /var/www/wushu/app/Filament/Pages/SuperJudgePad.php /var/www/wushu/resources/views/pdf/diplomas_blank.blade.php
```
Это копия на хосте, только для сведения (результат прислать мне; на установку не влияет).

## Шаг 2. Страховка (бэкап)

1. Снимок контейнера целиком (1–2 минуты):
```
docker commit wushu_app wushu_app:before_hotfix_2026-09-25
```
2. Дамп базы:
```
docker exec wushu_db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > ~/hotfix_2026-09-25/backup/db.sql.gz
```
```
gunzip -c ~/hotfix_2026-09-25/backup/db.sql.gz | tail -3
```
Ожидается строка `-- PostgreSQL database dump complete`.

3. Копии двух заменяемых файлов из контейнера:
```
docker cp wushu_app:/var/www/html/app/Filament/Pages/SuperJudgePad.php ~/hotfix_2026-09-25/backup/SuperJudgePad.php
```
```
docker cp wushu_app:/var/www/html/resources/views/pdf/diplomas_blank.blade.php ~/hotfix_2026-09-25/backup/diplomas_blank.blade.php
```
```
md5sum ~/hotfix_2026-09-25/backup/*.php && ls -la ~/hotfix_2026-09-25/backup
```
Ожидается: `affdd4a3…`, `801f7af8…`, `db.sql.gz` не нулевого размера.

## Шаг 3. Установка в контейнер

1. Бэкап старого шаблона рядом с ним (станет `diplomas_blank_old.blade.php`):
```
docker exec wushu_app cp -p /var/www/html/resources/views/pdf/diplomas_blank.blade.php /var/www/html/resources/views/pdf/diplomas_blank_old.blade.php
```
2. Новый шаблон диплома:
```
docker cp ~/hotfix_2026-09-25/diplomas_blank.new.blade.php wushu_app:/var/www/html/resources/views/pdf/diplomas_blank.blade.php
```
3. Хотфикс пульта старшего судьи:
```
docker cp ~/hotfix_2026-09-25/SuperJudgePad.hotfix.php wushu_app:/var/www/html/app/Filament/Pages/SuperJudgePad.php
```
4. Сверка всех трёх файлов:
```
docker exec wushu_app md5sum /var/www/html/app/Filament/Pages/SuperJudgePad.php /var/www/html/resources/views/pdf/diplomas_blank.blade.php /var/www/html/resources/views/pdf/diplomas_blank_old.blade.php
```
Ожидается по порядку: `943d766c…`, `8a02127e…`, `801f7af8…`.

5. Права (файл должен читаться веб-сервером):
```
docker exec wushu_app chmod 644 /var/www/html/app/Filament/Pages/SuperJudgePad.php /var/www/html/resources/views/pdf/diplomas_blank.blade.php /var/www/html/resources/views/pdf/diplomas_blank_old.blade.php
```
6. Синтаксис:
```
docker exec wushu_app php -l /var/www/html/app/Filament/Pages/SuperJudgePad.php
```
Ожидается: `No syntax errors detected`.

7. Сброс кэша шаблонов и настроек:
```
docker exec wushu_app php artisan optimize:clear
```

## Шаг 4. Проверка

1. **8.4 — бригада турнира №4.** Должно вывести `3` (судьи бригады, кроме Копыловой):
```
docker exec wushu_app php artisan tinker --execute="echo App\Models\Competition::find(4)->judges()->where('users.id','!=',3)->whereIn('users.role',['judge','head_judge'])->where('users.is_active_judge',true)->count();"
```
2. **Пульт старшего судьи** в браузере открывается без ошибки 500, видно «ОЦЕНОК: … / 4».
   Если поведение не изменилось (старый код в OPcache):
```
docker restart wushu_app
```
   (`restart` безопасен: изменения контейнера сохраняются.)
3. **Диплом.** В админке: турнир → участники → кнопка «Дипломы» у любой категории
   турнира 2 или 3 (где есть оценки). В PDF — только «награждается», ФИО, место,
   дисциплина, возраст; без названия турнира и подписей. Один лист — на обычную
   бумагу, приложить к бланку на просвет.
4. **8.8 — переход только вперёд** проверяется после жеребьёвки (шаг 7).

## Шаг 5. Закрепить результат

Снимок контейнера уже с исправлениями (точка возврата на случай пересоздания):
```
docker commit wushu_app wushu_app:after_hotfix_2026-09-25
```
```
docker images wushu_app
```
Ожидается: теги `before_hotfix_2026-09-25` и `after_hotfix_2026-09-25`.

## Шаг 6 (желательно). Копия на хосте (защита от пересборки образа)

Образ собирается из `/var/www/wushu`. Чтобы случайный `up --build` не вернул
старые файлы, кладём новые и туда (старые хост-файлы — в бэкап).
Этот шаг заменяет `git apply` из `HOTFIX_8.4_8.8.md` — патч применять **не нужно**.
После него `git status` на хосте покажет изменённые файлы — так и должно быть,
после турнира они попадут в git (см. `docs/POST_TOURNAMENT_PLAN.md`).
```
cp -p /var/www/wushu/app/Filament/Pages/SuperJudgePad.php ~/hotfix_2026-09-25/backup/host_SuperJudgePad.php && cp -p /var/www/wushu/resources/views/pdf/diplomas_blank.blade.php ~/hotfix_2026-09-25/backup/host_diplomas_blank.blade.php
```
```
cp ~/hotfix_2026-09-25/backup/host_diplomas_blank.blade.php /var/www/wushu/resources/views/pdf/diplomas_blank_old.blade.php && cp ~/hotfix_2026-09-25/diplomas_blank.new.blade.php /var/www/wushu/resources/views/pdf/diplomas_blank.blade.php && cp ~/hotfix_2026-09-25/SuperJudgePad.hotfix.php /var/www/wushu/app/Filament/Pages/SuperJudgePad.php
```
```
md5sum /var/www/wushu/app/Filament/Pages/SuperJudgePad.php /var/www/wushu/resources/views/pdf/diplomas_blank.blade.php
```
Ожидается: `943d766c…`, `8a02127e…`. На работу сайта этот шаг не влияет.
(Если `cp` пишет `Permission denied` — пропустить шаг и сообщить мне.)

## Шаг 7. До начала турнира

1. Провести **жеребьёвку** турнира №4 (без неё 8.8 работает по-старому).
2. Тестовый прогон с 4 судьями: утвердить участника №5 → переход на №6,
   а не на пропущенного; на последнем номере — предупреждение о пропущенных.

---

## Откат (каждый фикс отдельно, ~1 минута)

**Только диплом** (вернуть старый шаблон):
```
docker cp ~/hotfix_2026-09-25/backup/diplomas_blank.blade.php wushu_app:/var/www/html/resources/views/pdf/diplomas_blank.blade.php && docker exec wushu_app php artisan view:clear
```
**Только пульт** (вернуть SuperJudgePad):
```
docker cp ~/hotfix_2026-09-25/backup/SuperJudgePad.php wushu_app:/var/www/html/app/Filament/Pages/SuperJudgePad.php && docker exec wushu_app php artisan optimize:clear && docker restart wushu_app
```
Проверка после отката:
```
docker exec wushu_app md5sum /var/www/html/app/Filament/Pages/SuperJudgePad.php /var/www/html/resources/views/pdf/diplomas_blank.blade.php
```
Ожидается: `affdd4a3…`, `801f7af8…`.

Если был выполнен шаг 6 — вернуть и хост-файлы:
```
cp ~/hotfix_2026-09-25/backup/host_SuperJudgePad.php /var/www/wushu/app/Filament/Pages/SuperJudgePad.php && cp ~/hotfix_2026-09-25/backup/host_diplomas_blank.blade.php /var/www/wushu/resources/views/pdf/diplomas_blank.blade.php
```

**Аварийно (всё сломалось):** база не трогалась, поэтому восстанавливать её не
нужно. Код откатывается файлами выше. Снимок `wushu_app:before_hotfix_2026-09-25`
— крайний случай, запускать его только вместе со мной.
