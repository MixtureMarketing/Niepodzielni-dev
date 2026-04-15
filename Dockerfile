FROM php:8.4-apache
# WP-CLI binary (oficjalny obraz)
COPY --from=wordpress:cli /usr/local/bin/wp /usr/local/bin/wp

# Install system dependencies
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
    git \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Crontab dla Bookero
COPY docker/cron/bookero /etc/cron.d/bookero
RUN chmod 0644 /etc/cron.d/bookero

# Install PHP extensions required by WordPress
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

# Install imagick and redis via PECL
RUN pecl install imagick redis \
    && docker-php-ext-enable imagick redis

# Enable Apache modules
RUN a2enmod rewrite headers expires

# Copy Apache VirtualHost config
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

# Copy PHP settings
COPY docker/php/php.ini /usr/local/etc/php/conf.d/wordpress.ini

# Entrypoint startowy
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 80
