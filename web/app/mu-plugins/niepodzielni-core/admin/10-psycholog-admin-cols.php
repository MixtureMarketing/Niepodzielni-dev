<?php

/**
 * Kolumna "Konto" na liście psychologów (edit.php?post_type=psycholog).
 *
 * Pokazuje status konta WP powiązanego z psychologiem:
 *   ⚠ — brak emaila w meta
 *   ✗ — email jest, ale nie ma konta WP z tym mailem
 *   ✓ — email + konto WP istnieje
 */

if (! defined('ABSPATH')) {
    exit;
}

add_filter('manage_psycholog_posts_columns', function (array $cols): array {
    // Wstaw kolumnę "Konto" za tytułem
    $new = [];
    foreach ($cols as $key => $label) {
        $new[ $key ] = $label;
        if ($key === 'title') {
            $new['np_konto'] = 'Konto';
        }
    }
    return $new;
});

add_action('manage_psycholog_posts_custom_column', function (string $column, int $post_id): void {
    if ($column !== 'np_konto') {
        return;
    }
    $email = (string) get_post_meta($post_id, 'email_kontaktowy', true);

    if ($email === '') {
        echo '<span title="Brak emaila — wpisz w polu \'Email logowania\' aby móc utworzyć konto" style="color:#d97706">⚠ brak emaila</span>';
        return;
    }

    $user = get_user_by('email', $email);
    if (! $user) {
        echo '<span style="color:#dc2626" title="Email jest, ale konto WP jeszcze nie zostało utworzone">✗ konto nieutworzone</span>';
        echo '<br><small style="color:#666">' . esc_html($email) . '</small>';
        return;
    }

    $url = admin_url('user-edit.php?user_id=' . (int) $user->ID);
    echo '<span style="color:#16a34a">✓</span> <a href="' . esc_url($url) . '">' . esc_html($email) . '</a>';
}, 10, 2);
