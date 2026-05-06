<?php

/**
 * CPT: Grupy wsparcia
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('init', static function (): void {
    np_register_event_cpt(
        postType: 'grupy-wsparcia',
        singular: 'Grupa wsparcia',
        plural: 'Grupy wsparcia',
        rewriteSlug: 'grupa-wsparcia',
        menuIcon: 'dashicons-heart',
        menuPosition: 9,
    );
});
