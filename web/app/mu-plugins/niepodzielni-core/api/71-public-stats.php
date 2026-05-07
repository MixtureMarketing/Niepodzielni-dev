<?php

/**
 * Public Stats — Wall of impact.
 *
 * Agreguje liczby fundacji (psycholodzy, artykuły, grupy wsparcia, opinie)
 * dla widgetu na stronie głównej i /o-nas. Realna logika agregacji żyje
 * w View Composerze App\View\Composers\PublicStats — tu trzymamy tylko
 * helpery invalidacji cache, podpięte do hooków zapisu, oraz wspólny
 * klucz cache, żeby nie powstał drift między composerem a invalidatorem.
 *
 * Cache key: 'np_public_stats' / group: 'np_stats' / TTL: 1h.
 */

if (! defined('ABSPATH')) {
    exit;
}

const NP_PUBLIC_STATS_CACHE_KEY   = 'np_public_stats';
const NP_PUBLIC_STATS_CACHE_GROUP = 'np_stats';

/**
 * Czyści cache statystyk publicznych. Wywoływane przez hooki zapisu.
 */
function np_public_stats_invalidate(): void
{
    wp_cache_delete(NP_PUBLIC_STATS_CACHE_KEY, NP_PUBLIC_STATS_CACHE_GROUP);
}

// Inwalidacja przy zapisie postów wpływających na liczniki.
add_action('save_post_psycholog', 'np_public_stats_invalidate');
add_action('save_post_grupy-wsparcia', 'np_public_stats_invalidate');
add_action('save_post_artykul_psychoedu', 'np_public_stats_invalidate');
add_action('save_post_post', 'np_public_stats_invalidate');

// Recenzje wpływają na średnią ocenę — invalidate przy zmianach.
add_action('wp_insert_comment', 'np_public_stats_invalidate_on_comment', 10, 2);
add_action('transition_comment_status', 'np_public_stats_invalidate_on_status', 10, 3);
add_action('deleted_comment', 'np_public_stats_invalidate');

/**
 * @param int      $id
 * @param \WP_Comment|object $comment
 */
function np_public_stats_invalidate_on_comment(int $id, $comment): void
{
    if (isset($comment->comment_type) && $comment->comment_type === 'review') {
        np_public_stats_invalidate();
    }
}

/**
 * @param string     $newStatus
 * @param string     $oldStatus
 * @param \WP_Comment|object $comment
 */
function np_public_stats_invalidate_on_status(string $newStatus, string $oldStatus, $comment): void
{
    if (isset($comment->comment_type) && $comment->comment_type === 'review') {
        np_public_stats_invalidate();
    }
}
