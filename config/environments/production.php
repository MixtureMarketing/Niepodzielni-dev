<?php

/**
 * Configuration overrides for WP_ENV === 'production'
 *
 * Hardening produkcyjnego runtime'u — wartości jawne, żeby pomyłka w `.env`
 * nie odsłoniła trace'ów PHP/WP klientowi.
 */

use Roots\WPConfig\Config;

// Debug — bezwzględnie wyłączone na produkcji.
Config::define('WP_DEBUG', false);
Config::define('WP_DEBUG_DISPLAY', false);
Config::define('WP_DEBUG_LOG', false);
Config::define('SCRIPT_DEBUG', false);
// Nigdy nie zapisuj zapytań SQL — kosztuje pamięć i czas na każdym żądaniu (PR #5 perf).
Config::define('SAVEQUERIES', false);
Config::define('WP_DISABLE_FATAL_ERROR_HANDLER', false);

// Logowanie/admin tylko po HTTPS.
Config::define('FORCE_SSL_ADMIN', true);
Config::define('FORCE_SSL_LOGIN', true);

// Edycja plików / instalacja wtyczek z poziomu wp-admin — zablokowane.
Config::define('DISALLOW_FILE_EDIT', true);
Config::define('DISALLOW_FILE_MODS', true);

// Skrócony cycle session do 24h (zamiast WP-defaultowych 14 dni dla "remember me").
Config::define('AUTH_COOKIE_EXPIRATION', 86400);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Wskaźnik dla mu-pluginów / monitoringu, że jesteśmy w produkcji.
Config::define('NP_RUNTIME', 'production');
