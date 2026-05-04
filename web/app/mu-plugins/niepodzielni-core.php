<?php

/**
 * Plugin Name: Niepodzielni Core
 * Description: Główna wtyczka Must-Use zawierająca logikę biznesową, Custom Post Types oraz integrację API Bookero dla Fundacji Niepodzielni. Uniezależnia dane od motywu.
 * Version: 1.0.0
 * Author: Mixture Marketing
 */

if (! defined('ABSPATH')) {
    exit; // Zabezpieczenie przed bezpośrednim dostępem.
}

// Zdefiniowanie stałej ze ścieżką dla wygody
define('NIEPODZIELNI_CORE_PATH', plugin_dir_path(__FILE__) . 'niepodzielni-core/');

// Stałe Bookero (używane przez api/9-bookero-sync.php i inne)
define('BOOKERO_CRON_HOOK', 'aktualizuj_terminy_psychologow_event');
define('BOOKERO_OFFSET_KEY', 'terminy_cron_offset');

// Wersja cache listingu psychologów — zmień żeby wymusić odświeżenie transientów
define('NP_PSY_LISTING_VERSION', '1.1.0');

// Zabezpieczenie: jeśli katalog niepodzielni-core/ nie istnieje, pomiń ładowanie
if (! is_dir(NIEPODZIELNI_CORE_PATH)) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Niepodzielni Core: katalog ' . NIEPODZIELNI_CORE_PATH . ' nie istnieje. Sklonuj lub skopiuj pliki z repozytorium.');
    }
    return;
}

// 1. REJESTRACJA CPT, TAKSONOMII I METABOXÓW
require_once NIEPODZIELNI_CORE_PATH . 'cpt/14-cpt-psycholog.php';
require_once NIEPODZIELNI_CORE_PATH . 'cpt/16-cpt-aktualnosci.php';
require_once NIEPODZIELNI_CORE_PATH . 'cpt/17-cpt-wydarzenia.php';
require_once NIEPODZIELNI_CORE_PATH . 'cpt/18-cpt-warsztaty.php';
require_once NIEPODZIELNI_CORE_PATH . 'cpt/19-cpt-grupy-wsparcia.php';
require_once NIEPODZIELNI_CORE_PATH . 'cpt/20-cpt-metaboxes.php'; // fallback gdy brak CF
require_once NIEPODZIELNI_CORE_PATH . 'cpt/21-carbon-fields.php'; // Carbon Fields (główny)
require_once NIEPODZIELNI_CORE_PATH . 'cpt/22-cpt-osrodki.php';      // Psychomapa: CPT + taksonomie
require_once NIEPODZIELNI_CORE_PATH . 'cpt/23-osrodki-metaboxes.php'; // Psychomapa: Carbon Fields

// 2. INTEGRACJA API BOOKERO I AJAX
// 8-bookero-api.php usunięty — logika przeniesiona do BookeroApiClient + PsychologistRepository (OOP)
require_once NIEPODZIELNI_CORE_PATH . 'api/9-bookero-sync.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/21-psychomapa-endpoint.php'; // Psychomapa: REST API
require_once NIEPODZIELNI_CORE_PATH . 'api/10-ajax-handlers.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/11-bookero-shortcodes.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/12-bookero-enqueue.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/13-bookero-worker-sync.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/14-bk-shared-calendar.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/15-matchmaker-shortcode.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/18-ai-sync.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/19-ai-endpoints.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/20-ai-feedback.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/22-media-helpers.php';
require_once NIEPODZIELNI_CORE_PATH . 'api/30-panel-psycholog.php'; // panel psychologa — AJAX endpoints

// 3. MODYFIKACJE PANELU ADMINA
require_once NIEPODZIELNI_CORE_PATH . 'admin/5-admin-dashboard.php';
require_once NIEPODZIELNI_CORE_PATH . 'admin/6-admin-product-columns.php';
require_once NIEPODZIELNI_CORE_PATH . 'admin/7-admin-settings.php';
require_once NIEPODZIELNI_CORE_PATH . 'admin/8-login-page.php';
require_once NIEPODZIELNI_CORE_PATH . 'admin/9-psycholog-role.php';                // rola WP psycholog + redirecty
require_once NIEPODZIELNI_CORE_PATH . 'admin/10-psycholog-admin-cols.php';         // kolumna "Konto" na liście
require_once NIEPODZIELNI_CORE_PATH . 'admin/11-psycholog-account-metabox.php';    // metabox "Stwórz konto"

// 4. HELPERS — funkcje niezależne od motywu (używane przez shortcodes, admin i Blade)
require_once NIEPODZIELNI_CORE_PATH . 'misc/1-helpers.php';

// 5. SERWISY OOP (require_once — poza PSR-4 z src/)
require_once NIEPODZIELNI_CORE_PATH . 'services/GeocodingService.php';

// Zarejestruj hook geokodowania (admin: automatyczny zapis po Carbon Fields)
(new \Niepodzielni\Psychomapa\GeocodingService())->registerHooks();

// 6. WP-CLI — komendy dostępne tylko w kontekście CLI
if (defined('WP_CLI') && WP_CLI) {
    require_once NIEPODZIELNI_CORE_PATH . 'cli/ImportPsychomapyCommand.php';
    \WP_CLI::add_command(
        'niepodzielni import-psychomapa',
        new \Niepodzielni\Psychomapa\ImportPsychomapyCommand(
            new \Niepodzielni\Psychomapa\GeocodingService()
        )
    );
}

// JEDNORAZOWE — usuń po wykonaniu
if (file_exists(NIEPODZIELNI_CORE_PATH . 'misc/99-term-cleanup.php')) {
    require_once NIEPODZIELNI_CORE_PATH . 'misc/99-term-cleanup.php';
}
