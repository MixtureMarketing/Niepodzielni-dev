<?php
/**
 * Matchmaker Shortcode — dopasowanie psychologa do potrzeb użytkownika
 * Shortcode: [np_matchmaker]
 */

if (! defined('ABSPATH')) {
    exit;
}

add_shortcode('np_matchmaker', 'np_shortcode_matchmaker');

function np_shortcode_matchmaker(array $atts): string
{
    $atts = shortcode_atts([
        'typ' => 'pelnoplatne', // 'pelnoplatne' | 'niskoplatne'
    ], $atts);

    ob_start();
    ?>
    <div class="np-matchmaker" data-typ="<?= esc_attr($atts['typ']) ?>">
        <div class="np-matchmaker__step" data-step="1">
            <h3>Czego potrzebujesz?</h3>
            <?php
            $obszary = get_terms([ 'taxonomy' => 'obszar-pomocy', 'hide_empty' => false ]);
    if (! is_wp_error($obszary) && $obszary) :
        ?>
            <div class="np-matchmaker__options">
                <?php foreach ($obszary as $term) : ?>
                <button class="np-matchmaker__option" data-value="<?= esc_attr($term->slug) ?>">
                    <?= esc_html($term->name) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="np-matchmaker__results" style="display:none;"></div>
    </div>
    <?php
    return ob_get_clean();
}
