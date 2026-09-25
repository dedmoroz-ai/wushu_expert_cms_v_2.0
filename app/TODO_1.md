📋 Инструкция по обслуживанию (TODO.md)
🚀 1. Как обновить сайт (Деплой изменений)
Если вы внесли изменения в код и запушили их в Git, выполните на сервере:


Bash
# 1. Забираем свежий код
git pull

# 2. Если менялись пакеты composer (добавили библиотеку)
docker compose exec app composer install --no-dev --optimize-autoloader

# 3. Если были изменения в базе данных (миграции)
docker compose exec app php artisan migrate --force

# 4. Сбрасываем кеш (обязательно!)
docker compose exec app php artisan optimize:clear

# 5. Перезапускаем контейнер (чтобы PHP увидел новый код)
docker compose restart app
🎨 2. Проблемы со стилями / Админка Filament
Если админка “развалилась”, пропали иконки или стили:

Мягкий вариант:


Bash
docker compose exec app php artisan filament:upgrade
docker compose exec app php artisan optimize:clear
Жесткий вариант (если мягкий не помог):

Удаляем папки стилей на хосте (чтобы пересоздать их начисто):

Bash
rm -rf public/css public/js
Запускаем пересборку:

Bash
docker compose exec app php artisan filament:assets --install
docker compose exec app php artisan filament:upgrade
docker compose exec app php artisan optimize:clear
Сбрасываем кеш браузера (Ctrl + F5).
💾 3. База данных (Бэкап)
Крайне рекомендуется делать перед соревнованиями!

Создать бэкап:


Bash
# Создаст файл backup.sql в текущей папке
docker compose exec db pg_dump -U wushu -d wushu_expert > backup.sql
Скачайте этот файл себе на компьютер через FileZilla.

Восстановить из бэкапа (Осторожно, удалит текущие данные!):


Bash
cat backup.sql | docker compose exec -T db psql -U wushu -d wushu_expert
🧹 4. Место на диске (Очистка)
Если сервер пишет, что места нет (df -h показывает мало свободного места).

Очистка мусора Docker (удаляет старые образы):


Bash
docker system prune -a -f
Очистка логов Laravel:


Bash
truncate -s 0 storage/logs/laravel.log
🔐 5. Права доступа (Permissions)
Если в логах ошибка Permission denied или Failed to open stream.

Исправить владельца (вернуть файлы пользователю www-data):


Bash
chown -R www-data:www-data public
chown -R www-data:www-data storage
Открыть права на запись (безопасно):


Bash
chmod -R 755 public
chmod -R 755 storage
(В экстренном случае, если ничего не помогает: chmod -R 777 ...)

⚙️ 6. Конфигурация (.env)
Если нужно поменять пароли или настройки.

Открыть файл: nano .env
Внести изменения.
Сохранить: Ctrl+O, Enter. Выйти: Ctrl+X.
Обязательно применить изменения:

Bash
docker compose restart app
docker compose exec app php artisan config:clear
Важные настройки для Production:

APP_ENV=production
APP_DEBUG=false (чтобы не показывать ошибки пользователям)
LOG_LEVEL=error (чтобы не забивать диск лишними логами)