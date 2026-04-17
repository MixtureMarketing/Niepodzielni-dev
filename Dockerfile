# ──────────────────────────────────────────────────────────────────────────────
# Niepodzielni — Multi-stage Dockerfile
#
# Etapy budowania:
#   node-builder    — Node 20 Alpine: npm ci + Vite build (assets frontendowe)
#   composer-root   — Composer 2: zależności PHP korzenia projektu (bez --dev)
#   composer-theme  — Composer 2: zależności PHP motywu Sage (bez --dev)
#   runtime         — php:8.4-apache: finalny obraz produkcyjny
#
# Optymalizacja Docker Layer Cache:
#   Manifesty (package.json, composer.json/lock) są kopiowane PRZED kodem źródłowym.
#   Warstwy z npm ci / composer install są inwalidowane tylko przy zmianie zależności,
#   nie przy każdej zmianie kodu — oszczędza 2–5 min na każdym buildzie.
#
# Artefakty baked-in:
#   vendor/ (root + theme) i public/build/ są wgrane przez COPY --from,
#   więc entrypoint NIE uruchamia composer install przy starcie kontenera.
# ──────────────────────────────────────────────────────────────────────────────

# ── Stage 1: Frontend (Node/Vite) ─────────────────────────────────────────────
FROM node:20-alpine AS node-builder

WORKDIR /theme

# Kopiuj manifesty przed źródłem — cache npm ci inwalidowany tylko przy zmianie lock
COPY web/app/themes/niepodzielni-theme/package.json \
     web/app/themes/niepodzielni-theme/package-lock.json ./

RUN npm ci --prefer-offline

# Kopiuj resztę motywu i zbuduj assety
COPY web/app/themes/niepodzielni-theme/ ./

RUN npm run build

# ── Stage 2: Composer — root projektu ─────────────────────────────────────────
FROM composer:2 AS composer-root

WORKDIR /app

# Kopiuj manifesty przed źródłem — cache install inwalidowany tylko przy zmianie lock
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# ── Stage 3: Composer — motyw Sage ────────────────────────────────────────────
FROM composer:2 AS composer-theme

WORKDIR /theme

COPY web/app/themes/niepodzielni-theme/composer.json \
     web/app/themes/niepodzielni-theme/composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# ── Stage 4: Runtime (PHP/Apache) ─────────────────────────────────────────────
FROM php:8.4-apache AS runtime

# WP-CLI z oficjalnego obrazu
COPY --from=wordpress:cli /usr/local/bin/wp /usr/local/bin/wp

# Zależności systemowe (bez git — niepotrzebny w produkcji)
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libmagickwand-dev \
    libonig-dev \
    unzip \
    curl \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Crontab dla Bookero (musi być przed chown, żeby plik istniał)
COPY docker/cron/bookero /etc/cron.d/bookero
RUN chmod 0644 /etc/cron.d/bookero

# Rozszerzenia PHP
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j$(nproc) \
        mysqli \
        pdo_mysql \
        gd \
        zip \
        exif \
        intl \
        dom \
        simplexml \
        mbstring \
        opcache

# Rozszerzenia PECL (imagick + redis)
RUN pecl install imagick redis \
    && docker-php-ext-enable imagick redis

# Apache i konfiguracja
RUN a2enmod rewrite headers expires
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

# Ustawienia PHP
COPY docker/php/php.ini /usr/local/etc/php/conf.d/wordpress.ini

# Entrypoint startowy
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html

# ── Artefakty z etapów budowania ──────────────────────────────────────────────
# Vendor i assety kopiowane PRZED COPY . . — zmiana kodu nie invalida tych warstw
COPY --from=composer-root  /app/vendor   ./vendor
COPY --from=composer-theme /theme/vendor ./web/app/themes/niepodzielni-theme/vendor
COPY --from=node-builder   /theme/public/build \
                            ./web/app/themes/niepodzielni-theme/public/build

# Kod źródłowy (ostatni — najczęściej zmienia się, musi być na końcu)
COPY . .

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
