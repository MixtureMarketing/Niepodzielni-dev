<?php
/**
 * Plugin Name: Niepodzielni Core
 * Description: Główna wtyczka Must-Use zawierająca logikę biznesową, Custom Post Types oraz integrację API Bookero dla Fundacji Niepodzielni. Uniezależnia dane od motywu.
 * Version: 1.0.0
 * Author: Mixture Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Zabezpieczenie przed bezpośrednim dostępem.
}

// Zdefiniowanie stałej ze ścieżką dla wygody
define( 'NIEPODZIELNI_CORE_PATH', plugin_dir_path( __FILE__ ) . 'niepodzielni-core/' );

// Stałe Bookero (używane przez api/9-bookero-sync.php i inne)
define( 'BOOKERO_CRON_HOOK', 'aktualizuj_terminy_psychologow_event' );
define( 'BOOKERO_OFFSET_KEY', 'terminy_cron_offset' );

// Wersja cache listingu psychologów — zmień żeby wymusić odświeżenie transientów
define( 'NP_PSY_LISTING_VERSION', '1.1.0' );

// 1. REJESTRACJA CPT, TAKSONOMII I METABOXÓW
require_once NIEPODZIELNI_CORE_PATH . 'cpt/14-cpt-psycholog.php';
require_once NIEPODZIELNI_CORE_PATH . 'cpt/16-cpt-aktualnosci.php';
require_once NIEPODZIELNI_CORE_PATH . 'cpt/17-cpt-wydarzenia.php';
require_once NIEPODZIELNI_CORE_PATH . 'cpt/18-cpt-warsztaty.php';
require_once NIEPODZIELNI_CORE_PATH . 'cpt/19-cpt-grupy-wsparcia.php';
require_once NIEPODZIELNI_CORE_PATH . 'cpt/20-cpt-metaboxes.php';

// 2. INTEGRACJA API BOOKERO I AJAX
require_once NIEPODZIELNI_CORE_PATH . 'api/8-bookero-api.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/9-bookero-sync.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/10-ajax-handlers.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/11-bookero-shortcodes.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/12-bookero-enqueue.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/13-bookero-worker-sync.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/14-bk-shared-calendar.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/15-matchmaker-shortcode.php';

// 3. MODYFIKACJE PANELU ADMINA
require_once NIEPODZIELNI_CORE_PATH . 'admin/5-admin-dashboard.php';
require_once NIEPODZIELNI_CORE_PATH . 'admin/6-admin-product-columns.php';
require_once NIEPODZIELNI_CORE_PATH . 'admin/7-admin-settings.php';

// 4. HELPERS — funkcje niezależne od motywu (używane przez shortcodes, admin i Blade)
require_once NIEPODZIELNI_CORE_PATH . 'misc/1-helpers.php';

// JEDNORAZOWE — usuń po wykonaniu
if ( file_exists( NIEPODZIELNI_CORE_PATH . 'misc/99-term-cleanup.php' ) ) {
    require_once NIEPODZIELNI_CORE_PATH . 'misc/99-term-cleanup.php';
}
