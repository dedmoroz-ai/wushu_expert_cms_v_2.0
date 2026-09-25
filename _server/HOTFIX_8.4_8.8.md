# Хотфикс 8.4 + 8.8 для боевого сервера (SuperJudgePad.php)

| Файл | md5 | Что это |
|---|---|---|
| `SuperJudgePad.server.php` | `affdd4a3e74b42291aef001797e121f8` | Текущая версия на сервере (восстановлена и сверена) |
| `SuperJudgePad.hotfix.php` | `943d766cfd077889d6794d2699b8be38` | Версия с хотфиксом |
| `hotfix-8.4-8.8.patch` | — | Разница между ними (git-патч, +34 −7) |

Затронут **только** `app/Filament/Pages/SuperJudgePad.php`. Миграций нет, схема БД не меняется.

## Что исправлено
- **8.4** — ожидаемое число оценок считается по бригаде ТЕКУЩЕГО турнира
  (`competition_user`), а не по всем активным судьям системы. Для турнира №4:
  Копылова + 3 судьи = 4 оценки (раньше ждали 7).
- **8.8** — после «Утвердить» переход только ВПЕРЁД (`sort_order` больше текущего).
  Если впереди никого нет, но есть пропущенные — постоянное предупреждение
  «Остались неоценённые участники: N», выбирать их вручную.
  Если у текущего участника нет номера (жеребьёвка не проведена) — старое поведение.

## Подготовка
1. Провести **жеребьёвку** турнира №4 (без неё 8.8 не работает).
2. WinSCP: загрузить `hotfix-8.4-8.8.patch` и `SuperJudgePad.hotfix.php` в `~/` на сервере.

## Применение (на сервере)
```bash
cd /var/www/wushu
F=app/Filament/Pages/SuperJudgePad.php

# 0. Страховка
docker commit wushu_app wushu_app:before_hotfix_$(date +%F)
docker exec wushu_db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > ~/db_before_hotfix_$(date +%F).sql.gz

# 1. Оба md5 ДОЛЖНЫ быть affdd4a3... Если нет — СТОП, ничего не применять.
md5sum $F
docker exec wushu_app md5sum /var/www/html/$F

# 2. Бэкап текущего файла
cp $F ~/SuperJudgePad.before_hotfix.php

# 3. Применить патч к хосту
git apply --check ~/hotfix-8.4-8.8.patch && git apply ~/hotfix-8.4-8.8.patch
md5sum $F                      # 943d766c...  (должен совпасть с ~/SuperJudgePad.hotfix.php)

# 4. Залить в контейнер и проверить
docker cp $F wushu_app:/var/www/html/$F
docker exec wushu_app md5sum /var/www/html/$F      # 943d766c...
docker exec wushu_app php -l /var/www/html/$F       # No syntax errors
docker exec wushu_app php artisan view:clear

# 5. Проверка 8.4 без запуска турнира: должно вывести 3 (судьи бригады №4 кроме Копыловой)
docker exec wushu_app php artisan tinker --execute="echo App\Models\Competition::find(4)->judges()->where('users.id','!=',3)->whereIn('users.role',['judge','head_judge'])->where('users.is_active_judge',true)->count();"
```
Если `git apply --check` ругается — вместо шага 3 выполнить
`cp ~/SuperJudgePad.hotfix.php $F` (только если в шаге 1 было `affdd4a3...`).

Если после шага 4 поведение пульта не изменилось (кэш OPcache) — `docker restart wushu_app`.
Это **безопасно**: restart сохраняет изменения контейнера. Запрещены только
`docker compose down`, `up --build`, `rm`.

## Откат (≈1 минута)
```bash
cd /var/www/wushu
F=app/Filament/Pages/SuperJudgePad.php
cp ~/SuperJudgePad.before_hotfix.php $F
docker cp $F wushu_app:/var/www/html/$F
docker exec wushu_app md5sum /var/www/html/$F      # affdd4a3...
docker exec wushu_app php artisan view:clear
```

## Проверка перед турниром
- Пульт старшего судьи показывает **4** слота оценок (3 судьи + своя).
- Утвердить участника №5 → переход на №6, а не на пропущенного №2.
- На последнем номере при пропущенных — жёлтое предупреждение с их количеством.

## Как проверено локально
- Серверная версия восстановлена из git-blob `d89acedc` + серверного diff, md5 совпал с `affdd4a3...`.
- `php -l` — без ошибок; файл запущен на кодовой базе коммита `202c36c0` (как на сервере).
- Сгенерированный SQL (PostgreSQL):
  - 8.4: `select * from users inner join competition_user ... where competition_user.competition_id = 4 and users.id != 3 and users.role in ('judge','head_judge') and users.is_active_judge = true`
  - 8.8: `... where competition_id = 4 and is_completed = false and id != :cur and sort_order > :cur_sort order by sort_order, id`
- Живой прогон на базе локально не выполнен (локальный PostgreSQL/Docker не был запущен) — поэтому обязательна проверка на сервере по списку выше.
