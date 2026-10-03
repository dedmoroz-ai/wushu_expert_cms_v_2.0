FROM serversideup/php:8.4-fpm-nginx

WORKDIR /var/www/html

USER root

# ИСПРАВЛЕНИЕ: Используем специальный установщик расширений.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# ДОБАВИЛ 'gd' СЮДА (для работы с картинками и PDF)
RUN install-php-extensions intl gd

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN php artisan config:clear && \
    php artisan route:clear && \
    php artisan view:clear

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public

USER www-data
