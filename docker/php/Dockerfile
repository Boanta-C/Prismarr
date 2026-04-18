FROM php:8.4-fpm-alpine

# Extensions système requises
RUN apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    zip \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS

# Extensions PHP
RUN docker-php-ext-install \
    pdo_mysql \
    intl \
    zip \
    opcache \
    pcntl \
    sockets \
    mbstring

# Redis via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configuration PHP + PHP-FPM
COPY docker/php/php.ini /usr/local/etc/php/conf.d/argos.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-argos.conf

# Code Symfony baked dans l'image (Portainer git stack)
# En dev, docker-compose.dev.yml surcharge avec un bind mount live
WORKDIR /var/www/html
COPY symfony/ .

# Installer les dépendances + assets AssetMapper
RUN [ -f composer.json ] && composer install --no-dev --optimize-autoloader --apcu-autoloader --no-scripts || true
RUN [ -f bin/console ] && php bin/console importmap:install || true

RUN chown -R www-data:www-data /var/www/html

# Accès au socket Docker (/var/run/docker.sock appartient à root:root 660)
RUN adduser www-data root

USER www-data

EXPOSE 9000
