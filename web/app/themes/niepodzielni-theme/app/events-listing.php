<?php

/**
 * Backward-compat wrappers — delegates to EventsListingService.
 *
 * Logika przeniesiona do app/Services/EventsListingService.php.
 * Ten plik zachowany wyłącznie dla kompatybilności z kodem zewnętrznym
 * korzystającym z nazw globalnych funkcji.
 *
 * @package Niepodzielni
 */

if (! defined('ABSPATH')) {
    exit;
}

/** Zwraca imię i nazwisko prowadzącego dla danego posta warsztatu/grupy. */
function np_get_event_leader_name(int $pid): string
{
    $fac_id = (int) get_post_meta($pid, 'prowadzacy_id', true);
    if ($fac_id) {
        return get_post_meta($fac_id, 'imie_i_nazwisko', true) ?: get_the_title($fac_id);
    }
    return get_post_meta($pid, 'imie_i_nazwisko', true);
}

function get_workshops_listing_data(): array
{
    return app(\App\Services\EventsListingService::class)->getWorkshopsData();
}

function get_wydarzenia_listing_data(): array
{
    return app(\App\Services\EventsListingService::class)->getWydarzeniaData();
}

function get_aktualnosci_listing_data(): array
{
    return app(\App\Services\EventsListingService::class)->getAktualnosciData();
}

function get_psychoedukacja_listing_data(): array
{
    return app(\App\Services\EventsListingService::class)->getPsychoedukacjaData();
}

function get_psychoedukacja_tags(): array
{
    return app(\App\Services\EventsListingService::class)->getPsychoedukacjaTags();
}

function np_clear_events_listing_cache(int $post_id): void
{
    \App\Services\EventsListingService::clearCache($post_id);
}
