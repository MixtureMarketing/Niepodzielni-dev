<?php

/**
 * Crisis Help Hub — bootstrap.
 *
 * Tworzy term taksonomii `rodzaj-pomocy` o slugu `interwencja-kryzysowa`,
 * używany przez stronę /pomoc-w-kryzysie i mini-mapę interwencyjną.
 *
 * Eksponuje helper np_crisis_term_id(), który zwraca term_id (lub null
 * gdy term jeszcze nie istnieje — np. tuż po świeżej instalacji przed
 * wykonaniem hooka init).
 */

if (! defined('ABSPATH')) {
    exit;
}

const NP_CRISIS_TERM_SLUG = 'interwencja-kryzysowa';
const NP_CRISIS_TERM_NAME = 'Interwencja kryzysowa';

// Priorytet 20 — po np_register_cpt_osrodki (domyślnie priorytet 10).
add_action('init', 'np_crisis_ensure_term', 20);

function np_crisis_ensure_term(): void
{
    if (! taxonomy_exists('rodzaj-pomocy')) {
        return;
    }

    if (term_exists(NP_CRISIS_TERM_SLUG, 'rodzaj-pomocy')) {
        return;
    }

    wp_insert_term(NP_CRISIS_TERM_NAME, 'rodzaj-pomocy', [
        'slug'        => NP_CRISIS_TERM_SLUG,
        'description' => 'Ośrodki interwencji kryzysowej, telefony zaufania, oddziały psychiatryczne — pokazywane na stronie /pomoc-w-kryzysie.',
    ]);
}

/**
 * Zwraca ID termu „interwencja-kryzysowa" lub null.
 *
 * Pamiętaj: helper musi być wywoływany po hooku init (priorytet >= 20),
 * inaczej term jeszcze nie istnieje i funkcja zwróci null.
 */
function np_crisis_term_id(): ?int
{
    static $cached = null;

    if ($cached !== null) {
        return $cached === 0 ? null : $cached;
    }

    $term = get_term_by('slug', NP_CRISIS_TERM_SLUG, 'rodzaj-pomocy');
    $cached = ($term instanceof \WP_Term) ? (int) $term->term_id : 0;

    return $cached === 0 ? null : $cached;
}
