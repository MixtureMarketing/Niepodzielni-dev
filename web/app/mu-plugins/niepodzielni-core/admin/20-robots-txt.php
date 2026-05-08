<?php

/**
 * Dynamic robots.txt — używa home_url() dla sitemap, future-proof dla migracji
 * domeny niepodzielni.com (zastępuje statyczny web/robots.txt który zawierał
 * literówkę: niepodzielni.pl + Yoast format zamiast WP-native /wp-sitemap.xml).
 *
 * UWAGA: aby ten filter zadziałał, statyczny plik web/robots.txt musi NIE
 * istnieć (statyczny plik na dysku nadpisuje WP filter).
 */

if (! defined('ABSPATH')) {
    exit;
}

add_filter('robots_txt', function ($output, $public) {
    // Nie modyfikuj na private sites (Settings → Reading → "Discourage search engines")
    if (! $public) {
        return $output;
    }

    $sitemap_url = home_url('/wp-sitemap.xml');

    $lines = [];

    // Content Signals (https://contentsignals.org/) — preferencje użycia treści
    // przez AI crawlerów. ai-train=no = nie używaj do trenowania modeli;
    // search=yes = OK indeksować dla wyszukiwania; ai-input=yes = OK używać
    // on-the-fly gdy ktoś pyta AI o pomoc psychologiczną (live retrieval).
    $lines[] = '# Content Signals — AI usage preferences (https://contentsignals.org)';
    $lines[] = 'Content-Signal: ai-train=no, search=yes, ai-input=yes';
    $lines[] = '';
    $lines[] = 'User-agent: *';
    $lines[] = 'Disallow: /wp-admin/';
    $lines[] = 'Disallow: /wp-login.php';
    $lines[] = 'Disallow: /xmlrpc.php';
    $lines[] = 'Disallow: /wp-json/';
    $lines[] = 'Disallow: /?s=';
    $lines[] = 'Disallow: /wp-content/plugins/';
    $lines[] = 'Disallow: /wp-content/themes/';
    $lines[] = 'Allow: /wp-admin/admin-ajax.php';
    $lines[] = '';
    $lines[] = '# AI i search bots — pełny dostęp do treści';
    $lines[] = '# Fine-grained kontrola przez Cloudflare Dashboard (Bots → Crawlers)';
    foreach (['GPTBot', 'anthropic-ai', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'YouBot', 'CCBot'] as $bot) {
        $lines[] = '';
        $lines[] = 'User-agent: ' . $bot;
        $lines[] = 'Allow: /';
    }
    $lines[] = '';
    $lines[] = '# Sitemap (WordPress 5.5+ native)';
    $lines[] = 'Sitemap: ' . $sitemap_url;
    $lines[] = '';

    return implode("\n", $lines);
}, 10, 2);
