# Хотфикс 8.4/8.5 для боевого сервера (CompetitionPdfController.php)

Лист «СОСТАВ СУДЕЙСКОЙ КОЛЛЕГИИ» в итоговом протоколе (PDF) показывал
**все судьи системы**. Исправлено на **бригаду текущего турнира**
(таблица `competition_user`), п. 8.4–8.5 правил.

| Файл | md5 | Что это |
|---|---|---|
| `CompetitionPdfController.php` | `4cdba33128a26f29c9ffe1867c44a603` | Текущая версия на сервере (снята с сервера) |
| `CompetitionPdfController.hotfix.php` | `54283bd7a531ed5dcf87ae5e01cadab5` | Версия с хотфиксом (`php -l` чистый) |
| `hotfix-pdf-judges.patch` | — | Разница между ними (git-патч) |

Затронут **только** `app/Http/Controllers/CompetitionPdfController.php`
(метод `finalResults()`, блок «ДАННЫЕ ДЛЯ ЛИСТА АДМИНИСТРАЦИИ»).
Миграций нет, схема БД не меняется. Шаблоны `pdf.parts.admin-page` /
`pdf.parts.teams-page` не трогаем — они корректно отрисовывают то,
что им передал контроллер.

## Что исправлено

Было (строки 209–219 исходника) — все активные судьи системы:

```php
$headJudges = User::with('club')
    ->where('role', 'head_judge')
    ->where('is_active_judge', true)
    ->orderBy('name')
    ->get();
// ... аналогично $lineJudges
```

Стало — только бригада ЭТОГО турнира (`competition_user`), паттерн из
хотфикса SuperJudgePad 8.4 (та же связка `$competition->judges()`
с квалификацией `users.*`):

```php
$headJudges = $competition->judges()
    ->with('club')
    ->where('users.role', 'head_judge')
    ->where('users.is_active_judge', true)
    ->orderBy('users.name')
    ->get();
// ... аналогично $lineJudges
```

Главный судья и главный секретарь берутся из реквизитов соревнования
(`chief_judge_name` / `chief_secretary_name`) — их не трогали.

## Применение (на сервере)

```bash
cd /var/www/wushu
F=app/Http/Controllers/CompetitionPdfController.php

# 0. Страховка
docker commit wushu_app wushu_app:before_pdf_judges_$(date +%F)
docker exec wushu_db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > ~/db_before_pdf_judges_$(date +%F).sql.gz

# 1. md5 ДОЛЖЕН быть 4cdba33128a26f29c9ffe1867c44a603. Если нет — СТОП.
md5sum $F
docker exec wushu_app md5sum /var/www/html/$F

# 2. Загрузить CompetitionPdfController.hotfix.php в ~/ через WinSCP, затем:
docker cp ~/$F.hotfix.php wushu_app:/var/www/html/$F
# или
# cp ~/CompetitionPdfController.hotfix.php $F

# 3. Проверка синтаксиса и очистка кэша
docker exec wushu_app php -l /var/www/html/$F
docker exec wushu_app php artisan view:clear
docker exec wushu_app php artisan optimize:clear

# 4. Контроль: md5 нового файла = 54283bd7a531ed5dcf87ae5e01cadab5
docker exec wushu_app md5sum /var/www/html/$F
```

## Проверка

1. Открыть итоговый протокол PDF (`/competition/{id}/final-results`)
   у турнира с прикреплённой бригадой.
2. Лист «СОСТАВ СУДЕЙСКОЙ КОЛЛЕГИИ»: главный судья, главный секретарь,
   старшие судьи и судьи на ковре — **только** из `competition_user`
   этого турнира; судьи других турниров не выводятся.
3. У турнира без бригады лист содержит только главного судью/секретаря.
4. Остальные листы (команды, протокол, подписи) — без изменений.
