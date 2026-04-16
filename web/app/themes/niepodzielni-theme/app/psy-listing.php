<?php

/**
 * Backward-compat wrapper — delegates to PsychologistListingService.
 *
 * Logika przeniesiona do app/Services/PsychologistListingService.php.
 * Ten plik zachowany wyłącznie dla kompatybilności z kodem zewnętrznym
 * korzystającym z nazw globalnych funkcji.
 *
 * @package Niepodzielni
 */

if (! defined('ABSPATH')) {
    exit;
}

function get_psy_listing_json_data(string $rodzaj = 'nisko'): array
{
    return app(\App\Services\PsychologistListingService::class)->getData($rodzaj);
}

function np_clear_psy_listing_cache(): void
{
    \App\Services\PsychologistListingService::clearCache();
}
