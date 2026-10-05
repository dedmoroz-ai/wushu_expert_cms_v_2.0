FROM serversideup/php:8.4-fpm-nginx

WORKDIR /var/www/html

USER root

# nginx ждёт PHP-ответ столько секунд, сколько задано PHP_MAX_EXECUTION_TIME
# (шаблон site-opts.d рендерит `fastcgi_read_timeout $PHP_MAX_EXECUTION_TIME`;
# в образе serversideup по умолчанию 99с — мало для долгих запросов).
ENV PHP_MAX_EXECUTION_TIME=300

# ИСПРАВЛЕНИЕ: Используем специальный установщик расширений.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# ДОБАВИЛ 'gd' СЮДА (для работы с картинками и PDF)
RUN install-php-extensions intl gd

COPY . .

# Отчёты аналитики: статический /storage/reports/ отдаёт 404 — файлы открывает
# только авторизованный Laravel-маршрут /reports/{файл} (см. docs/ANALYTICS.md).
# Рабочий конфиг: _server/nginx-reports-deny.conf (копируется в server-opts.d).
RUN mkdir -p /etc/nginx/server-opts.d && printf '%s\n' \
    'location ^~ /storage/reports/ { return 404; }' \
    'location = /storage/reports { return 404; }' \
    > /etc/nginx/server-opts.d/reports-deny.conf

RUN composer install --no-dev --optimize-autoloader

RUN php artisan config:clear && \
    php artisan route:clear && \
    php artisan view:clear

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public

USER www-data
