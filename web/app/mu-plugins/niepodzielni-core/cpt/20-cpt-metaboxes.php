<?php

/**
 * Metaboxes — pola dla wszystkich CPT (fallback gdy ACF nie jest aktywny)
 * Jeśli ACF jest aktywny, pola są zarządzane przez ACF — ten plik jest pomijany.
 */

if (! defined('ABSPATH')) {
    exit;
}

// Jeśli ACF aktywny — nie rejestrujemy własnych metaboxów
if (function_exists('acf_add_local_field_group')) {
    return;
}

add_action('add_meta_boxes', 'np_register_metaboxes');
add_action('save_post', 'np_save_metaboxes');

function np_register_metaboxes(): void
{
    // Psycholog
    add_meta_box(
        'np_psycholog_meta',
        'Dane psychologa',
        'np_render_psycholog_metabox',
        'psycholog',
        'normal',
        'high',
    );
}

function np_render_psycholog_metabox(WP_Post $post): void
{
    wp_nonce_field('np_psycholog_meta_nonce', 'np_psycholog_meta_nonce');
    $fields = [
        'imie_i_nazwisko'         => 'Imię i nazwisko',
        'biogram'                 => 'Biogram',
        'bookero_id_pelny'        => 'Bookero ID (pełnopłatne)',
        'bookero_id_niski'        => 'Bookero ID (niskoplatne)',
        'stawka_wysokoplatna'     => 'Stawka (pełnopłatna)',
        'stawka_niskoplatna'      => 'Stawka (niskopłatna)',
        'swiadczy_pelnoplatne'    => 'Świadczy pełnopłatne (yes/no)',
        'swiadczy_niskoplatne'    => 'Świadczy niskopłatne (yes/no)',
        'rodzaj_wizyty'           => 'Rodzaj wizyty',
        'tryb_konsultacji_info'   => 'Tryb konsultacji (info)',
    ];
    echo '<table class="form-table">';
    foreach ($fields as $key => $label) {
        $val = get_post_meta($post->ID, $key, true);
        echo "<tr><th><label for='{$key}'>{$label}</label></th>";
        echo "<td><input type='text' id='{$key}' name='{$key}' value='" . esc_attr($val) . "' class='widefat'></td></tr>";
    }
    echo '</table>';
}

function np_save_metaboxes(int $post_id): void
{
    if (! isset($_POST['np_psycholog_meta_nonce'])) {
        return;
    }
    if (! wp_verify_nonce($_POST['np_psycholog_meta_nonce'], 'np_psycholog_meta_nonce')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    $fields = [
        'imie_i_nazwisko', 'biogram', 'bookero_id_pelny', 'bookero_id_niski',
        'stawka_wysokoplatna', 'stawka_niskoplatna', 'swiadczy_pelnoplatne',
        'swiadczy_niskoplatne', 'rodzaj_wizyty', 'tryb_konsultacji_info',
    ];
    foreach ($fields as $key) {
        if (isset($_POST[ $key ])) {
            update_post_meta($post_id, $key, sanitize_text_field($_POST[ $key ]));
        }
    }
}
