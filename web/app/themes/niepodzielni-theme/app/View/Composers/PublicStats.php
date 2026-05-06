<?php

declare(strict_types=1);

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

/**
 * Wall of impact — agregaty fundacji.
 *
 * Composer wywoływany TYLKO dla widoku partials.wall-of-impact.
 * Cache 1h w object cache (Redis); klucz i invalidator współdzielone
 * z mu-plugin api/71-public-stats.php.
 */
class PublicStats extends Composer
{
    /** @var string[] */
    protected static $views = ['partials.wall-of-impact'];

    private const CACHE_KEY   = 'np_public_stats';
    private const CACHE_GROUP = 'np_stats';
    private const CACHE_TTL   = HOUR_IN_SECONDS;

    /**
     * @return array{stats: array{
     *   psychologists: int,
     *   articles: int,
     *   support_groups_this_month: int,
     *   reviews_count: int,
     *   avg_rating: float|null,
     * }}
     */
    public function with(): array
    {
        return ['stats' => $this->stats()];
    }

    /**
     * @return array{
     *   psychologists: int,
     *   articles: int,
     *   support_groups_this_month: int,
     *   reviews_count: int,
     *   avg_rating: float|null,
     * }
     */
    public function stats(): array
    {
        $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        $stats = [
            'psychologists'             => $this->countPsychologists(),
            'articles'                  => $this->countArticles(),
            'support_groups_this_month' => $this->countSupportGroupsThisMonth(),
            'reviews_count'             => $this->countReviews(),
            'avg_rating'                => $this->avgRating(),
        ];

        wp_cache_set(self::CACHE_KEY, $stats, self::CACHE_GROUP, self::CACHE_TTL);

        return $stats;
    }

    private function countPsychologists(): int
    {
        $counts = wp_count_posts('psycholog');
        return (int) ($counts->publish ?? 0);
    }

    private function countArticles(): int
    {
        // Tymczasowo liczymy posty (post_type=post) — gdy CPT artykul_psychoedu
        // wejdzie w T2/#7, podmienić na 'artykul_psychoedu'.
        $counts = wp_count_posts('post');
        $articles = (int) ($counts->publish ?? 0);

        if (post_type_exists('artykul_psychoedu')) {
            $extra = wp_count_posts('artykul_psychoedu');
            $articles += (int) ($extra->publish ?? 0);
        }

        return $articles;
    }

    private function countSupportGroupsThisMonth(): int
    {
        if (! post_type_exists('grupy-wsparcia')) {
            return 0;
        }

        $monthStart = strtotime('first day of this month 00:00:00') ?: time();
        $monthEnd   = strtotime('first day of next month 00:00:00') ?: time();

        $query = new \WP_Query([
            'post_type'              => 'grupy-wsparcia',
            'post_status'            => 'publish',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => [
                [
                    'key'     => 'data',
                    'value'   => [date('Y-m-d', $monthStart), date('Y-m-d', $monthEnd - 1)],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
        ]);

        $count = (int) $query->found_posts;

        // Fallback: jeśli żadne posty nie mają meta `data`, zwróć łączną liczbę
        // bieżąco-publikowanych grup zamiast 0 — pozwala statystyce mieć sens
        // od dnia 1 nawet bez wypełnionej meta.
        if ($count === 0) {
            $counts = wp_count_posts('grupy-wsparcia');
            $count = (int) ($counts->publish ?? 0);
        }

        return $count;
    }

    private function countReviews(): int
    {
        global $wpdb;
        $sum = $wpdb->get_var(
            "SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)), 0)
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_reviews_count'"
        );
        return (int) $sum;
    }

    private function avgRating(): ?float
    {
        global $wpdb;
        $avg = $wpdb->get_var(
            "SELECT AVG(CAST(meta_value AS DECIMAL(3,1)))
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_average_rating'
               AND CAST(meta_value AS DECIMAL(3,1)) > 0"
        );

        if ($avg === null || $avg === '') {
            return null;
        }

        return round((float) $avg, 1);
    }
}
