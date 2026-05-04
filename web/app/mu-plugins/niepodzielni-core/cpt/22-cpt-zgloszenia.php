<?php

/**
 * CPT: Zgłoszenia (Form Submissions)
 *
 * Rejestruje typ postu `zgloszenie` jako readonly panel admina:
 * administrator może przeglądać i usuwać rekordy, ale nie tworzyć/edytować.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('init', 'np_register_cpt_zgloszenie');

function np_register_cpt_zgloszenie(): void
{
    register_post_type('zgloszenie', [
        'labels' => [
            'name'               => 'Zgłoszenia',
            'singular_name'      => 'Zgłoszenie',
            'all_items'          => 'Wszystkie zgłoszenia',
            'view_item'          => 'Szczegóły zgłoszenia',
            'search_items'       => 'Szukaj zgłoszeń',
            'not_found'          => 'Brak zgłoszeń',
            'not_found_in_trash' => 'Brak zgłoszeń w koszu',
        ],
        'public'            => false,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'show_in_rest'      => false,
        'has_archive'       => false,
        'supports'          => ['title'],
        'menu_icon'         => 'dashicons-email',
        'menu_position'     => 25,
        'capabilities'      => [
            'create_posts'           => 'do_not_allow',
            'edit_post'              => 'manage_options',
            'edit_posts'             => 'manage_options',
            'edit_others_posts'      => 'manage_options',
            'edit_published_posts'   => 'do_not_allow',
            'publish_posts'          => 'do_not_allow',
            'read_post'              => 'manage_options',
            'read_private_posts'     => 'manage_options',
            'delete_post'            => 'manage_options',
            'delete_posts'           => 'manage_options',
            'delete_private_posts'   => 'manage_options',
            'delete_published_posts' => 'manage_options',
            'delete_others_posts'    => 'manage_options',
        ],
    ]);
}

// ─── Read-only edit screen ────────────────────────────────────────────────────

add_action('add_meta_boxes', 'np_zgloszenie_add_metabox');

function np_zgloszenie_add_metabox(): void
{
    add_meta_box(
        'np_zgloszenie_data',
        'Dane zgłoszenia',
        'np_zgloszenie_render_metabox',
        'zgloszenie',
        'normal',
        'high',
    );
}

function np_zgloszenie_render_metabox(\WP_Post $post): void
{
    $form_id    = get_post_meta($post->ID, '_form_id', true);
    $form_data  = get_post_meta($post->ID, '_form_data', true);
    $source_url = get_post_meta($post->ID, '_source_url', true);
    $verified   = get_post_meta($post->ID, '_verified', true);

    $fields = [];
    if ($form_data) {
        $decoded = json_decode($form_data, true);
        if (is_array($decoded)) {
            $fields = $decoded;
        }
    }

    $status_label = $verified ? '<span style="color:#1a6b1a;font-weight:600;">✓ Zweryfikowane</span>' : '<span style="color:#b45309;">Oczekujące</span>';

    echo '<style>
        .np-zgl-table { width:100%; border-collapse:collapse; margin-top:8px; }
        .np-zgl-table th { text-align:left; padding:8px 12px; background:#f6f7f7; font-weight:600; border:1px solid #ddd; width:200px; }
        .np-zgl-table td { padding:8px 12px; border:1px solid #ddd; word-break:break-word; }
        .np-zgl-table tr:nth-child(even) td { background:#fafafa; }
    </style>';

    echo '<table class="np-zgl-table">';
    echo '<tr><th>Formularz</th><td>' . esc_html($form_id ?: '—') . '</td></tr>';
    echo '<tr><th>Status weryfikacji</th><td>' . $status_label . '</td></tr>';

    foreach ($fields as $key => $value) {
        $display = is_array($value) ? implode(', ', array_map('esc_html', $value)) : esc_html((string) $value);
        echo '<tr><th>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . '</th><td>' . $display . '</td></tr>';
    }

    if ($source_url) {
        echo '<tr><th>URL źródłowy</th><td><a href="' . esc_url($source_url) . '" target="_blank" rel="noopener">' . esc_html($source_url) . '</a></td></tr>';
    }

    echo '</table>';
}

// Usuń metabox "Opublikuj" (czyni ekran edycji de facto readonly).
add_action('admin_head-post.php', 'np_zgloszenie_hide_publish_box');
add_action('admin_head-post-new.php', 'np_zgloszenie_hide_publish_box');

function np_zgloszenie_hide_publish_box(): void
{
    global $post_type;
    if ($post_type !== 'zgloszenie') {
        return;
    }
    echo '<style>
        #submitdiv, #titlewrap label, .wp-heading-inline + a.page-title-action { display:none !important; }
        #title { pointer-events:none; background:#f6f7f7; color:#555; }
    </style>';
}

// ─── Kolumny listingu ─────────────────────────────────────────────────────────

add_filter('manage_zgloszenie_posts_columns', 'np_zgloszenie_columns');

function np_zgloszenie_columns(array $cols): array
{
    unset($cols['date']);
    return array_merge($cols, [
        'np_form_id' => 'Formularz',
        'np_email'   => 'E-mail',
        'np_status'  => 'Status',
        'np_date'    => 'Data',
    ]);
}

add_action('manage_zgloszenie_posts_custom_column', 'np_zgloszenie_column_values', 10, 2);

function np_zgloszenie_column_values(string $column, int $post_id): void
{
    switch ($column) {
        case 'np_form_id':
            echo esc_html(get_post_meta($post_id, '_form_id', true) ?: '—');
            break;

        case 'np_email':
            $data = json_decode((string) get_post_meta($post_id, '_form_data', true), true);
            $email = $data['email'] ?? '';
            echo $email ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '—';
            break;

        case 'np_status':
            $verified = get_post_meta($post_id, '_verified', true);
            echo $verified
                ? '<span style="color:#1a6b1a;font-weight:600;">✓ Zweryfikowane</span>'
                : '<span style="color:#b45309;">Oczekujące</span>';
            break;

        case 'np_date':
            echo esc_html(get_the_date('Y-m-d H:i', $post_id));
            break;
    }
}

add_filter('manage_edit-zgloszenie_sortable_columns', 'np_zgloszenie_sortable_columns');

function np_zgloszenie_sortable_columns(array $cols): array
{
    $cols['np_date']   = 'date';
    $cols['np_status'] = 'np_status';
    return $cols;
}
