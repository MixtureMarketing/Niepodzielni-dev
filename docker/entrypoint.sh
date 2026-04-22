#!/bin/bash
set -e

# Instalacja zależności PHP.
# Warunek: brak WordPress core (web/wp/wp-load.php) — znaczy że wolumen webwp
# jest pusty i trzeba zainstalować wszystko od zera (composer install zapisuje
# zarówno vendor/ jak i web/wp/ przez installer-paths).
# Przypadek: vendor/ może być wgrany z obrazu (multi-stage COPY --from), ale
# web/wp/ pochodzi z osobnego wolumenu — sprawdzamy więc WP, nie vendor.
if [ ! -f "web/wp/wp-load.php" ]; then
    echo "WordPress core not found — running composer install"
    composer install --no-interaction --prefer-dist --optimize-autoloader
    # Jeśli vendor/ był wgrany z obrazu (multi-stage COPY --from), composer install
    # pomija już-znane paczki i nie wypełnia web/wp/ (wolumen webwp jest pusty).
    # WP-CLI pobiera WordPress core bezpośrednio — pomija ograniczenie named volume.
    if [ ! -f "web/wp/wp-load.php" ]; then
        WP_VERSION=$(grep -o '"roots/wordpress": "[^"]*"' composer.json | grep -o '[0-9][^"]*' || echo "latest")
        echo "Still missing — downloading WordPress ${WP_VERSION} via WP-CLI"
        wp core download --path=web/wp --version="${WP_VERSION}" --allow-root --force
    fi
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

# Carbon Fields assets — vendor/ jest poza DocumentRoot (web/).
# Kopiujemy CF do web/carbon-fields/ żeby assety były dostępne przez HTTP.
# Warunek: kopiuj tylko raz (gdy katalogu nie ma lub jest pusty).
# Filtr carbon_fields_plugin_url w 21-carbon-fields.php wskazuje na ten URL.
if [ ! -d "web/carbon-fields/core" ]; then
    echo "Copying Carbon Fields assets to web/carbon-fields/"
    cp -r vendor/htmlburger/carbon-fields web/carbon-fields
fi

# Redis object cache
cp -f web/app/plugins/redis-cache/includes/object-cache.php web/app/object-cache.php || true

# Flush reguł przepisywania URL (|| true — nie blokuj startu gdy WP niezainicjowany)
php -r '
    define("WP_USE_THEMES", false);
    $_SERVER["HTTP_HOST"]  = "localhost:8000";
    $_SERVER["REQUEST_URI"] = "/";
    require "web/wp/wp-load.php";
    global $wp_rewrite;
    $wp_rewrite->flush_rules(true);
    echo "Rewrite rules flushed." . PHP_EOL;
' || echo "Rewrite flush skipped (WP not configured yet — run wp core install)"

# Uruchom demona cron (system cron dla Bookero)
service cron start
echo "System cron started."

# Uruchom PHP-FPM (proces główny kontenera; nasłuchuje na :9000)
exec php-fpm
