<?php

/**
 * Theme filters.
 */

namespace App;

/**
 * Add "… Continued" to the excerpt.
 *
 * @return string
 */
add_filter('excerpt_more', function () {
    return sprintf(' &hellip; <a href="%s">%s</a>', get_permalink(), __('Continued', 'sage'));
});

/**
 * Add type="module" to all Vite/Sage script tags.
 * Required because Vite outputs ESM with import statements.
 */
add_filter('script_loader_tag', function (string $tag, string $handle, string $src): string {
    if (! $src) {
        return $tag;
    }
    if (str_starts_with($handle, 'sage/')) {
        return '<script type="module" src="' . esc_url($src) . '"></script>' . "\n";
    }
    return $tag;
}, 10, 3);

/**
 * Dodaje fetchpriority="high" do głównego CSS — informuje przeglądarkę że
 * to zasób krytyczny (blokuje render), więc ma priorytet nad innymi fetchami.
 */
add_filter('style_loader_tag', function (string $tag, string $handle): string {
    if ($handle === 'sage/app.css') {
        return str_replace('<link ', '<link fetchpriority="high" ', $tag);
    }
    return $tag;
}, 10, 2);
