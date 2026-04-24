<?php

/**
 * Helper: np_img_url()
 *
 * Zwraca URL obrazu z CDN (media.niepodzielni.com) na podstawie ścieżki WP.
 * Działa przez wbudowany filtr wp_get_attachment_url z pluginu media-cloud-sync.
 *
 * Użycie w szablonach Blade:
 *   {!! np_img_url('/wp-content/uploads/2026/01/moj-obraz.png') !!}
 *
 * Gdy attachment nie znaleziony → zwraca oryginalną ścieżkę (bezpieczny fallback).
 * Wyniki są cache'owane w static array na czas requestu.
 */

if (! defined('ABSPATH')) {
    exit;
}

function np_img_url(string $wp_path): string
{
    static $cache = [];

    if (isset($cache[$wp_path])) {
        return $cache[$wp_path];
    }

    $full_url = home_url($wp_path);
    $id = attachment_url_to_postid($full_url);

    if (! $id) {
        // Próba z domeną niepodzielni.com (stare hardkodowane adresy)
        $alt_url = 'https://niepodzielni.com' . $wp_path;
        $id = attachment_url_to_postid($alt_url);
    }

    $cache[$wp_path] = $id ? (string) wp_get_attachment_url($id) : $wp_path;

    return $cache[$wp_path];
}
