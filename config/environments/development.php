<?php

/**
 * Configuration overrides for WP_ENV === 'development'
 */

use Roots\WPConfig\Config;

use function Env\env;

Config::define('SAVEQUERIES', true);
Config::define('QM_SHOW_ALL_QUERIES', true);
Config::define('WP_DEBUG', true);
Config::define('WP_DEBUG_DISPLAY', true);
Config::define('WP_DEBUG_LOG', env('WP_DEBUG_LOG') ?? true);
Config::define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
Config::define('SCRIPT_DEBUG', true);
Config::define('DISALLOW_INDEXING', true);

ini_set('display_errors', '1');

// Lokalny dev często działa po HTTP — wyłączamy wymuszenie SSL aby nie blokować
// admina/logowania (`FORCE_SSL_ADMIN`/`FORCE_SSL_LOGIN` są domyślnie włączone w application.php).
Config::define('FORCE_SSL_ADMIN', false);
Config::define('FORCE_SSL_LOGIN', false);

// Enable plugin and theme updates and installation from the admin
Config::define('DISALLOW_FILE_MODS', false);
