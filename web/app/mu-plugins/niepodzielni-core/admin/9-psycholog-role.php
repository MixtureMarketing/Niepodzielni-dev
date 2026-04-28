<?php

/**
 * Custom WP role 'psycholog' + admin access redirects.
 *
 * Psycholog ma własne konto WP linkowane do CPT psycholog przez post_author.
 * Nie ma dostępu do wp-admin — kierowany jest do panelu frontendowego /panel/.
 */

if (! defined('ABSPATH')) {
    exit;
}

const NP_PSYCHOLOG_ROLE = 'psycholog';
const NP_PANEL_URL_PATH = '/panel/';
const NP_PANEL_LOGIN_PATH = '/panel/logowanie/';

add_action('init', 'np_register_psycholog_role');

function np_register_psycholog_role(): void
{
    if (get_role(NP_PSYCHOLOG_ROLE) === null) {
        add_role(NP_PSYCHOLOG_ROLE, 'Psycholog', [
            'read' => true,
        ]);
    }
}

/**
 * Blokuj wejście na wp-admin dla roli psycholog (poza AJAX).
 * Przekieruj na panel frontendowy.
 */
add_action('admin_init', function (): void {
    if (! is_user_logged_in() || wp_doing_ajax()) {
        return;
    }
    $user = wp_get_current_user();
    if (! in_array(NP_PSYCHOLOG_ROLE, (array) $user->roles, true)) {
        return;
    }
    if (current_user_can('manage_options')) {
        return; // admin też ma rolę psycholog? — nie blokuj
    }
    wp_safe_redirect(home_url(NP_PANEL_URL_PATH));
    exit;
});

/**
 * Ukryj górny pasek admina dla psychologa.
 */
add_filter('show_admin_bar', function ($show) {
    if (! is_user_logged_in()) {
        return $show;
    }
    $user = wp_get_current_user();
    if (in_array(NP_PSYCHOLOG_ROLE, (array) $user->roles, true) && ! current_user_can('manage_options')) {
        return false;
    }
    return $show;
});

/**
 * Po zalogowaniu psycholog → /panel/, nie /wp-admin/.
 */
add_filter('login_redirect', function ($redirect_to, $requested_redirect_to, $user) {
    if (! ($user instanceof WP_User)) {
        return $redirect_to;
    }
    if (in_array(NP_PSYCHOLOG_ROLE, (array) $user->roles, true) && ! user_can($user, 'manage_options')) {
        return home_url(NP_PANEL_URL_PATH);
    }
    return $redirect_to;
}, 10, 3);
