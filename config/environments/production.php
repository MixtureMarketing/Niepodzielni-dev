<?php

/**
 * Configuration overrides for WP_ENV === 'production'
 */

use Roots\WPConfig\Config;

// Nigdy nie zapisuj zapytań SQL — kosztuje pamięć i czas na każdym żądaniu
Config::define('SAVEQUERIES', false);
Config::define('WP_DEBUG', false);
Config::define('WP_DEBUG_LOG', false);
Config::define('WP_DEBUG_DISPLAY', false);
Config::define('SCRIPT_DEBUG', false);
