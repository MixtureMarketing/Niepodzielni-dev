<?php

/**
 * SEO: Meta Tags, Open Graph, Twitter Cards, Structured Data (schema.org JSON-LD)
 *
 * @package Niepodzielni
 */

namespace App;

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Builds title, description, image, type, and url for the current page.
 *
 * @return array{title:string,desc:string,image:string,type:string,url:string}
 */
function np_seo_meta(): array
{
    $post_id = get_the_ID() ?: 0;
    $pt      = $post_id ? get_post_type($post_id) : '';

    // Title
    if (is_front_page()) {
        $title = get_bloginfo('name');
    } else {
        $title = get_the_title($post_id) ?: get_bloginfo('name');
    }

    // Description
    if (is_front_page()) {
        $desc = get_bloginfo('description');
    } elseif ($pt === 'psycholog') {
        $desc = wp_strip_all_tags((string) get_post_meta($post_id, 'biogram', true));
    } else {
        $excerpt = $post_id ? get_the_excerpt($post_id) : '';
        $desc    = $excerpt ?: wp_strip_all_tags((string) get_the_content(null, false, $post_id));
    }
    $desc = mb_strimwidth(wp_strip_all_tags($desc), 0, 160, '…');

    // Image
    $image = $post_id ? (get_the_post_thumbnail_url($post_id, 'large') ?: '') : '';
    if (! $image) {
        $image = np_seo_og_image_default();
    }

    // OG type
    if ($pt === 'psycholog') {
        $type = 'profile';
    } elseif (is_singular('post') || is_singular('aktualnosci')) {
        $type = 'article';
    } else {
        $type = 'website';
    }

    // URL
    $url = $post_id ? (string) get_permalink($post_id) : (string) home_url('/');
    if (is_front_page()) {
        $url = (string) home_url('/');
    }

    return compact('title', 'desc', 'image', 'type', 'url');
}

/**
 * Returns the fallback OG image URL stored in option np_seo_og_image.
 */
function np_seo_og_image_default(): string
{
    return (string) get_option('np_seo_og_image', '');
}

/**
 * Returns the archive / listing URL for a given post type.
 * All CPTs have has_archive=false, so we fall back to known slugs.
 *
 * @param string $post_type
 */
function np_seo_listing_url(string $post_type): string
{
    $link = get_post_type_archive_link($post_type);
    if ($link) {
        return (string) $link;
    }

    $defaults = [
        'psycholog'      => home_url('/psycholodzy/'),
        'warsztaty'      => home_url('/warsztaty/'),
        'grupy-wsparcia' => home_url('/grupy-wsparcia/'),
        'wydarzenia'     => home_url('/wydarzenia/'),
        'aktualnosci'    => home_url('/aktualnosci/'),
    ];

    return (string) get_option(
        "np_seo_listing_{$post_type}",
        $defaults[$post_type] ?? home_url('/'),
    );
}

/**
 * Combines a date string (Y-m-d) and time string (H:i) into ISO 8601.
 *
 * @param string $date  "2026-05-15"
 * @param string $time  "18:00"
 */
function np_seo_datetime(string $date, string $time = ''): string
{
    if (! $date) {
        return '';
    }

    return $time ? "{$date}T{$time}:00" : $date;
}

/**
 * Returns image data array [url, width, height] from a post thumbnail or attachment ID.
 *
 * @param int    $post_id
 * @param string $size
 * @return array{0:string,1:int,2:int}|null
 */
function np_seo_image_data(int $post_id, string $size = 'large'): ?array
{
    $thumb_id = (int) get_post_thumbnail_id($post_id);
    if (! $thumb_id) {
        return null;
    }

    $src = wp_get_attachment_image_src($thumb_id, $size);
    if (! $src) {
        return null;
    }

    return [$src[0], (int) $src[1], (int) $src[2]];
}

/**
 * Parses a price string like "150 zł" or "150.00" to a float. Returns 0.0 if unparseable.
 *
 * @param string $raw
 */
function np_seo_price(string $raw): float
{
    return (float) preg_replace('/[^0-9.]/', '', $raw);
}

// ── Section A — meta description + Open Graph + Twitter Cards ────────────────
// Priority 1 — runs before most plugins; covers every page type.

add_action('wp_head', function () {
    $m = np_seo_meta();
    if (! $m['desc'] && ! $m['title']) {
        return;
    }

    $title = esc_attr($m['title']);
    $desc  = esc_attr($m['desc']);
    $image = esc_url($m['image']);
    $url   = esc_url($m['url']);
    $type  = esc_attr($m['type']);

    $lines = [];

    if ($desc) {
        $lines[] = "<meta name=\"description\" content=\"{$desc}\">";
    }

    $lines[] = "<meta property=\"og:title\" content=\"{$title}\">";
    if ($desc) {
        $lines[] = "<meta property=\"og:description\" content=\"{$desc}\">";
    }
    if ($image) {
        $lines[] = "<meta property=\"og:image\" content=\"{$image}\">";
        $lines[] = "<meta property=\"og:image:width\" content=\"1200\">";
        $lines[] = "<meta property=\"og:image:height\" content=\"630\">";
    }
    $lines[] = "<meta property=\"og:url\" content=\"{$url}\">";
    $lines[] = "<meta property=\"og:type\" content=\"{$type}\">";
    $lines[] = "<meta property=\"og:locale\" content=\"pl_PL\">";
    $lines[] = "<meta property=\"og:site_name\" content=\"Fundacja Niepodzielni\">";

    $lines[] = "<meta name=\"twitter:card\" content=\"summary_large_image\">";
    $lines[] = "<meta name=\"twitter:title\" content=\"{$title}\">";
    if ($desc) {
        $lines[] = "<meta name=\"twitter:description\" content=\"{$desc}\">";
    }
    if ($image) {
        $lines[] = "<meta name=\"twitter:image\" content=\"{$image}\">";
    }

    echo implode("\n", $lines) . "\n";
}, 1);

// ── Section B — WebSite + Organization schema ─────────────────────────────────
// Priority 2 — sitewide; defines @id anchors referenced by other sections.

add_action('wp_head', function () {
    $logo_url = 'https://media.niepodzielni.com/wp-content/uploads/20260330165908/Clip-path-group.svg';

    $schema = [
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type'           => 'WebSite',
                '@id'             => home_url('/#website'),
                'name'            => 'Fundacja Niepodzielni',
                'url'             => home_url('/'),
                'inLanguage'      => 'pl',
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => [
                        '@type'       => 'EntryPoint',
                        'urlTemplate' => home_url('/?s={search_term_string}'),
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
            [
                '@type'     => 'NGO',
                '@id'       => home_url('/#organization'),
                'name'      => 'Fundacja Niepodzielni',
                'url'       => home_url('/'),
                'logo'      => [
                    '@type' => 'ImageObject',
                    'url'   => $logo_url,
                ],
                'telephone' => '+48668277176',
                'email'     => 'kontakt@niepodzielni.com',
                'address'   => [
                    [
                        '@type'           => 'PostalAddress',
                        'streetAddress'   => 'ul. Zeylanda 9/3',
                        'addressLocality' => 'Poznań',
                        'postalCode'      => '60-808',
                        'addressCountry'  => 'PL',
                    ],
                    [
                        '@type'           => 'PostalAddress',
                        'streetAddress'   => 'ul. Środkowa 30',
                        'addressLocality' => 'Warszawa',
                        'postalCode'      => '03-431',
                        'addressCountry'  => 'PL',
                    ],
                ],
                'sameAs'    => [
                    'https://www.facebook.com/fundacjaniepodzielni/',
                    'https://www.instagram.com/fundacjaniepodzielni/',
                    'https://www.tiktok.com/@fundacjaniepodzielni',
                    'https://pl.linkedin.com/company/fundacja-niepodzielni',
                ],
            ],
        ],
    ];

    echo '<script type="application/ld+json">'
        . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>' . "\n";
}, 2);

// ── Section C — Person schema for psycholog ───────────────────────────────────
// Priority 5 — singular psycholog only.

add_action('wp_head', function () {
    if (! is_singular('psycholog')) {
        return;
    }

    $pid = get_the_ID();

    $specs   = \np_get_post_terms($pid, 'specjalizacja');
    $obszary = \np_get_post_terms($pid, 'obszar-pomocy');
    $jezyki  = \np_get_post_terms($pid, 'jezyk') ?: ['Polski'];

    $biogram = wp_strip_all_tags((string) get_post_meta($pid, 'biogram', true));
    $image   = get_the_post_thumbnail_url($pid, 'large');

    $schema = [
        '@context'      => 'https://schema.org',
        '@type'         => 'Person',
        '@id'           => get_permalink($pid) . '#person',
        'name'          => get_the_title(),
        'url'           => get_permalink($pid),
        'jobTitle'      => ! empty($specs) ? $specs[0] : 'Psycholog',
        'worksFor'      => ['@id' => home_url('/#organization')],
        'knowsAbout'    => array_values(array_unique(array_merge($specs, $obszary))),
        'knowsLanguage' => $jezyki,
    ];

    if ($image) {
        $schema['image'] = $image;
    }

    if ($biogram) {
        $schema['description'] = $biogram;
    }

    // AggregateRating — when review data is present
    $rating_avg   = (float) get_post_meta($pid, '_average_rating', true);
    $rating_count = (int) get_post_meta($pid, '_reviews_count', true);
    if ($rating_count > 0 && $rating_avg > 0) {
        $schema['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => round($rating_avg, 1),
            'reviewCount' => $rating_count,
            'bestRating'  => 5,
            'worstRating' => 1,
        ];
    }

    // hasOfferCatalog — when Bookero booking links exist
    $bk_pelno = (string) get_post_meta($pid, \np_bk_meta_key('pelnoplatny'), true);
    $bk_nisko = (string) get_post_meta($pid, \np_bk_meta_key('niskoplatny'), true);
    $offers   = [];

    if (! empty(bookero_sanitize_date($bk_pelno))) {
        $price_pelno = np_seo_price((string) get_post_meta($pid, 'stawka_wysokoplatna', true));
        $offer       = [
            '@type'         => 'Offer',
            'name'          => 'Konsultacja pełnopłatna',
            'priceCurrency' => 'PLN',
            'url'           => get_permalink($pid),
        ];
        if ($price_pelno > 0) {
            $offer['price'] = $price_pelno;
        }
        $offers[] = $offer;
    }

    if (! empty(bookero_sanitize_date($bk_nisko))) {
        $price_nisko = np_seo_price((string) get_post_meta($pid, 'stawka_niskoplatna', true));
        $offer       = [
            '@type'         => 'Offer',
            'name'          => 'Konsultacja niskopłatna',
            'priceCurrency' => 'PLN',
            'url'           => get_permalink($pid),
        ];
        if ($price_nisko > 0) {
            $offer['price'] = $price_nisko;
        }
        $offers[] = $offer;
    }

    if ($offers) {
        $schema['hasOfferCatalog'] = [
            '@type'           => 'OfferCatalog',
            'name'            => 'Konsultacje psychologiczne',
            'itemListElement' => $offers,
        ];
    }

    // ReserveAction — direct booking link
    $has_booking = ! empty(bookero_sanitize_date($bk_pelno)) || ! empty(bookero_sanitize_date($bk_nisko));
    if ($has_booking) {
        $schema['potentialAction'] = [
            '@type'  => 'ReserveAction',
            'target' => get_permalink($pid),
            'result' => ['@type' => 'Reservation'],
        ];
    }

    echo '<script type="application/ld+json">'
        . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>' . "\n";
}, 5);

// ── Section D — Event schema for warsztaty + grupy-wsparcia ──────────────────
// Priority 5 — singular warsztaty / grupy-wsparcia only.

add_action('wp_head', function () {
    if (! is_singular(['warsztaty', 'grupy-wsparcia'])) {
        return;
    }

    $pid = get_the_ID();

    $status_map = [
        'Trwa zapisy'       => 'https://schema.org/EventScheduled',
        'Planowane'         => 'https://schema.org/EventScheduled',
        'Zapisy zamknięte'  => 'https://schema.org/EventScheduled',
        'Odwołane'          => 'https://schema.org/EventCancelled',
        ''                  => 'https://schema.org/EventScheduled',
    ];
    $raw_status   = (string) get_post_meta($pid, 'status', true);
    $event_status = $status_map[$raw_status] ?? 'https://schema.org/EventScheduled';

    $data    = (string) get_post_meta($pid, 'data', true);
    // Etap 3: ujednolicony klucz `godzina_rozpoczecia` (fallback na stary `godzina`).
    $start_t = (string) (get_post_meta($pid, 'godzina_rozpoczecia', true)
        ?: get_post_meta($pid, 'godzina', true));
    $end_t   = (string) get_post_meta($pid, 'godzina_zakonczenia', true);

    $startDate = np_seo_datetime($data, $start_t);
    $endDate   = np_seo_datetime($data, $end_t);

    if (! $startDate) {
        return;
    }

    $cena_raw     = (string) get_post_meta($pid, 'cena', true);
    $cena_numeric = np_seo_price($cena_raw);
    $is_free      = $cena_numeric === 0.0 || strtolower($cena_raw) === 'bezpłatne';

    $prowadzacy_id = (int) get_post_meta($pid, 'prowadzacy_id', true);

    $schema = [
        '@context'            => 'https://schema.org',
        '@type'               => 'Event',
        '@id'                 => get_permalink($pid) . '#event',
        'name'                => get_the_title(),
        'description'         => mb_strimwidth(wp_strip_all_tags((string) get_the_content(null, false, $pid)), 0, 300, '…'),
        'startDate'           => $startDate,
        'eventStatus'         => $event_status,
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'organizer'           => ['@id' => home_url('/#organization')],
        'url'                 => get_permalink($pid),
    ];

    if ($endDate) {
        $schema['endDate'] = $endDate;
    }

    // Location from Foundation address (workshops are at Foundation venues)
    $schema['location'] = [
        '@type' => 'Place',
        'name'  => 'Fundacja Niepodzielni',
        'address' => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => 'ul. Zeylanda 9/3',
            'addressLocality' => 'Poznań',
            'postalCode'      => '60-808',
            'addressCountry'  => 'PL',
        ],
    ];

    if ($prowadzacy_id) {
        $schema['performer'] = [
            '@type' => 'Person',
            '@id'   => get_permalink($prowadzacy_id) . '#person',
            'name'  => get_the_title($prowadzacy_id),
            'url'   => get_permalink($prowadzacy_id),
        ];
    }

    // Offers
    if ($is_free) {
        $schema['offers'] = [
            '@type'         => 'Offer',
            'price'         => '0',
            'priceCurrency' => 'PLN',
            'availability'  => 'https://schema.org/InStock',
            'url'           => get_permalink($pid),
        ];
    } elseif ($cena_numeric > 0) {
        $schema['offers'] = [
            '@type'         => 'Offer',
            'price'         => $cena_numeric,
            'priceCurrency' => 'PLN',
            'url'           => get_permalink($pid),
        ];
    }

    // Image
    $img = np_seo_image_data($pid);
    if ($img) {
        $schema['image'] = [
            '@type'  => 'ImageObject',
            'url'    => $img[0],
            'width'  => $img[1],
            'height' => $img[2],
        ];
    }

    echo '<script type="application/ld+json">'
        . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>' . "\n";
}, 5);

// ── Section E — Event schema for wydarzenia ───────────────────────────────────
// Priority 5 — singular wydarzenia only. Different meta fields than warsztaty.

add_action('wp_head', function () {
    if (! is_singular('wydarzenia')) {
        return;
    }

    $pid = get_the_ID();

    $data    = (string) get_post_meta($pid, 'data', true);
    $start_t = (string) get_post_meta($pid, 'godzina_rozpoczecia', true);
    $end_t   = (string) get_post_meta($pid, 'godzina_zakonczenia', true);

    $startDate = np_seo_datetime($data, $start_t);
    $endDate   = np_seo_datetime($data, $end_t);

    if (! $startDate) {
        return;
    }

    $miasto      = (string) get_post_meta($pid, 'miasto', true);
    $lokalizacja = (string) get_post_meta($pid, 'lokalizacja', true);
    $opis        = (string) get_post_meta($pid, 'opis', true);
    // Etap 3: ujednolicony klucz `cena` (fallback na stary `koszt`).
    $koszt_raw   = (string) (get_post_meta($pid, 'cena', true)
        ?: get_post_meta($pid, 'koszt', true));
    $koszt       = np_seo_price($koszt_raw);
    $is_free     = $koszt === 0.0 || strtolower($koszt_raw) === 'bezpłatne';

    $desc = mb_strimwidth(wp_strip_all_tags($opis ?: get_the_excerpt($pid)), 0, 300, '…');

    $schema = [
        '@context'            => 'https://schema.org',
        '@type'               => 'Event',
        '@id'                 => get_permalink($pid) . '#event',
        'name'                => get_the_title(),
        'startDate'           => $startDate,
        'eventStatus'         => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'organizer'           => ['@id' => home_url('/#organization')],
        'url'                 => get_permalink($pid),
    ];

    if ($desc) {
        $schema['description'] = $desc;
    }

    if ($endDate) {
        $schema['endDate'] = $endDate;
    }

    if ($miasto || $lokalizacja) {
        $address = ['@type' => 'PostalAddress', 'addressCountry' => 'PL'];
        if ($miasto) {
            $address['addressLocality'] = $miasto;
        }
        if ($lokalizacja) {
            $address['streetAddress'] = $lokalizacja;
        }
        $schema['location'] = [
            '@type'   => 'Place',
            'name'    => $lokalizacja ?: $miasto,
            'address' => $address,
        ];
    }

    if ($is_free) {
        $schema['offers'] = [
            '@type'         => 'Offer',
            'price'         => '0',
            'priceCurrency' => 'PLN',
            'availability'  => 'https://schema.org/InStock',
            'url'           => get_permalink($pid),
        ];
    } elseif ($koszt > 0) {
        $schema['offers'] = [
            '@type'         => 'Offer',
            'price'         => $koszt,
            'priceCurrency' => 'PLN',
            'url'           => get_permalink($pid),
        ];
    }

    // Image: post thumbnail or zdjecie attachment meta
    $img = np_seo_image_data($pid);
    if (! $img) {
        $att_id = (int) get_post_meta($pid, 'zdjecie', true);
        if ($att_id) {
            $src = wp_get_attachment_image_src($att_id, 'large');
            if ($src) {
                $img = [$src[0], (int) $src[1], (int) $src[2]];
            }
        }
    }

    if ($img) {
        $schema['image'] = [
            '@type'  => 'ImageObject',
            'url'    => $img[0],
            'width'  => $img[1],
            'height' => $img[2],
        ];
    }

    echo '<script type="application/ld+json">'
        . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>' . "\n";
}, 5);

// ── Section F — NewsArticle for aktualnosci + post ────────────────────────────
// Priority 5 — singular aktualnosci or post only.

add_action('wp_head', function () {
    if (! is_singular('aktualnosci') && ! is_singular('post')) {
        return;
    }

    $pid = get_the_ID();

    $schema = [
        '@context'      => 'https://schema.org',
        '@type'         => 'NewsArticle',
        '@id'           => get_permalink($pid) . '#article',
        'headline'      => mb_strimwidth(get_the_title(), 0, 110, '…'),
        'datePublished' => get_the_date('c', $pid),
        'dateModified'  => get_the_modified_date('c', $pid),
        'author'        => ['@id' => home_url('/#organization')],
        'publisher'     => ['@id' => home_url('/#organization')],
        'url'           => get_permalink($pid),
        'inLanguage'    => 'pl',
        'isPartOf'      => ['@id' => home_url('/#website')],
    ];

    $excerpt = get_the_excerpt($pid) ?: get_the_content(null, false, $pid);
    $desc    = mb_strimwidth(wp_strip_all_tags($excerpt), 0, 160, '…');
    if ($desc) {
        $schema['description'] = $desc;
    }

    // articleSection from temat taxonomy (aktualnosci only)
    if (is_singular('aktualnosci')) {
        $tematy = \np_get_post_terms($pid, 'temat');
        if ($tematy) {
            $schema['articleSection'] = implode(', ', $tematy);
        }
    }

    // Image
    $img = np_seo_image_data($pid);
    if (! $img && is_singular('aktualnosci')) {
        $att_id = (int) get_post_meta($pid, 'zdjecie_glowne', true);
        if ($att_id) {
            $src = wp_get_attachment_image_src($att_id, 'large');
            if ($src) {
                $img = [$src[0], (int) $src[1], (int) $src[2]];
            }
        }
    }

    if ($img) {
        $schema['image'] = [
            '@type'  => 'ImageObject',
            'url'    => $img[0],
            'width'  => $img[1],
            'height' => $img[2],
        ];
    }

    echo '<script type="application/ld+json">'
        . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>' . "\n";
}, 5);

// ── Section G — BreadcrumbList ────────────────────────────────────────────────
// Priority 5 — all singular posts and home/blog pages.

add_action('wp_head', function () {
    $items = [];

    if (is_front_page()) {
        return; // No breadcrumb on homepage
    }

    $home = ['position' => 1, 'name' => 'Strona główna', 'item' => home_url('/')];

    if (is_singular()) {
        $pid    = get_the_ID();
        $pt     = get_post_type($pid);
        $pt_obj = get_post_type_object((string) $pt);

        $listing_name = $pt_obj ? (string) $pt_obj->labels->name : '';
        $listing_url  = np_seo_listing_url((string) $pt);

        $items = [
            $home,
            ['position' => 2, 'name' => $listing_name, 'item' => $listing_url],
            ['position' => 3, 'name' => get_the_title()],
        ];
    } elseif (is_home()) {
        $items = [
            $home,
            ['position' => 2, 'name' => 'Aktualności', 'item' => home_url('/aktualnosci/')],
        ];
    } elseif (is_page()) {
        $items = [
            $home,
            ['position' => 2, 'name' => get_the_title()],
        ];
    }

    if (! $items) {
        return;
    }

    $list_items = [];
    foreach ($items as $item) {
        $li = [
            '@type'    => 'ListItem',
            'position' => $item['position'],
            'name'     => $item['name'],
        ];
        if (isset($item['item'])) {
            $li['item'] = $item['item'];
        }
        $list_items[] = $li;
    }

    $schema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $list_items,
    ];

    echo '<script type="application/ld+json">'
        . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>' . "\n";
}, 5);
