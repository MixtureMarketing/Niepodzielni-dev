<?php

/**
 * CPT: Wydarzenia
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('init', static function (): void {
    np_register_event_cpt(
        postType: 'wydarzenia',
        singular: 'Wydarzenie',
        plural: 'Wydarzenia',
        rewriteSlug: 'wydarzenie',
        menuIcon: 'dashicons-calendar-alt',
        menuPosition: 7,
    );
});
