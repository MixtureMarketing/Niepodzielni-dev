<?php

/**
 * Globalne helpery związane z wydarzeniami (warsztaty/grupy/wydarzenia).
 *
 * Trzymane jako funkcje globalne, bo Carbon Fields callbacki i bladowe
 * partials wciąż ich używają.  Logika listingów żyje w
 * App\Services\EventsListingService.
 *
 * @package Niepodzielni
 */

if (! defined('ABSPATH')) {
    exit;
}

/** Imię i nazwisko prowadzącego warsztat/grupę — z CPT psycholog albo z pola ręcznego. */
function np_get_event_leader_name(int $pid): string
{
    $facId = (int) get_post_meta($pid, 'prowadzacy_id', true);
    if ($facId) {
        return (string) (get_post_meta($facId, 'imie_i_nazwisko', true) ?: get_the_title($facId));
    }

    return (string) get_post_meta($pid, 'imie_i_nazwisko', true);
}
