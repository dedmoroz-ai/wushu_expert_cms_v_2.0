# Инструкция по развертыванию QR-кодов и публичной страницы результатов через WinSCP

## 📋 Файлы для копирования на сервер

### 1. Обновленные файлы:
- `composer.json`
- `routes/web.php`
- `app/Livewire/Scoreboard.php`
- `resources/views/livewire/scoreboard.blade.php`
- `app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php`

### 2. Новые файлы:
- `database/migrations/2026_01_29_120000_add_public_token_to_competitions_table.php`
- `app/Livewire/PublicResults.php`
- `app/Http/Controllers/QrCodeController.php`
- `resources/views/livewire/public-results.blade.php`
- `resources/views/components/layouts/public.blade.php`
- `resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php`

---

## 🚀 Пошаговая инструкция

### Шаг 1: Подключение к серверу через WinSCP
1. Откройте WinSCP и подключитесь к серверу
2. Перейдите в директорию: `/var/www/wushu/`
3. Откройте терминал (Ctrl+P)

### Шаг 2: Создание резервных копий (опционально)

```bash
cd /var/www/wushu && cp composer.json composer.json.backup && cp routes/web.php routes/web.php.backup && cp app/Livewire/Scoreboard.php app/Livewire/Scoreboard.php.backup && cp resources/views/livewire/scoreboard.blade.php resources/views/livewire/scoreboard.blade.php.backup && cp app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php.backup
```

### Шаг 3: Загрузка файлов на сервер через WinSCP

**Перетащите следующие файлы из локальной папки проекта на сервер:**

1. `composer.json` → `/var/www/wushu/composer.json`
2. `routes/web.php` → `/var/www/wushu/routes/web.php`
3. `app/Livewire/Scoreboard.php` → `/var/www/wushu/app/Livewire/Scoreboard.php`
4. `app/Livewire/PublicResults.php` → `/var/www/wushu/app/Livewire/PublicResults.php`
5. `app/Http/Controllers/QrCodeController.php` → `/var/www/wushu/app/Http/Controllers/QrCodeController.php`
6. `app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php` → `/var/www/wushu/app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php`
7. `resources/views/livewire/scoreboard.blade.php` → `/var/www/wushu/resources/views/livewire/scoreboard.blade.php`
8. `resources/views/livewire/public-results.blade.php` → `/var/www/wushu/resources/views/livewire/public-results.blade.php`
9. `resources/views/components/layouts/public.blade.php` → `/var/www/wushu/resources/views/components/layouts/public.blade.php`
10. `resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php` → `/var/www/wushu/resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php`
11. `database/migrations/2026_01_29_120000_add_public_token_to_competitions_table.php` → `/var/www/wushu/database/migrations/2026_01_29_120000_add_public_token_to_competitions_table.php`

### Шаг 4: Создание необходимых директорий (если их нет)

```bash
mkdir -p /var/www/wushu/app/Livewire /var/www/wushu/app/Http/Controllers /var/www/wushu/resources/views/livewire /var/www/wushu/resources/views/components/layouts /var/www/wushu/resources/views/filament/resources/competition-resource/pages /var/www/wushu/database/migrations
```

### Шаг 5: Копирование файлов в Docker контейнер

```bash
docker cp /var/www/wushu/composer.json wushu_app:/var/www/html/composer.json && docker cp /var/www/wushu/routes/web.php wushu_app:/var/www/html/routes/web.php && docker cp /var/www/wushu/app/Livewire/Scoreboard.php wushu_app:/var/www/html/app/Livewire/Scoreboard.php && docker cp /var/www/wushu/app/Livewire/PublicResults.php wushu_app:/var/www/html/app/Livewire/PublicResults.php && docker cp /var/www/wushu/app/Http/Controllers/QrCodeController.php wushu_app:/var/www/html/app/Http/Controllers/QrCodeController.php && docker cp /var/www/wushu/app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php wushu_app:/var/www/html/app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php && docker cp /var/www/wushu/resources/views/livewire/scoreboard.blade.php wushu_app:/var/www/html/resources/views/livewire/scoreboard.blade.php && docker cp /var/www/wushu/resources/views/livewire/public-results.blade.php wushu_app:/var/www/html/resources/views/livewire/public-results.blade.php && docker cp /var/www/wushu/resources/views/components/layouts/public.blade.php wushu_app:/var/www/html/resources/views/components/layouts/public.blade.php && docker cp /var/www/wushu/resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php wushu_app:/var/www/html/resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php && docker cp /var/www/wushu/database/migrations/2026_01_29_120000_add_public_token_to_competitions_table.php wushu_app:/var/www/html/database/migrations/2026_01_29_120000_add_public_token_to_competitions_table.php
```

### Шаг 6: Установка библиотеки QR-кодов

```bash
docker exec wushu_app composer require simplesoftwareio/simple-qrcode
```

### Шаг 7: Выполнение миграции

```bash
docker exec wushu_app php artisan migrate
```

### Шаг 8: Очистка кеша Laravel

```bash
docker exec wushu_app php artisan optimize:clear && docker exec wushu_app php artisan config:cache && docker exec wushu_app php artisan route:cache && docker exec wushu_app php artisan view:clear
```

### Шаг 9: Проверка работы

```bash
docker exec wushu_app php artisan route:list | grep -E "(results|qr-code)"
```

---

## ✅ Чек-лист

- [ ] Все файлы загружены на сервер через WinSCP
- [ ] Все файлы скопированы в Docker контейнер
- [ ] Библиотека QR-кодов установлена
- [ ] Миграция выполнена
- [ ] Кеш Laravel очищен
- [ ] Маршруты проверены
- [ ] Кнопка QR-кода появляется в админ-панели
- [ ] QR-код отображается в паузе на табло
- [ ] Публичная страница результатов работает

---

## 🔧 Устранение проблем

**Если QR-код не загружается:**
```bash
docker exec wushu_app composer dump-autoload && docker exec wushu_app php artisan optimize:clear
```

**Если маршруты не работают:**
```bash
docker exec wushu_app php artisan route:clear && docker exec wushu_app php artisan route:cache
```

**Если файлы не копируются:**
Проверьте путь внутри контейнера:
```bash
docker exec wushu_app ls -la /var/www/html/
```
