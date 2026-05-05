<?php

/**
 * Theme setup.
 */

namespace App;

use Illuminate\Support\Facades\Vite;

/**
 * Inject styles into the block editor.
 *
 * @return array
 */
add_filter('block_editor_settings_all', function ($settings) {
    $style = Vite::asset('resources/css/editor.css');

    $settings['styles'][] = [
        'css' => "@import url('{$style}')",
    ];

    return $settings;
});

/**
 * Inject scripts into the block editor.
 *
 * @return void
 */
add_action('admin_head', function () {
    if (! get_current_screen()?->is_block_editor()) {
        return;
    }

    if (! Vite::isRunningHot()) {
        $dependencies = json_decode(Vite::content('editor.deps.json'));

        foreach ($dependencies as $dependency) {
            if (! wp_script_is($dependency)) {
                wp_enqueue_script($dependency);
            }
        }
    }
    echo Vite::withEntryPoints([
        'resources/js/editor.js',
    ])->toHtml();
});

/**
 * Register the theme assets.
 *
 * @return void
 */
add_action('wp_enqueue_scripts', function () {
    // Główne pliki CSS i JS (Vite)
    wp_enqueue_style('sage/app.css', Vite::asset('resources/css/app.css'), false, null);
    wp_enqueue_script('sage/app.js', Vite::asset('resources/js/app.js'), [], null, true);

    // Globalny obiekt konfiguracyjny dla skryptów JS (ajaxUrl, nonce, globalne hashe Bookero)
    wp_localize_script('sage/app.js', 'niepodzielniBookero', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('np_bookero_nonce'),
        'pelnoId' => np_bookero_cal_id_for('pelnoplatny'),
        'niskoId' => np_bookero_cal_id_for('nisko'),
    ]);

    $ai_worker_url = defined('NP_AI_WORKER_URL') && NP_AI_WORKER_URL ? NP_AI_WORKER_URL : '';
    if ($ai_worker_url) {
        wp_localize_script('sage/app.js', 'npAiChat', [
            'workerUrl' => esc_url_raw($ai_worker_url),
            'contact'   => [
                'phone'    => '+48 732 081 111',
                'email'    => 'kontakt@niepodzielni.pl',
                'formUrl'  => 'https://niepodzielni.pl/kontakt/',
            ],
        ]);
    }

    // Flag icons — tylko na stronach specjalistów i listingów (flagi języków)
    if (is_singular('psycholog') || is_page_template(['template-psy-listing-pelno.blade.php', 'template-psy-listing-nisko.blade.php'])) {
        wp_enqueue_style('flag-icons', 'https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.0.0/css/flag-icons.min.css', [], null);
        add_action('wp_head', function () {
            echo '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>' . "\n";
            echo '<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">' . "\n";
        }, 2);
    }

    // Bookero init — na stronach z kalendarzem CPT, listingach lub ze shortcodem [bookero_wspolny_kalendarz]
    $listing_templates    = ['template-psy-listing-pelno.blade.php', 'template-psy-listing-nisko.blade.php'];
    $has_bookero_shortcode = is_singular() && has_shortcode(get_post()->post_content ?? '', 'bookero_wspolny_kalendarz');
    if (is_singular(['psycholog', 'wydarzenia', 'warsztaty', 'grupy-wsparcia']) || $has_bookero_shortcode || is_page_template($listing_templates)) {
        wp_enqueue_script('sage/bookero-init.js', Vite::asset('resources/js/bookero-init.js'), [], null, true);
    }

    // Listing psychologów — tylko na stronach z listingiem
    if (is_page_template(['template-psy-listing-pelno.blade.php', 'template-psy-listing-nisko.blade.php'])) {
        wp_enqueue_script('sage/psy-listing.js', Vite::asset('resources/js/psy-listing-atomic.js'), [], null, true);
        wp_enqueue_script('sage/bk-shared-calendar.js', Vite::asset('resources/js/bk-shared-calendar.js'), [], null, true);
    }

    // Matchmaker — na stronach z shortcodem [matchmaker] lub [np_matchmaker]
    $post = get_post();
    $has_matchmaker = $post && (
        has_shortcode($post->post_content, 'matchmaker')
        || has_shortcode($post->post_content, 'np_matchmaker')
    );
    if ($has_matchmaker) {
        wp_enqueue_script('sage/matchmaker.js', Vite::asset('resources/js/matchmaker.js'), [], null, true);
    }

    // Events listing — warsztaty, wydarzenia, aktualności, psychoedukacja
    $events_templates = [
        'template-warsztaty-grupy.blade.php',
        'template-wydarzenia.blade.php',
        'template-aktualnosci.blade.php',
        'template-psychoedukacja.blade.php',
    ];
    if (is_page_template($events_templates)) {
        wp_enqueue_script('sage/events-listing.js', Vite::asset('resources/js/events-listing.js'), [], null, true);
    }

    // Psychomapa — mapa ośrodków pomocy (Leaflet jsDelivr + własny JS)
    if (is_page_template('template-psychomapa.blade.php') || is_singular('osrodek_pomocy')) {
        wp_enqueue_style('leaflet', 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.css', [], null);
        wp_enqueue_script('leaflet', 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.min.js', [], null, true);
    }
    if (is_page_template('template-psychomapa.blade.php') || is_singular('osrodek_pomocy')) {
        wp_enqueue_script('sage/psychomapa.js', Vite::asset('resources/js/psychomapa.js'), ['leaflet'], null, true);
    }

    // Panel psychologa — dashboard wymaga JS+CSS, login tylko CSS
    $panel_templates = [
        'template-panel-dashboard.blade.php',
        'template-panel-logowanie.blade.php',
    ];
    if (is_page_template($panel_templates)) {
        wp_enqueue_script('sage/panel.js', Vite::asset('resources/js/panel.js'), [], null, true);
        // CSS jest generowany przez Vite z importu w panel.js — enqueue jawnie z manifestu
        if (! Vite::isRunningHot()) {
            $manifest_path = get_theme_file_path('public/build/manifest.json');
            if (file_exists($manifest_path)) {
                $manifest = json_decode((string) file_get_contents($manifest_path), true);
                foreach ($manifest['resources/js/panel.js']['css'] ?? [] as $css_file) {
                    wp_enqueue_style('sage/panel.css', get_theme_file_uri('public/build/' . $css_file), [], null);
                }
            }
        }
        wp_localize_script('sage/panel.js', 'npPanel', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('np_panel_nonce'),
        ]);
    }
}, 100);

/**
 * Preload self-hosted Roboto font files early in <head> so the browser
 * fetches them in parallel with app.css instead of after CSS parsing.
 * Priority 1 places these tags before wp_enqueue_scripts output.
 * Preconnect to media.niepodzielni.com (Cloudflare R2) — saves ~300ms LCP
 * by establishing the TCP+TLS handshake before the first image request.
 */
add_action('wp_head', function () {
    // Cloudflare R2 — TCP+TLS handshake z wyprzedzeniem (obrazki mediów)
    echo '<link rel="preconnect" href="https://media.niepodzielni.com" crossorigin>' . "\n";
    echo '<link rel="dns-prefetch" href="https://media.niepodzielni.com">' . "\n";

    if (Vite::isRunningHot()) {
        return;
    }

    // Czcionki Roboto — pobierane równolegle z parsowaniem CSS (nie po nim)
    $latin_ext = Vite::asset('resources/fonts/roboto-v51-latin-ext.woff2');
    $latin     = Vite::asset('resources/fonts/roboto-v51-latin.woff2');
    echo '<link rel="preload" href="' . esc_url($latin_ext) . '" as="font" type="font/woff2" crossorigin>' . "\n";
    echo '<link rel="preload" href="' . esc_url($latin) . '" as="font" type="font/woff2" crossorigin>' . "\n";

    // modulepreload dla głównego JS — przeglądarka pobiera i parsuje moduł
    // zanim natrafi na <script type="module">, bez blokowania renderowania
    $app_js = Vite::asset('resources/js/app.js');
    echo '<link rel="modulepreload" href="' . esc_url($app_js) . '">' . "\n";
}, 1);

/**
 * Use the generated theme.json file.
 *
 * @return string
 */
add_filter('theme_file_path', function ($path, $file) {
    return $file === 'theme.json'
        ? public_path('build/assets/theme.json')
        : $path;
}, 10, 2);

/**
 * Disable on-demand block asset loading.
 *
 * @link https://core.trac.wordpress.org/ticket/61965
 */
add_filter('should_load_separate_core_block_assets', '__return_false');

/**
 * Register the initial theme setup.
 *
 * @return void
 */
add_action('after_setup_theme', function () {
    /**
     * Disable full-site editing support.
     *
     * @link https://wptavern.com/gutenberg-10-5-embeds-pdfs-adds-verse-block-color-options-and-introduces-new-patterns
     */
    remove_theme_support('block-templates');

    /**
     * Register the navigation menus.
     *
     * @link https://developer.wordpress.org/reference/functions/register_nav_menus/
     */
    register_nav_menus([
        'primary_navigation' => __('Primary Navigation', 'sage'),
    ]);

    /**
     * Disable the default block patterns.
     *
     * @link https://developer.wordpress.org/block-editor/developers/themes/theme-support/#disabling-the-default-block-patterns
     */
    remove_theme_support('core-block-patterns');

    /**
     * Enable plugins to manage the document title.
     *
     * @link https://developer.wordpress.org/reference/functions/add_theme_support/#title-tag
     */
    add_theme_support('title-tag');

    /**
     * Enable post thumbnail support.
     *
     * @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
     */
    add_theme_support('post-thumbnails');

    /**
     * Enable responsive embed support.
     *
     * @link https://developer.wordpress.org/block-editor/how-to-guides/themes/theme-support/#responsive-embedded-content
     */
    add_theme_support('responsive-embeds');

    /**
     * Enable HTML5 markup support.
     *
     * @link https://developer.wordpress.org/reference/functions/add_theme_support/#html5
     */
    add_theme_support('html5', [
        'caption',
        'comment-form',
        'comment-list',
        'gallery',
        'search-form',
        'script',
        'style',
    ]);

    /**
     * Enable selective refresh for widgets in customizer.
     *
     * @link https://developer.wordpress.org/reference/functions/add_theme_support/#customize-selective-refresh-widgets
     */
    add_theme_support('customize-selective-refresh-widgets');
}, 20);

/**
 * Register the theme sidebars.
 *
 * @return void
 */
add_action('widgets_init', function () {
    $config = [
        'before_widget' => '<section class="widget %1$s %2$s">',
        'after_widget' => '</section>',
        'before_title' => '<h3>',
        'after_title' => '</h3>',
    ];

    register_sidebar([
        'name' => __('Primary', 'sage'),
        'id' => 'sidebar-primary',
    ] + $config);

    register_sidebar([
        'name' => __('Footer', 'sage'),
        'id' => 'sidebar-footer',
    ] + $config);
});

/**
 * Bookero performance hints — preconnect + preload on calendar pages.
 * Fires at priority 1 so hints land at the very top of <head>,
 * giving the browser maximum lead time to open connections and fetch the script.
 */
add_action('wp_head', function () {
    $has_bookero_sc   = is_singular() && has_shortcode(get_post()->post_content ?? '', 'bookero_wspolny_kalendarz');
    $listing_templates = ['template-psy-listing-pelno.blade.php', 'template-psy-listing-nisko.blade.php'];
    if (! is_singular(['psycholog', 'wydarzenia', 'warsztaty', 'grupy-wsparcia']) && ! $has_bookero_sc && ! is_page_template($listing_templates)) {
        return;
    }
    // TCP+TLS connections to Bookero domains start immediately during HTML parse
    echo '<link rel="preconnect" href="https://cdn.bookero.pl" crossorigin>' . "\n";
    echo '<link rel="preconnect" href="https://panel.bookero.pl" crossorigin>' . "\n";
    echo '<link rel="dns-prefetch" href="https://cdn.bookero.pl">' . "\n";
    echo '<link rel="dns-prefetch" href="https://panel.bookero.pl">' . "\n";
    // Preload the 1.1 MB Bookero bundle in parallel with everything else.
    // Without this the browser only discovers the script after DOMContentLoaded
    // (dynamic createElement('script') in bookero-init.js). With preload it
    // downloads during page parse — saves 300–700 ms on first visit.
    echo '<link rel="preload" as="script" href="https://cdn.bookero.pl/plugin/v2/js/bookero-compiled.js">' . "\n";
}, 1);

/**
 * Performance tweaks: disable emojis, Gutenberg CSS, Heartbeat, WP generator.
 */
add_action('init', function () {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    add_filter('tiny_mce_plugins', function ($plugins) {
        return is_array($plugins) ? array_diff($plugins, ['wpemoji']) : [];
    });
    add_filter('wp_resource_hints', function ($urls, $relation_type) {
        if ($relation_type === 'dns-prefetch') {
            $emoji_svg_url = apply_filters('emoji_svg_url', 'https://s.w.org/images/core/emoji/15.0.3/svg/');
            $urls = array_diff($urls, [$emoji_svg_url]);
        }
        return $urls;
    }, 10, 2);

    if (! is_admin()) {
        wp_deregister_script('heartbeat');
    }
});

add_action('wp_enqueue_scripts', function () {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('wc-block-style');
}, 100);

remove_action('wp_head', 'wp_generator');

/**
 * Mega menu cache invalidation — czyści transients przy zapisie posta.
 */
add_action('save_post', function () {
    delete_transient('np_mega_menu_events');
    delete_transient('np_mega_menu_posts');
});

/**
 * WooCommerce optimizer — wyłącza zbędne skrypty/style gdy WC jest aktywne.
 */
if (class_exists('WooCommerce')) {
    add_action('wp_print_scripts', function () {
        wp_dequeue_script('wc-cart-fragments');
    }, 100);

    add_action('wp_enqueue_scripts', function () {
        $scripts = ['wc-add-to-cart', 'wc-cart', 'wc-checkout', 'wc-add-to-cart-variation',
            'wc-single-product', 'woocommerce', 'prettyPhoto', 'prettyPhoto-init',
            'jquery-blockui', 'jquery-placeholder', 'jquery-payment', 'fancybox', 'jquery-cookie'];
        foreach ($scripts as $script) {
            wp_dequeue_script($script);
        }
    }, 99);

    add_filter('woocommerce_allow_marketplace_suggestions', '__return_false');
    add_filter('woocommerce_show_admin_notice_marketing', '__return_false');
    add_filter('woocommerce_helper_suppress_admin_notices', '__return_true');

    add_action('wp_dashboard_setup', function () {
        remove_meta_box('woocommerce_dashboard_status', 'dashboard', 'normal');
        remove_meta_box('woocommerce_dashboard_recent_reviews', 'dashboard', 'normal');
    }, 99);

    add_filter('woocommerce_admin_features', function ($features) {
        $idx = array_search('marketing', $features);
        if ($idx !== false) {
            unset($features[$idx]);
        }
        return $features;
    });
}
