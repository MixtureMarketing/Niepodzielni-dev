<?php

/**
 * CPT: Warsztaty
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('init', static function (): void {
    np_register_event_cpt(
        postType: 'warsztaty',
        singular: 'Warsztat',
        plural: 'Warsztaty',
        rewriteSlug: 'warsztat',
        menuIcon: 'dashicons-groups',
        menuPosition: 8,
    );
});
