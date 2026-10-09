# Локальная проверка судейства

Проверено на этой машине: WSL Ubuntu 24.04, PHP 8.3.6 (`pdo_pgsql` есть,
`pdo_sqlite` нет), Docker 29.1.3, PostgreSQL 17 в контейнере.

## 1. Поднять базу

`docker-compose.yml` рассчитан на прод (собирает образ приложения и не
публикует порт БД наружу). Для локальной работы достаточно одного контейнера
с PostgreSQL:

```bash
docker run -d --name wushu_db_local \
  -e POSTGRES_DB=wushu_expert \
  -e POSTGRES_USER=sail \
  -e POSTGRES_PASSWORD=password \
  -p 5432:5432 postgres:17
```

В `.env` стоит `DB_HOST=pgsql` (имя сервиса внутри Docker), поэтому с хоста
нужно переопределять хост. Либо один раз поправь `.env` на `DB_HOST=127.0.0.1`,
либо подставляй переменную в каждой команде — ниже используется второй вариант.

Проверка связи:

```bash
DB_HOST=127.0.0.1 php artisan db:show
```

## 2. Миграции

```bash
DB_HOST=127.0.0.1 php artisan migrate --force
```

Контроль, что новые правила легли в схему:

```bash
# scores.score → numeric(5,3)
docker exec wushu_db_local psql -U sail -d wushu_expert \
  -c "select numeric_precision, numeric_scale from information_schema.columns
      where table_name='scores' and column_name='score';"

# лимиты возрастной категории
docker exec wushu_db_local psql -U sail -d wushu_expert \
  -c "select column_name from information_schema.columns
      where table_name='age_groups' and column_name like '%_score';"

# журнал аудита (с 24.09.2026: reason → text, есть details json)
docker exec wushu_db_local psql -U sail -d wushu_expert -c '\d judging_logs'

# сценарий A/B и коды сбавок
docker exec wushu_db_local psql -U sail -d wushu_expert -c '\d deduction_codes'
docker exec wushu_db_local psql -U sail -d wushu_expert -c '\d score_deductions'
docker exec wushu_db_local psql -U sail -d wushu_expert \
  -c "select table_name, column_name from information_schema.columns
      where column_name in ('judging_scheme','panel','score_a','score_b','b_min_score','b_max_score');"
```

Миграции идемпотентны: повторный `migrate` выдаёт «Nothing to migrate».

## 3. Автотесты

Unit-тесты БД не требуют и идут «как есть»:

```bash
./vendor/bin/phpunit tests/Unit
```

Feature-тесты требуют драйвер БД. `phpunit.xml` настроен на `sqlite :memory:`,
но `pdo_sqlite` на этой машине не установлен, поэтому гоняем их на PostgreSQL
в отдельной базе (тесты используют `RefreshDatabase` — база очищается):

```bash
docker exec wushu_db_local psql -U sail -d postgres \
  -c 'CREATE DATABASE wushu_test OWNER sail;'

DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=wushu_test DB_USERNAME=sail DB_PASSWORD=password \
./vendor/bin/phpunit
```

Ожидаемый результат (24.09.2026): **OK (87 tests, 203 assertions)**.

Ключевые наборы по сценарию A/B:

| Файл | Что проверяет |
|---|---|
| `tests/Unit/JudgingCalculatorTest.php` | среднее с отбрасыванием, 5 − сбавки, лимит 2 нажатий кода, A + B |
| `tests/Unit/ScoreRangePanelTest.php` | диапазоны судей A/B и итога |
| `tests/Unit/JudgePadInputTest.php` | ввод B (1 цифра целой части), кнопки кодов, отмена |
| `tests/Feature/JudgingRulesTest.php` | схема БД, `ScoreWriter` (транзакция, перезапись сбавок, журнал), снимок кода |
| `tests/Feature/AbJudgingFlowTest.php` | сквозной цикл на пультах: A1, A2, B1, B2, старший (B) → итог 8.600 |

Если нужного драйвера нет, feature-тесты не падают, а помечаются
как skipped — см. `setUp()`.

Альтернатива (чтобы работал дефолтный `phpunit.xml`):
`sudo apt install php8.3-sqlite3`.

## 4. Демо-данные и ручная проверка

```bash
DB_HOST=127.0.0.1 php artisan db:seed --class=JudgingDemoSeeder --force
DB_HOST=127.0.0.1 php artisan filament:assets
DB_HOST=127.0.0.1 php artisan serve --host=0.0.0.0 --port=8000
```

Сидер создаёт демо-турнир: бригада из старшего судьи и трёх линейных, один
судья вне бригады, пять участников, на ковре — третий (позади остались
неоценённые №1 и №2), возрастная категория с диапазоном 5.000–10.000.

Учётные записи (пароль у всех `password`):

| Роль | E-mail |
|---|---|
| admin | `admin@demo.local` |
| head_judge | `head@demo.local` |
| judge (в бригаде) | `judge1@demo.local`, `judge2@demo.local`, `judge3@demo.local` |
| judge (вне бригады) | `judge-outsider@demo.local` |

Адреса: `/admin/login`, `/admin/judge-pad`, `/admin/super-judge-pad`,
`/admin/judging-logs`, `/scoreboard`.

### Сценарии

1. **Допуск по бригаде (п. 8.5).** Войти как `judge-outsider@demo.local` →
   `/admin/judge-pad` должен отказать в доступе. Любой из `judge1..3` — работает.
2. **Диапазон и точность (п. 8.1–8.3).** На пульте судьи видна подсказка
   «ДИАПАЗОН: 5.000–10.000». Цифра `4` не принимается первой, `10.000`
   вводится, четвёртый знак после точки не набирается.
3. **Счётчик бригады (п. 8.4).** У старшего судьи «ОЦЕНОК: N / 4»
   (3 линейных + сам старший). Расчёт стартует только при 4 оценках.
4. **Исправления (п. 8.7).** До утверждения протокола: «Исправить оценку» у
   линейного судьи, «Исправить» у старшего, «Снять» на карточке линейного судьи.
5. **Переход только вперёд (п. 8.8).** Утвердить протокол участника №3 →
   система перейдёт к №4, а не вернётся к №1. Когда список кончится,
   появится предупреждение об оставшихся неоценённых участниках.
6. **Журнал (п. 8.9).** Под `admin@demo.local` открыть «Журнал судейства» —
   там должны быть записи по всем действиям выше, с автором и причиной.

### Сценарий A/B (п. 8.6)

```bash
DB_HOST=127.0.0.1 php artisan db:seed --class=JudgingAbDemoSeeder --force
```

Сидер сам вызывает `JudgingDemoSeeder` и `DeductionCodeSeeder`, затем
переключает демо-турнир на сценарий `ab`:

| Функция | Судьи |
|---|---|
| A (коды сбавок) | `judge1@demo.local`, `judge2@demo.local`, `head@demo.local` |
| B (клавиатура) | `judge3@demo.local`, `judge4@demo.local` (создаётся сидером) |

Лимит судей B в демо-категории — 2.000–5.000. Вернуть прежний режим:
`db:seed --class=JudgingDemoSeeder` (ставит `judging_scheme = simple`).

1. **Судья A.** `judge1` → вместо клавиатуры кнопки кодов; старт 5.000, каждое
   нажатие уменьшает балл; третий раз один и тот же код не нажимается;
   «Отменить» снимает последнее нажатие. После сохранения виден список сбавок.
2. **Судья B.** `judge3` → «ДИАПАЗОН: 2.000–5.000», `1` первой цифрой не
   принимается, двузначная целая часть не набирается.
3. **Старший судья.** Счётчики «A: n / 3», «B: n / 2», формула
   «A x + B y = итог»; утвердить можно только когда собраны обе панели.
4. **Функция не назначена.** В «Соревнования → Бригада» убрать у судьи функцию →
   на его пульте «Функция судьи не назначена».
5. **Справочник.** «Справочники → Коды сбавок»: выключенный код пропадает с
   пультов, уже сохранённые сбавки не меняются.
6. **Журнал.** Кнопка «Подробно» у записи показывает панель, коды сбавок,
   прежний набор при исправлении и раскладку итога A/B; в A/B у записи
   «Протокол утверждён» коды, нажатые только одним судьёй (не учтённые
   в вычете), выделены цветом; у остальных записей зачёркиваний нет.

> ⚠️ Коды и величины в `DeductionCodeSeeder` — примерные, сверить с правилами федерации.

## 5. Остановить окружение

```bash
# сервер: Ctrl+C или
pkill -f 'artisan serve'

docker stop wushu_db_local && docker rm wushu_db_local
```

Данные живут внутри контейнера (volume не подключён), после `rm` они удаляются —
для повторной проверки просто пройди шаги 1–4 заново.
