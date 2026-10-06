# Release Notes

## [Unreleased](https://github.com/laravel/laravel/compare/v12.11.1...12.x)

### Wushu Expert — 05.10.2026 (тёмная тема: серые кнопки)

* **Серые кнопки в тёмной теме (`:root.dark`).** Кнопки с заливкой `#E6E9E8` в тёмной теме получают фон `#18181B`, текст и иконки — белые (hover — `#27272A`). Затронуты **только кнопки**: меню сайдбара, фоны и таблицы не перекрашиваются (палитра `gray` остаётся дефолтной Filament). Серые кнопки Filament — правила `:root.dark .fi-btn.fi-color-gray` в CSS панели (`AdminPanelProvider`); кастомные кнопки переведены на общий класс `.btn-soft-gray` (светлая тема — прежние `#E6E9E8`/`#272727`) вместо инлайн-заливок: «Скачать PDF»/«Печать» в «Сводке оценок», кнопки «.», disabled «ВВЕДИТЕ ОЦЕНКУ» и «Отмена» на пульте судьи A. JS-ховеры `onmouseover`/`onmouseout`, сбрасывавшие фон в светлый `#E6E9E8` и ломавшие тёмную тему, заменены CSS `:hover`. На пульте старшего судьи disabled-кнопки (`btn-green-huge:disabled` — «РЕЖИМ ПРОСМОТРА», «ЖДЕМ СУДЕЙ…») и на пульте управления `.btn-gray` получили собственные `:root.dark`-правила. Тесты `ButtonPaletteTest` расширены проверками тёмной темы.

### Wushu Expert — 05.10.2026 (план: `docs/PLAN_05.10.2026.md`)

* **Пункт 4.** На пульте судьи A (`JudgePad`, `SuperJudgePad`) кнопки кодов сбавок сгруппированы по полю «Группа» (`deduction_codes.group_label`); пустая группа — блок «Прочее». Лимиты повторов, подсветка нажатых и «ОТМЕНИТЬ ПОСЛЕДНЮЮ» без изменений.
* **Пункт 1.** Переключатели «Аналитика / Сводка оценок / Журнал судейства» перенесены из карточки судьи («Судейская коллегия») в карточку пользователя (раздел «Пользователи») и действуют на все роли, кроме администратора: админ видит разделы всегда (тумблеры у него заблокированы). Легаси-байпас `ScoresSummary::ALLOWED_EMAILS` оставлен. Отчёты `/reports/*` и PDF «Сводка оценок» используют те же проверки; памятки судьям (коды сбавок, возрастные группы) оставлены с собственным доступом (админ / старший судья).
* **Пункт 3.** На дашборд тренера и администратора добавлены два виджета: «Предварительный стартовый протокол» (HTML-страница по поданным заявкам в новой вкладке — `/start-list-preview/{competition}`, только авторизованным тренеру и администратору) и «QR-код страницы результатов» (модалка со ссылкой и кнопкой «Копировать»). Блок статистики сдвинут ниже.
* **Пункт 2 (вариант A).** Набор кодов сбавок per-competition: таблица `competition_deduction_codes`, чекбокс-список «Коды сбавок (судья A)» в карточке соревнования. Свой набор перекрывает глобальный; при пустом наборе действует глобальный активный набор (`deduction_codes.is_active` — значение по умолчанию). Пульты судьи A и PDF-памятка кодов используют эффективный набор; история выставленных сбавок (`score_deductions`) хранит снимок и не меняется.
* **Пункт 3 (правка по скриншоту).** Модалка «QR-код страницы результатов»: затемнение заднего плана теперь покрывает всю область между шапкой (8rem) и футером (50px) на всю ширину окна — шапка и футер остаются без затемнения. Прежний оверлей с классом `z-[9999]` не получал z-index (класс отсутствует в пресобранной теме Filament, панель грузит только `@filamentStyles`) и уходил под топбар/футер/сайдбар — отсюда «частичное затемнение».
### Wushu Expert — 05.10.2026 (замечания по дашборду и списку соревнований)

* **Виджеты дашборда.** У виджетов «Предварительный стартовый протокол» и «QR-код страницы результатов» убраны заголовки секций и описания. В левом виджете вместо дат и адреса — подпись «Предварительный»; в правом вместо URL — «Страница результатов (поделиться)», кнопка — «QR-код» (вместо «Показать QR-код»). Внутри модалки QR-кода ссылка и кнопка «Копировать» без изменений.
* **Список соревнований.** Колонка «Организатор» убрана; первой колонкой идёт аватар турнира из настроек соревнования (круглый; без загруженного аватара — пустая ячейка).
* **Дашборд администратора.** В плашке «Актуальное соревнование» название соревнования кликабельно — переход в карточку соревнования (как через пункт меню «Соревнования»). Остальным ролям название остаётся обычным текстом.
* **Падение карточки соревнования** (`Undefined table: competition_deduction_codes`): локальная БД отставала — миграция `2026_10_05_100100_create_competition_deduction_codes_table` была в статусе Pending (тесты зелёные — `RefreshDatabase` мигрирует sqlite in-memory, поэтому падение не воспроизводилось). Выполнен `php artisan migrate`; деплой-скрипт миграции запускает автоматически.
* **Заголовки виджетов (уточнение 05.10).** В виджете протокола заголовок — «Стартовый протокол», подпись — «Предварительный (по поданным заявкам)»; в виджете QR-кода — «Результаты соревнования», подпись — «(поделиться)». Название соревнования в этих виджетах больше не показывается.

### Wushu Expert — 05.10.2026 (замечания по кнопкам, темам и ползункам)

* **Регресс меню сайдбара (светлая тема) и тёмной темы.** Перебитая вчера палитра `gray` (`#E6E9E8`-градиент) использовалась Filament не только для кнопок, но и для текста пунктов меню сайдбара (светлая тема — «еле видимые» пункты) и фонов тёмной темы (`gray-950` и др. — «посветлевший серый фон»). Палитра `gray` возвращена к дефолтной Filament (`Color::Zinc`); серые кнопки и дальше красятся стандартом `#E6E9E8`/`#272727` инлайн-CSS (`.fi-btn.fi-color-gray`).
* **Ползунки скролла.** По всему приложению — тонкие (6px), бегунок — синий `#0A92BA`, дорожка — серая `#E6E9E8` в светлой теме и `#18181B` в тёмной (переопределение `:root.dark` в CSS панели; на страницах `welcome` и табло, которые всегда тёмные, дорожка сразу `#18181B`). Реализовано через `scrollbar-width: thin` + `scrollbar-color` (Firefox) и `::-webkit-scrollbar` (Chrome/Edge) в CSS панели (`AdminPanelProvider`), публичных layouts (`public`, `base` — табло и страницы результатов/протоколов) и странице `welcome`.

### Wushu Expert — 05.10.2026 (единые цвета кнопок; «съезжающая» шапка/сайдбар)

* **Цвета кнопок.** Все кнопки приложения приведены к пяти стандартам: серый `#E6E9E8` (текст `#272727`), синий `#0A92BA`, зелёный `#229954`, оранжевый `#E67E22`, красный `#DC3532` (текст `#FFFFFF`). Палитры Filament (`primary`/`info` — синий, `success` — зелёный, `warning` — оранжевый, `danger` — красный, `gray` — серый) переопределены в `AdminPanelProvider`: shade 600 (заливка кнопки `bg-custom-600`) равен стандартному hex, shade 500 — осветлённый hover; белая заливка серых кнопок Filament перекрыта инлайн-CSS (`#E6E9E8`/`#272727`). Кастомные кнопки (пульт управления, пульты судьи и старшего судьи, «Сводка оценок», QR-модалка, аналитика, «Предварительный стартовый протокол», welcome, кнопка «Дипломы» в заявках) переведены на те же hex с сохранением прежней семантики цвета; на пульте старшего судьи кнопки «Снять»/«Исправить» получили заливку вместо контурного стиля. Hover — затемнение стандартного цвета.
* **«Съезжающая» шапка/сайдбар при прокрутке.** Причина — `body { padding-bottom: 50px }` под fixed-футер 50px: он давал «мёртвый ход» прокрутки, из-за которого в конце страницы sticky-топбар и sticky-сайдбар (`h-screen`) уезжали вверх на 50px, и шапка/сайдбар/контент разъезжались. Компенсация футера перенесена на контент (`.fi-main { padding-bottom: 60px }`) и навигацию (`.fi-sidebar-nav { padding-bottom: 50px }`), `.fi-layout` — снова `min-height: 100vh`, сайдбар закреплён `top: 0`.

## [v12.11.1](https://github.com/laravel/laravel/compare/v12.11.0...v12.11.1) - 2025-12-23

* Use environment variable for `DB_SSLMODE` - Postgres by [@robsontenorio](https://github.com/robsontenorio) in https://github.com/laravel/laravel/pull/6727
* fix: ensure APP_URL does not have trailing slash in filesystem by [@msamgan](https://github.com/msamgan) in https://github.com/laravel/laravel/pull/6728

## [v12.11.0](https://github.com/laravel/laravel/compare/v12.10.1...v12.11.0) - 2025-11-25

* fix: cookies are not available for subdomains by default by [@joostdebruijn](https://github.com/joostdebruijn) in https://github.com/laravel/laravel/pull/6705
* Fix PHP 8.5 PDO Driver Specific Constant Deprecation by [@RyanSchaefer](https://github.com/RyanSchaefer) in https://github.com/laravel/laravel/pull/6710
* Ignore Laravel compiled views for Vite  by [@QistiAmal1212](https://github.com/QistiAmal1212) in https://github.com/laravel/laravel/pull/6714

## [v12.10.1](https://github.com/laravel/laravel/compare/v12.10.0...v12.10.1) - 2025-11-06

* Update schema URL in package.json by [@robinmiau](https://github.com/robinmiau) in https://github.com/laravel/laravel/pull/6701

## [v12.10.0](https://github.com/laravel/laravel/compare/v12.9.1...v12.10.0) - 2025-11-04

* Add background driver by [@barryvdh](https://github.com/barryvdh) in https://github.com/laravel/laravel/pull/6699

## [v12.9.1](https://github.com/laravel/laravel/compare/v12.9.0...v12.9.1) - 2025-10-23

* [12.x] Replace Bootcamp with Laravel Learn by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6692
* [12.x] Comment out CLI workers for fresh applications by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6693

## [v12.9.0](https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0) - 2025-10-21

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.8.0...v12.9.0

## [v12.8.0](https://github.com/laravel/laravel/compare/v12.7.1...v12.8.0) - 2025-10-20

* [12.x] Makes test suite using broadcast's `null` driver by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/laravel/pull/6691

## [v12.7.1](https://github.com/laravel/laravel/compare/v12.7.0...v12.7.1) - 2025-10-15

* Added `failover` driver to the `queue` config comment.  by [@sajjadhossainshohag](https://github.com/sajjadhossainshohag) in https://github.com/laravel/laravel/pull/6688

## [v12.7.0](https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0) - 2025-10-14

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.6.0...v12.7.0

## [v12.6.0](https://github.com/laravel/laravel/compare/v12.5.0...v12.6.0) - 2025-10-02

* Fix setup script by [@goldmont](https://github.com/goldmont) in https://github.com/laravel/laravel/pull/6682

## [v12.5.0](https://github.com/laravel/laravel/compare/v12.4.0...v12.5.0) - 2025-09-30

* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6670
* Fix CVEs affecting vite by [@faissaloux](https://github.com/faissaloux) in https://github.com/laravel/laravel/pull/6672
* Update .editorconfig to target compose.yaml by [@fredikaputra](https://github.com/fredikaputra) in https://github.com/laravel/laravel/pull/6679
* Add pre-package-uninstall script to composer.json by [@cosmastech](https://github.com/cosmastech) in https://github.com/laravel/laravel/pull/6681

## [v12.4.0](https://github.com/laravel/laravel/compare/v12.3.1...v12.4.0) - 2025-08-29

* [12.x] Add default Redis retry configuration by [@mateusjatenee](https://github.com/mateusjatenee) in https://github.com/laravel/laravel/pull/6666

## [v12.3.1](https://github.com/laravel/laravel/compare/v12.3.0...v12.3.1) - 2025-08-21

* [12.x] Bump Pint version by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6653
* [12.x] Making sure all related processed are closed when terminating the currently command by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6654
* [12.x] Use application name from configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6655
* Bring back postAutoloadDump script by [@jasonvarga](https://github.com/jasonvarga) in https://github.com/laravel/laravel/pull/6662

## [v12.3.0](https://github.com/laravel/laravel/compare/v12.2.0...v12.3.0) - 2025-08-03

* Fix Critical Security Vulnerability in form-data Dependency by [@izzygld](https://github.com/izzygld) in https://github.com/laravel/laravel/pull/6645
* Revert "fix" by [@RobertBoes](https://github.com/RobertBoes) in https://github.com/laravel/laravel/pull/6646
* Change composer post-autoload-dump script to Artisan command by [@lmjhs](https://github.com/lmjhs) in https://github.com/laravel/laravel/pull/6647

## [v12.2.0](https://github.com/laravel/laravel/compare/v12.1.0...v12.2.0) - 2025-07-11

* Add Vite 7 support by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6639

## [v12.1.0](https://github.com/laravel/laravel/compare/v12.0.11...v12.1.0) - 2025-07-03

* [12.x] Disable nightwatch in testing by [@laserhybiz](https://github.com/laserhybiz) in https://github.com/laravel/laravel/pull/6632
* [12.x] Reorder environment variables in phpunit.xml for logical grouping by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6634
* Change to hyphenate prefixes and cookie names by [@u01jmg3](https://github.com/u01jmg3) in https://github.com/laravel/laravel/pull/6636
* [12.x] Fix type casting for environment variables in config files by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6637

## [v12.0.11](https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11) - 2025-06-10

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.10...v12.0.11

## [v12.0.10](https://github.com/laravel/laravel/compare/v12.0.9...v12.0.10) - 2025-06-09

* fix alphabetical order by [@Khuthaily](https://github.com/Khuthaily) in https://github.com/laravel/laravel/pull/6627
* [12.x] Reduce redundancy and keeps the .gitignore file cleaner by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6629
* [12.x] Fix: Add void return type to satisfy Rector analysis by [@Aluisio-Pires](https://github.com/Aluisio-Pires) in https://github.com/laravel/laravel/pull/6628

## [v12.0.9](https://github.com/laravel/laravel/compare/v12.0.8...v12.0.9) - 2025-05-26

* [12.x] Remove apc by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6611
* [12.x] Add JSON Schema to package.json by [@martinbean](https://github.com/martinbean) in https://github.com/laravel/laravel/pull/6613
* Minor language update by [@woganmay](https://github.com/woganmay) in https://github.com/laravel/laravel/pull/6615
* Enhance .gitignore to exclude common OS and log files by [@mohammadRezaei1380](https://github.com/mohammadRezaei1380) in https://github.com/laravel/laravel/pull/6619

## [v12.0.8](https://github.com/laravel/laravel/compare/v12.0.7...v12.0.8) - 2025-05-12

* [12.x] Clean up URL formatting in README by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6601

## [v12.0.7](https://github.com/laravel/laravel/compare/v12.0.6...v12.0.7) - 2025-04-15

* Add `composer run test` command by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/laravel/pull/6598
* Partner Directory Changes in ReadME by [@joshcirre](https://github.com/joshcirre) in https://github.com/laravel/laravel/pull/6599

## [v12.0.6](https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6) - 2025-04-08

**Full Changelog**: https://github.com/laravel/laravel/compare/v12.0.5...v12.0.6

## [v12.0.5](https://github.com/laravel/laravel/compare/v12.0.4...v12.0.5) - 2025-04-02

* [12.x] Update `config/mail.php` to match the latest core configuration by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6594

## [v12.0.4](https://github.com/laravel/laravel/compare/v12.0.3...v12.0.4) - 2025-03-31

* Bump vite from 6.0.11 to 6.2.3 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6586
* Bump vite from 6.2.3 to 6.2.4 by [@thinkverse](https://github.com/thinkverse) in https://github.com/laravel/laravel/pull/6590

## [v12.0.3](https://github.com/laravel/laravel/compare/v12.0.2...v12.0.3) - 2025-03-17

* Remove reverted change from CHANGELOG.md by [@AJenbo](https://github.com/AJenbo) in https://github.com/laravel/laravel/pull/6565
* Improves clarity in app.css file by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6569
* [12.x] Refactor: Structural improvement for clarity by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6574
* Bump axios from 1.7.9 to 1.8.2 - Vulnerability patch by [@abdel-aouby](https://github.com/abdel-aouby) in https://github.com/laravel/laravel/pull/6572
* [12.x] Remove Unnecessarily [@source](https://github.com/source) by [@AhmedAlaa4611](https://github.com/AhmedAlaa4611) in https://github.com/laravel/laravel/pull/6584

## [v12.0.2](https://github.com/laravel/laravel/compare/v12.0.1...v12.0.2) - 2025-03-04

* Make the github test action run out of the box independent of the choice of testing framework by [@ndeblauw](https://github.com/ndeblauw) in https://github.com/laravel/laravel/pull/6555

## [v12.0.1](https://github.com/laravel/laravel/compare/v12.0.0...v12.0.1) - 2025-02-24

* [12.x] prefer stable stability by [@pataar](https://github.com/pataar) in https://github.com/laravel/laravel/pull/6548

## [v12.0.0 (2025-??-??)](https://github.com/laravel/laravel/compare/v11.0.2...v12.0.0)

Laravel 12 includes a variety of changes to the application skeleton. Please consult the diff to see what's new.
