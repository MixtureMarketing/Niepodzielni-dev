#!/bin/bash
set -e

# Instalacja zależności PHP — pomijana gdy vendor/ istnieje (obraz produkcyjny
# ma vendor/ wgrany przez COPY --from=composer-* w Dockerfile).
# Fallback do composer install zachowany dla środowisk dev bez multi-stage build.
if [ ! -d "vendor" ]; then
    echo "vendor/ not found — running composer install (dev/fallback mode)"
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

if [ ! -d "web/app/themes/niepodzielni-theme/vendor" ]; then
    echo "theme vendor/ not found — running composer install for theme"
    composer install --no-interaction --prefer-dist --optimize-autoloader \
        --working-dir=web/app/themes/niepodzielni-theme
fi

# Katalogi wymagane przez WordPress
mkdir -p web/app/uploads
chmod -R 775 web/app/uploads
mkdir -p web/app/cache/acorn/framework/cache \
         web/app/cache/acorn/framework/views \
         web/app/cache/acorn/framework/sessions \
         web/app/cache/acorn/logs
chown -R www-data:www-data web/app/cache web/app/uploads

# Redis object cache
cp -f web/app/plugins/redis-cache/includes/object-cache.php web/app/object-cache.php || true

# Flush reguł przepisywania URL
php -r '
    define("WP_USE_THEMES", false);
    $_SERVER["HTTP_HOST"]  = "localhost:8000";
    $_SERVER["REQUEST_URI"] = "/";
    require "web/wp/wp-load.php";
    global $wp_rewrite;
    $wp_rewrite->flush_rules(true);
    echo "Rewrite rules flushed." . PHP_EOL;
'

# Uruchom demona cron (system cron dla Bookero)
service cron start
echo "System cron started."

# Uruchom Apache (proces główny kontenera)
exec apache2-foreground
