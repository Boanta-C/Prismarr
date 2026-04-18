FROM dunglas/frankenphp:1.3.6-php8.4-alpine

# ── s6-overlay (init system multi-process) ──────────────────────────────
ARG S6_OVERLAY_VERSION=3.2.0.2
ARG TARGETARCH

# Télécharger s6-overlay (noarch + arch-specific)
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz /tmp/
RUN if [ "$TARGETARCH" = "arm64" ]; then \
      wget -q -O /tmp/s6-overlay-arch.tar.xz https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-aarch64.tar.xz; \
    else \
      wget -q -O /tmp/s6-overlay-arch.tar.xz https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz; \
    fi && \
    tar -C / -Jxpf /tmp/s6-overlay-noarch.tar.xz && \
    tar -C / -Jxpf /tmp/s6-overlay-arch.tar.xz && \
    rm /tmp/s6-overlay-*.tar.xz

# ── Extensions système ──────────────────────────────────────────────────
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
    sqlite-dev \
    $PHPIZE_DEPS

# ── Extensions PHP (pas de Redis, pas de pdo_mysql) ─────────────────────
RUN docker-php-ext-install \
    pdo_sqlite \
    intl \
    zip \
    opcache \
    pcntl \
    sockets \
    mbstring

# ── Composer ────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Config PHP ──────────────────────────────────────────────────────────
COPY docker/frankenphp/php.ini $PHP_INI_DIR/conf.d/prismarr.ini

# ── Caddyfile (HTTP + Mercure intégré) ──────────────────────────────────
COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile

# ── s6 services ─────────────────────────────────────────────────────────
COPY docker/frankenphp/s6/ /etc/s6-overlay/s6-rc.d/

# ── Script d'initialisation (génération secrets + schéma BDD) ───────────
COPY docker/frankenphp/init.sh /usr/local/bin/prismarr-init
RUN chmod +x /usr/local/bin/prismarr-init

# ── Code Symfony ────────────────────────────────────────────────────────
WORKDIR /var/www/html
COPY symfony/ .

# Dépendances + assets
RUN composer install --no-dev --optimize-autoloader --no-scripts || true
RUN php bin/console importmap:install 2>/dev/null || true

# Permissions
RUN mkdir -p var/data var/cache var/log && \
    chown -R www-data:www-data /var/www/html

# ── Healthcheck ─────────────────────────────────────────────────────────
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -f http://localhost:7070/login || exit 1

EXPOSE 7070

# s6-overlay comme PID 1
ENTRYPOINT ["/init"]
