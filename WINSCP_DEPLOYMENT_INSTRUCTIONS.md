# Инструкция по развертыванию публичной страницы результатов и QR-кодов через WinSCP

## 📋 Содержание
1. [Подготовка файлов на локальной машине](#1-подготовка-файлов-на-локальной-машине)
2. [Подключение к серверу через WinSCP](#2-подключение-к-серверу-через-winscp)
3. [Загрузка файлов на сервер](#3-загрузка-файлов-на-сервер)
4. [Выполнение команд в Docker контейнере](#4-выполнение-команд-в-docker-контейнере)
5. [Проверка работы](#5-проверка-работы)

---

## 1. Подготовка файлов на локальной машине

Убедитесь, что все следующие файлы созданы в вашем локальном проекте:

### Новые файлы:
- ✅ `composer.json` (обновлен - добавлена библиотека QR-кодов)
- ✅ `database/migrations/2026_01_29_120000_add_public_token_to_competitions_table.php`
- ✅ `app/Livewire/PublicResults.php`
- ✅ `resources/views/livewire/public-results.blade.php`
- ✅ `resources/views/components/layouts/public.blade.php`
- ✅ `app/Http/Controllers/QrCodeController.php`
- ✅ `resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php`

### Обновленные файлы:
- ✅ `routes/web.php` (добавлены новые маршруты)
- ✅ `app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php` (добавлена кнопка QR-кода)

---

## 2. Подключение к серверу через WinSCP

1. Откройте **WinSCP**
2. Подключитесь к серверу (используйте ваши учетные данные)
3. Перейдите в директорию проекта: `/var/www/wushu/`

---

## 3. Загрузка файлов на сервер

### Шаг 3.1: Создайте резервную копию (рекомендуется)

В терминале WinSCP (Ctrl+P) выполните:

```bash
cd /var/www/wushu
cp composer.json composer.json.backup
cp routes/web.php routes/web.php.backup
cp app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php.backup
```

### Шаг 3.2: Загрузите обновленные файлы

**Через WinSCP перетащите следующие файлы из локальной папки проекта на сервер:**

1. **composer.json**
   - Локально: `composer.json`
   - На сервере: `/var/www/wushu/composer.json`

2. **routes/web.php**
   - Локально: `routes/web.php`
   - На сервере: `/var/www/wushu/routes/web.php`

3. **app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php**
   - Локально: `app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php`
   - На сервере: `/var/www/wushu/app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php`

### Шаг 3.3: Загрузите новые файлы

**Создайте необходимые директории, если их нет:**

В терминале WinSCP:

```bash
mkdir -p /var/www/wushu/app/Livewire
mkdir -p /var/www/wushu/app/Http/Controllers
mkdir -p /var/www/wushu/resources/views/livewire
mkdir -p /var/www/wushu/resources/views/components/layouts
mkdir -p /var/www/wushu/resources/views/filament/resources/competition-resource/pages
mkdir -p /var/www/wushu/database/migrations
```

**Загрузите новые файлы:**

1. **Миграция:**
   - Локально: `database/migrations/2026_01_29_120000_add_public_token_to_competitions_table.php`
   - На сервере: `/var/www/wushu/database/migrations/2026_01_29_120000_add_public_token_to_competitions_table.php`

2. **Livewire компонент:**
   - Локально: `app/Livewire/PublicResults.php`
   - На сервере: `/var/www/wushu/app/Livewire/PublicResults.php`

3. **View для публичной страницы:**
   - Локально: `resources/views/livewire/public-results.blade.php`
   - На сервере: `/var/www/wushu/resources/views/livewire/public-results.blade.php`

4. **Публичный layout:**
   - Локально: `resources/views/components/layouts/public.blade.php`
   - На сервере: `/var/www/wushu/resources/views/components/layouts/public.blade.php`

5. **Контроллер QR-кода:**
   - Локально: `app/Http/Controllers/QrCodeController.php`
   - На сервере: `/var/www/wushu/app/Http/Controllers/QrCodeController.php`

6. **Модальное окно QR-кода:**
   - Локально: `resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php`
   - На сервере: `/var/www/wushu/resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php`

---

## 4. Выполнение команд в Docker контейнере

Откройте терминал WinSCP (Ctrl+P) и выполните команды по порядку:

### Шаг 4.1: Установка библиотеки QR-кодов

```bash
docker exec wushu_app composer require simplesoftwareio/simple-qrcode
```

**Ожидаемый результат:** Библиотека будет установлена. Это может занять 1-2 минуты.

### Шаг 4.2: Копирование файлов в контейнер

**Важно:** Файлы нужно скопировать в контейнер, так как они находятся на хосте, а приложение работает в контейнере.

```bash
docker cp /var/www/wushu/composer.json wushu_app:/var/www/html/composer.json
```

```bash
docker cp /var/www/wushu/routes/web.php wushu_app:/var/www/html/routes/web.php
```

```bash
docker cp /var/www/wushu/app/Livewire/PublicResults.php wushu_app:/var/www/html/app/Livewire/PublicResults.php
```

```bash
docker cp /var/www/wushu/app/Http/Controllers/QrCodeController.php wushu_app:/var/www/html/app/Http/Controllers/QrCodeController.php
```

```bash
docker cp /var/www/wushu/app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php wushu_app:/var/www/html/app/Filament/Resources/CompetitionResource/Pages/EditCompetition.php
```

```bash
docker cp /var/www/wushu/resources/views/livewire/public-results.blade.php wushu_app:/var/www/html/resources/views/livewire/public-results.blade.php
```

```bash
docker cp /var/www/wushu/resources/views/components/layouts/public.blade.php wushu_app:/var/www/html/resources/views/components/layouts/public.blade.php
```

```bash
docker cp /var/www/wushu/resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php wushu_app:/var/www/html/resources/views/filament/resources/competition-resource/pages/qr-code-modal.blade.php
```

```bash
docker cp /var/www/wushu/database/migrations/2026_01_29_120000_add_public_token_to_competitions_table.php wushu_app:/var/www/html/database/migrations/2026_01_29_120000_add_public_token_to_competitions_table.php
```

### Шаг 4.3: Выполнение миграции

```bash
docker exec wushu_app php artisan migrate
```

**Ожидаемый результат:** Миграция будет выполнена, в таблице `competitions` появится колонка `public_token`.

### Шаг 4.4: Очистка кеша Laravel

```bash
docker exec wushu_app php artisan view:clear
```

```bash
docker exec wushu_app php artisan cache:clear
```

```bash
docker exec wushu_app php artisan config:clear
```

```bash
docker exec wushu_app php artisan route:clear
```

### Шаг 4.5: Оптимизация (опционально, но рекомендуется)

```bash
docker exec wushu_app php artisan optimize:clear
```

```bash
docker exec wushu_app php artisan config:cache
```

```bash
docker exec wushu_app php artisan route:cache
```

---

## 5. Проверка работы

### Шаг 5.1: Проверка маршрутов

```bash
docker exec wushu_app php artisan route:list | grep -E "(results|qr-code)"
```

**Ожидаемый результат:** Должны появиться два новых маршрута:
- `GET /results/{token?}`
- `GET /competition/{competition}/qr-code`

### Шаг 5.2: Проверка файлов в контейнере

```bash
docker exec wushu_app ls -la /var/www/html/app/Livewire/PublicResults.php
```

```bash
docker exec wushu_app ls -la /var/www/html/app/Http/Controllers/QrCodeController.php
```

**Ожидаемый результат:** Файлы должны существовать.

### Шаг 5.3: Проверка миграции

```bash
docker exec wushu_app php artisan migrate:status
```

**Ожидаемый результат:** Миграция `add_public_token_to_competitions_table` должна быть в списке выполненных.

### Шаг 5.4: Тестирование функционала

1. **Откройте админ-панель Filament** в браузере
2. Перейдите в раздел **"Соревнования"**
3. Откройте любое соревнование для редактирования
4. В шапке страницы должна появиться новая кнопка **"QR-код для публичной страницы"**
5. Нажмите на кнопку - должно открыться модальное окно с QR-кодом
6. Скопируйте ссылку и откройте её в новой вкладке - должна открыться публичная страница результатов

### Шаг 5.5: Проверка публичной страницы

Откройте в браузере:
```
https://yourdomain.com/results
```

Или с токеном:
```
https://yourdomain.com/results/ВАШ_ТОКЕН
```

**Ожидаемый результат:** Должна открыться страница с результатами соревнований в реальном времени.

---

## 🔧 Устранение проблем

### Проблема: "Class 'SimpleSoftwareIO\QrCode\Facades\QrCode' not found"

**Решение:**
```bash
docker exec wushu_app composer dump-autoload
docker exec wushu_app php artisan optimize:clear
```

### Проблема: "Route not found"

**Решение:**
```bash
docker exec wushu_app php artisan route:clear
docker exec wushu_app php artisan route:cache
```

### Проблема: "View not found"

**Решение:**
```bash
docker exec wushu_app php artisan view:clear
docker exec wushu_app php artisan optimize:clear
```

### Проблема: Файлы не копируются в контейнер

**Проверьте путь внутри контейнера:**
```bash
docker exec wushu_app pwd
docker exec wushu_app ls -la /var/www/html/
```

Если путь другой (например, `/app/`), замените `/var/www/html/` на правильный путь в командах `docker cp`.

---

## 📝 Примечания

1. **Права доступа:** После копирования файлов убедитесь, что права доступа правильные:
   ```bash
   docker exec wushu_app chown -R www-data:www-data /var/www/html
   docker exec wushu_app chmod -R 755 /var/www/html
   ```

2. **Резервные копии:** Все резервные копии находятся в тех же директориях с расширением `.backup`

3. **Откат изменений:** Если что-то пошло не так, восстановите файлы из резервных копий:
   ```bash
   cp composer.json.backup composer.json
   cp routes/web.php.backup routes/web.php
   # и т.д.
   ```

---

## ✅ Чек-лист завершения

- [ ] Все файлы загружены на сервер
- [ ] Все файлы скопированы в Docker контейнер
- [ ] Библиотека QR-кодов установлена
- [ ] Миграция выполнена
- [ ] Кеш Laravel очищен
- [ ] Маршруты проверены
- [ ] Кнопка QR-кода появляется в админ-панели
- [ ] Модальное окно с QR-кодом открывается
- [ ] Публичная страница результатов работает
- [ ] Страница обновляется в реальном времени

---

**Готово!** 🎉 Теперь у вас есть публичная страница результатов и генератор QR-кодов для доступа к ней.
