# Слияние с сервером (выгрузка 2026-09-29): аудит и план действий

**Дата аудита:** 29.09.2026
**Источник:** `C:\wushu-server` (в WSL — `/mnt/c/wushu-server`), 295 файлов + `.git` + `auto_backup.sql`
**Локально:** `~/wushu-expert`, ветка `ab-judging` (4 коммита поверх базы `202c36c0`)
**Артефакты аудита:** `_server/audit_*.txt` (диффы, списки миграций, результаты сравнения)

---

## 1. Методика аудита

- В выгрузке есть **`.git` серверного репозитория**: origin `github.com/dedmoroz-ai/wushu_expert_cms`, ветка `12.x`, HEAD = `202c36c0` («Add Caddy with full config»).
- Все серверные правки — **незакоммиченное рабочее дерево** поверх `202c36c0`. Наши 4 коммита (`2cf3c17c`, `e413fe4f`, `d991462b`, `4176610a`) на сервер **не** заезжали.
- Значит, обе стороны сравнимы с общей базой:
  - «что сделано на сервере» = `git -C /mnt/c/wushu-server diff HEAD` (26 файлов содержимого);
  - «что сделали мы» = `git diff 202c36c0..HEAD` (86 файлов);
  - пересечение списков = зоны слияния.
- **Важно:** `git status` на сервере без опций показывает ~150 «M»-файлов — это артефакт прав 0777 (выгрузка на NTFS). Реальные правки содержимого видны только с `core.fileMode=false`.

---

## 2. Резюме (главные выводы)

1. Серверные доработки — **две волны**: «QR/публичные результаты» (кон. янв — фев 2026) и **«майская волна» (15–18.05.2026)**.
2. Февральская волна **уже синхронизирована** с нашим репозиторием — 12+ файлов идентичны байт-в-байт (PublicResults, qr-code-modal, public_token-миграция, composer.* и др.). Терять её нечего.
3. **Майская волна в локальном коде отсутствует — это главная зона риска:**
   - PDF: `CompetitionPdfController` (+301 строка, `titlePage()` и др.), `pdf/parts/`, `title-page-standalone`, `final-results` (+138, фикс разделителей заголовков);
   - «Сводка оценок» (`ScoresSummary` + `ScoresSummaryPdfController`) и «Аналитика» (`Analytics`) — только на сервере;
   - судейские категории (`judge_category`) — миграция, User, UserResource, JudgeResource, JudgesRelationManager;
   - фиксы ввода пульта `SuperJudgePad` (лимиты 6/8 символов, сохранение «9»);
   - форма заявок: «Второй участник (Партнёр)» + поле «Оценка», расчёт места по `age_group_id`.
4. Майские снапшоты PDF у нас частично **уже есть** в `_server/` (`CompetitionPdfController.php` == серверный файл; `results.server.blade.php`, `admin-page`, `teams-page` == серверные). Хотфикс «PDF-судей» готов (`_server/CompetitionPdfController.hotfix.php`) и **ещё не выкачен на сервер**.
5. **Дамп БД есть**: `C:\wushu-server\auto_backup.sql` (29.09.2026 10:00, 214 КБ, pg_dump). Старый блокер «дамп не сделан» — снят.
6. Наши A/B-миграции на сервере **не применены** (таблиц `deduction_codes`/`judging_logs`/`score_deductions` в дампе нет) — деплой по схеме безопасен, но `php artisan migrate` придётся прогнать.

---

## 3. Что сделано непосредственно на сервере (инвентарь)

### 3.1. Инфраструктура (только на сервере)
| Файл | Изменение |
|---|---|
| `Caddyfile` | `reverse_proxy` + `X-Forwarded-Proto/Port` (корректный HTTPS за прокси) |
| `Dockerfile` | расширение `gd` (картинки, PDF) |
| `docker-compose.yml` | `TRUSTED_PROXIES=*`, `ASSET_URL`, лимиты 64M, том `./public`, сервис `caddy` + тома `caddy_data/config` |
| `.env` | продакшн-секреты (с локальным **не сливать**) |

### 3.2. QR-код и публичные результаты (февраль; УЖЕ синхронизировано)
Идентичны локальным (сверено `cmp`): `app/Livewire/PublicResults.php`, `resources/views/livewire/public-results.blade.php`, `resources/views/components/layouts/public.blade.php`, `resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php`, `public/images/NMRULOGOWH.svg`, `database/migrations/2026_01_29_120000_add_public_token_to_competitions_table.php`, маршруты `/results/{token?}` и `/competition/{competition}/qr-code`, `composer.json`/`composer.lock` (пакет QR уже в обеих).

Расхождение: `QrCodeController.php` (версии от 02.02, локальная новее на 26 минут и закоммичена). Берём локальную + смоук QR.

### 3.3. Судейские категории (15.05; только на сервере)
- `database/migrations/2026_05_15_065700_add_judge_category_to_users_table.php` — **untracked, импортировать в git**; в БД уже применена.
- `User.php` — `judge_category` в `$fillable` (локально отсутствует!).
- `UserResource.php`, `JudgeResource.php` — select/колонка/фильтры категории (локальных изменений в этих файлах нет).
- `JudgesRelationManager.php` — колонка «Категория» с бейджами.

### 3.4. PDF-май (15–18.05; только на сервере) — главная зона риска
- `CompetitionPdfController.php` **+301 строка**: `titlePage()`, переработанные `startList()`/`finalResults()`, формат дат, работа с логотипами/подписями. Локальная версия — февральская, `titlePage()` **не содержит**.
- `resources/views/pdf/parts/` (`title-page.blade.php`, `admin-page.blade.php`, `teams-page.blade.php`) + `title-page-standalone.blade.php` — только на сервере.
- `final-results.blade.php` +138 — майская переработка + фикс разделителей в заголовках (бэкапы `.bak.titlesep`).
- `start-list.blade.php` +49 — **идентичен** локальному (уже синхронизировано).
- `diplomas_blank.blade.php` +34 — майские правки старого шаблона; **сохранены у нас как `diplomas_blank_old.blade.php`** (сверено байт-в-байт). Наш текущий шаблон (печать на готовом бланке, `e413fe4f`) — более позднее решение заказчика.
- `ScoresSummary.php` + `ScoresSummaryPdfController.php` + `scores-summary.blade.php` (×2) — только на сервере.
- `EditCompetition.php` — кнопки «Титульный лист (PDF)» и «QR-код» (модалка с генерацией `public_token`).

### 3.5. Аналитика (только на сервере)
`app/Filament/Pages/Analytics.php`, `resources/views/filament/pages/analytics.blade.php`, `public/analytics.html`.

### 3.6. Правки форм и пультов (май; частично конфликтуют с нашими)
- `SuperJudgePad.php` +31: ввод моей оценки до 6 символов («8.125»), финал до 8, сохранение «9» (минимум 1 символ), любая первая цифра, округление до 3 знаков. Снапшот сервера == `_server/SuperJudgePad.server.php`.
- `RegistrationsRelationManager.php` +98: select «Второй участник (Партнёр)» (видим для «Дуйлянь»/«Дуйда»), поле «Оценка» (step 0.001), формат `final_score` в 3 знака, место по `age_group_id` + `whereNotNull(final_score)`, плотная нумерация мест.
- `Score.php` — cast `score => double`; `Registration.php` — `final_score`/`score => double`, `is_completed => boolean`.
- `Scoreboard.php` + `scoreboard.blade.php` — QR/URL-блок на паузе (status_code == 2); локальная версия = надмножество (try/catch).

### 3.7. Прочее
- `welcome.blade.php` (18.05) — футер «Разработано … Макс Мороз © 2026» с `images/c989.svg`; локальный вариант (февраль) — с `NMRULOGOWH.svg` и «© 2026». **По датам сервер новее.**
- `public/images/c989.svg` — только на сервере.
- Бэкапы (`*.bak`, `*.backup`, `*.bak.titlesep`, `welcome.blade1.php`, `composer.json.backup`) — **в git не переносить**.
- `auto_backup.sql` — дамп БД 29.09 10:00 (см. §6).

---

## 4. Матрица слияния

### A. Идентичны — действий не требуется
`RegistrationResource.php`, `RegistrationResource/Pages/CreateRegistration.php`, `StyleResource.php`, `AppServiceProvider.php`, `composer.json`, `composer.lock`, `scoreboard.blade.php`, `start-list.blade.php`, `ExportController.php`, `PublicResults.php`, `public-results.blade.php`, `qr-code-modal.blade.php`, `layouts/public.blade.php`, миграция `2026_01_29_120000`. ⚠️ (30.09) в `start-list.blade.php` и `public-results.blade.php` колонка команды переведена с `club->city` на `club->name` — при копировании B сохранить эту правку. Также (30.09) в `PublicResults.php`/`public-results.blade.php` добавлен блок «Командный зачёт» (R-6.14) — при слиянии сохранить и его.

### B. Сервер победитель — скопировать с сервера
| Файл | Почему |
|---|---|
| `Caddyfile`, `Dockerfile`, `docker-compose.yml` | продакшн-инфраструктура; наших изменений в них нет |
| `UserResource.php`, `JudgeResource.php` | судейские категории; локально файлы не менялись |
| `welcome.blade.php` + `public/images/c989.svg` | майский футер новее локального (⚠️ утвердить брендинг, см. §5 п.6) |
| `database/migrations/2026_05_15_065700_...` | перенести в git как есть |

### C. Объединить вручную (union) — контрольные точки
| Файл | Сервер добавляет | Мы добавляем | Итог |
|---|---|---|---|
| `User.php` | `judge_category` в `$fillable` | `withPivot('panel')` | оба изменения |
| `routes/web.php` | `title-page`, `scores-summary` | маршрут `competition.team-standings` (30.09) | оба маршрута + наши |
| `EditCompetition.php` | кнопка «Титульный лист», QR-модалка | try/catch вокруг `Schema::hasColumn`, кнопка «Командный зачёт» (30.09) | сервер + наш try/catch + кнопка «Командный зачёт» |
| `JudgesRelationManager.php` | колонка «Категория» | `panelSelect()` A/B (R-4.18), колонка «Функция» | оба набора |
| `RegistrationsRelationManager.php` | Партнёр, «Оценка», место по `age_group_id`, формат 3 знака | фильтр по соревнованию + сортировка («заявки тренеров», 28.09) | оба набора; **не** добавлять «редактирование заявки, если нет» (решение 28.09) |
| `SuperJudgePad.php` | фиксы ввода 6/8, «9», любая цифра, round(3) | полная переработка A/B | база — наша версия; **проверить**, что все 5 фиксов покрыты логикой `ScoreRange`/`appendDigit` |
| `CompetitionPdfController.php` | май +301 (`titlePage`, `startList`, `finalResults`) | хотфикс «PDF-судей» + метод `teamStandings()` (30.09) | итог = `_server/CompetitionPdfController.hotfix.php` (= серверный май + хотфикс); затем перенести уникальные локальные правки (12 однострочных хунков — проверить по `diff -u`) **и не потерять `teamStandings()` + `pdf/team-standings.blade.php` (30.09)** |
| `final-results.blade.php` | майская переработка + titlesep-фикс | локальные правки (17 хунков — проверить, не относятся ли к A/B) | база — серверная; уникальное локальное перенести; **30.09: колонка «Команда» = `club->name` — не потерять при взятии серверной базы** |

### D. Мы победитель (сервер не менял или наша версия — надмножество)
`JudgePad.php` (на сервере только mode), `Score.php`, `Registration.php`, `Scoreboard.php`, `Competition.php`, `AgeGroup.php`, `AgeGroupResource.php`, `CompetitionResource.php`, `QrCodeController.php` (+ смоук QR).

`diplomas_blank.blade.php` — наш новый шаблон (решение заказчика печатать на готовом бланке); майская версия сервера уже сохранена как `diplomas_blank_old.blade.php` (ничего не потеряно). ⚠️ Перед следующей печатью дипломов — подтвердить выбор шаблона.

Командный зачёт (30.09): `app/Support/TeamStandings.php`, `resources/views/pdf/team-standings.blade.php`, `tests/Unit/TeamStandingsTest.php` — новые файлы, сервер их не знает, конфликтов нет; правки в `PublicResults.php`, `public-results.blade.php`, `routes/web.php`, `EditCompetition.php`, `CompetitionPdfController.php` — см. зоны A/C.

### E. Импортировать с сервера (untracked — сейчас есть ТОЛЬКО на сервере!)
1. `app/Filament/Pages/Analytics.php` + `resources/views/filament/pages/analytics.blade.php` + `public/analytics.html`
2. `app/Filament/Pages/ScoresSummary.php` + `resources/views/filament/pages/scores-summary.blade.php`
3. `app/Http/Controllers/ScoresSummaryPdfController.php` + `resources/views/pdf/scores-summary.blade.php`
4. `resources/views/pdf/parts/` (title-page, admin-page, teams-page) + `resources/views/pdf/title-page-standalone.blade.php`
5. `database/migrations/2026_05_15_065700_add_judge_category_to_users_table.php`
6. `public/images/c989.svg`

### F. Не трогать / не переносить
`.env` (прод-секреты), `storage/` (штампы, подписи, логотипы — уже на месте), `auto_backup.sql`, все `*.bak`/`*.backup`/`*.bak.titlesep`, `welcome.blade1.php`.

---

## 5. План действий

> **Статус 30.09.2026 (ветка `merge/server-2026-09-29`):** шаги 0–3 выполнены (коммиты
> `5799752f`, `92adc09f`, `11a2bff2`, `b7b83223`, `1cbcedd7`; контрольные точки C — в `WORK_SUMMARY.md` §0.1).
> Осталось: 4 (тесты при БД), 5 (смоук), 6 (решения заказчика), 7 (коммит слияния), 8 (деплой), 9.

0. **Стабилизация.** Закоммитить текущее рабочее дерево (`docs/WORK_SUMMARY.md` и др.); создать ветку `merge/server-2026-09-29` от `ab-judging`.
1. **Импорт E** (untracked-файлы сервера) в рабочее дерево; сразу добавить в git — это страховка от потери майской волны.
2. **Копирование B** (сервер-победители) из `/mnt/c/wushu-server`.
3. **Union-слияния C** в порядке: `User.php` → `routes/web.php` → `EditCompetition.php` → `JudgesRelationManager.php` → `RegistrationsRelationManager.php` → `SuperJudgePad.php` → `CompetitionPdfController.php` → `final-results.blade.php`. Для каждого файла — контрольная точка из таблицы C.
   - `CompetitionPdfController.php`: заливка `_server/CompetitionPdfController.hotfix.php` + сверка `diff -u` с локальной версией (перенести функциональные уникальные правки из 12 хунков).
   - `final-results.blade.php`: база — серверная; отдельно проверить 17 локальных хунков; не потерять titlesep-фикс; колонка «Команда» — выводить `club->name` (фикс 30.09).
   - `SuperJudgePad.php`: чек-поведение: ввод «8.125», сохранение «9», любая первая цифра, длинный финал, round(3) в средних.
4. **Тесты:** `php artisan test` (Unit — всегда; Feature требуют БД — поднять docker-БД или прогнать на копии `auto_backup.sql`; без БД 22 feature падают на `connection refused`).
5. **Смоук (визуально/локально):** пульт A и B (простой + A/B сценарий), QR-код и публичная страница, PDF: стартовый, итоговый, титульный лист, сводка оценок, дипломы, аналитика, **командный зачёт** (страница `team-standings` + PDF + блок на публичной). Группа «O» — смоук с приёмкой Фазы 4 (решение заказчика 30.09: смоуки «Командного зачёта» и группы «O» включить после слияния в обязательный чек-лист).
6. **Решения с заказчиком:** (а) футер `welcome` — ✅ решено 30.09: майский «Макс Мороз» (c989.svg); (б) шаблон дипломов — ✅ решено 30.09: новый (бланк), майский old остаётся фолбэком.
7. **Коммит слияния** + обновление `WORK_SUMMARY.md`.
8. **Деплой:** файлы по матрице (B/C/D/E), затем `php artisan migrate --force` (список §6), `config:cache`/`view:cache`, смоук на сервере. Хотфикс «PDF-судей» уже включён в C (см. также `_server/HOTFIX_PDF_JUDGES.md`).
9. **После слияния — текущая работа A/B** (см. §8).

---

## 6. Состояние БД (по `auto_backup.sql`, 29.09.2026)

- Дамп **свежий** (сегодня, 10:00). Рекомендация: сохранить копию вне сервера (в `C:\wushu-server` он лежит рядом с кодом — риск при перезаливке).
- **Применено 24 миграции**, хвост: `2026_01_29_120000_add_public_token_to_competitions_table`, `2026_05_15_065700_add_judge_category_to_users_table`.
- В схеме есть: `public_token`, `judge_category`, `final_score`, `is_completed`. Таблицы `categories` **нет** (и не было).
- **Нет** таблиц `deduction_codes`, `judging_logs`, `score_deductions`, колонок `judging_scheme`/`score_a`/`score_b` — наши миграции не применялись.
- **Pending при деплое:** `2026_01_28_105148_add_score_limits_to_categories` (безопасна: внутри `Schema::hasTable('categories')`-guard, просто выйдет), `2026_09_23_100000_change_scores_score_precision`, `2026_09_23_100100_add_score_limits_to_age_groups`, `2026_09_23_100200_create_judging_logs`, `2026_09_24_100000_add_judging_scheme_and_panels`, `2026_09_24_100100_create_deduction_codes_tables`, `2026_09_24_100200_add_details_to_judging_logs` (+ будущие миграции «кнопок per-турнир»).
- Перед миграциями на проде: взять дамп ещё раз (или подтвердить актуальность `auto_backup.sql`).

---

## 7. Чек-лист «ничего не потерять»

- [x] Майский PDF-кластер: `CompetitionPdfController` (titlePage), `pdf/parts/*`, `title-page-standalone`, `final-results` (titlesep) — E + C union (`5799752f`, `b7b83223`, `1cbcedd7`)
- [x] `ScoresSummary` (страница + PDF-контроллер + 2 blade) — E (`5799752f`)
- [x] `Analytics` (страница + blade + `public/analytics.html`) — E (`5799752f`)
- [x] Судейские категории: миграция + `User` fillable + `UserResource`/`JudgeResource`/`JudgesRelationManager` — E + B + C (`5799752f`, `92adc09f`, `11a2bff2`)
- [x] Форма заявок: Партнёр (Дуйлянь/Дуйда), «Оценка» (step 0.001), место по `age_group_id` — проверено: в нашей версии есть (надмножество серверной)
- [x] Фиксы ввода `SuperJudgePad` (6/8, «9», любая цифра, round(3)) — проверено: покрыты `ScoreRange`/`appendDigit` (база — наша A/B)
- [x] Double-касты `Score`/`Registration` — зона D, наши версии сохранены
- [x] Инфраструктура: Caddyfile, Dockerfile (gd), docker-compose (caddy, 64M, TRUSTED_PROXIES) — B (`92adc09f`)
- [x] Футер `welcome` + `c989.svg` — B (`92adc09f`); ⚠️ брендинг ждёт подтверждения (§5 п.6)
- [x] `diplomas_blank_old.blade.php` (= майский серверный шаблон) остаётся в репо как фолбэк — не трогали
- [x] Хотфикс «PDF-судей» в `CompetitionPdfController` (из `_server/*.hotfix.php`) — влит как база (`b7b83223`)

---

## 8. После слияния — текущая работа A/B

1. **Фильтр подтверждения кодов сбавок A** (правило 08.10: код засчитывается, только если его заметили несколько судей — нажали ≥2 разных судья; код одного судьи, хоть один, хоть два раза, не учитывается; при подтверждении — все нажатия каждого судьи в его личную оценку): — **выполнено 08.10.2026:** `JudgingCalculator::confirmedPanelScores()` (`MIN_CODE_JUDGES`) / `SuperJudgePad::calculateAb()` + `ScoresSummaryMatrix` + авто-расчёт A/B в аналитике (`CompetitionAnalyticsBuilder::autoScore()`, без R-4.6) + тесты (`JudgingCalculatorTest`, `AbJudgingFlowTest`, `ScoresSummaryTest`, `CompetitionAnalyticsBuilderTest`); R-3.13/R-3.14 обновлены.
2. **Пер-турнирные наборы кнопок** (связка `competition ↔ deduction_code` с порядком, кнопка = число сбавки; `ScoreWriter` и глобальный справочник **не меняем**): миграция + выборка кнопок в `JudgePad`/`SuperJudgePad` + настройка (в `DeductionCodeResource` или отдельный ресурс); обновить R-7.8.
3. Мелочи: `.gitignore` (Zone.Identifier), опечатка «нандý» (`JUDGING_RULES.md` стр. 128, 342), решение по `_server/diplomas_test_print.pdf`, прогон всех тестов при живой БД.

---

## 9. Новая функция «Группа O» (особые спортсмены) — только после слияния

Запланирована 30.09.2026 (задача заказчика): спортсмены группы «O» выступают в тех же номинациях,
но в отдельном зачёте; в протоколах подгруппа «(O)» сразу после основной («…Мальчики (7-8 лет)» →
«…Мальчики (7-8 лет) (O)»). **Реализация — Фаза 4 (после слияния и деплоя):** фича трогает файлы
зон A/B/C (`CompetitionPdfController`, `ExportController`, `pdf/final-results.blade.php`,
`livewire/public-results.blade.php`, `RegistrationsRelationManager`) + миграцию `is_special` —
до слияния это лишние ханки в матрице. В планы §2/§7 ханки **не добавлять**. Полный план и решения
заказчика (зафиксированы 30.09.2026, R-6.15 / п. 9.16) — `WORK_SUMMARY.md` §0. При реализации
не потерять фиксы «30.09».
