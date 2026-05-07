<?php

namespace App\Services;

/**
 * Bazowa klasa serwisów listingów (psychologów, wydarzeń, warsztatów, …).
 *
 * Eliminuje duplikację 3 mechanizmów współdzielonych przez wszystkie listingi:
 *   1. Dwuwarstwowy cache: WP Object Cache (L0/Redis) → Transient (L1/DB)
 *   2. WP_Query z bulk meta + term cache (eliminacja N+1)
 *   3. Bazowy rekord (id/title/link/thumb/thumb_tag/excerpt)
 *
 * Klasy potomne dostarczają per-listing query args + mapper i (opcjonalnie) sorter.
 */
abstract class AbstractListingService
{
    /**
     * Dwuwarstwowy cache: zwraca zcache'owany wynik lub buduje go i zapisuje w obu warstwach.
     *
     * @template T of array
     * @param string       $cacheKey     klucz Object Cache
     * @param string       $transientKey klucz transient (DB)
     * @param string       $cacheGroup   grupa Object Cache
     * @param int          $ttl          TTL (sekundy) dla obu warstw
     * @param callable(): T $builder
     * @return T
     */
    protected function withCache(
        string $cacheKey,
        string $transientKey,
        string $cacheGroup,
        int $ttl,
        callable $builder,
    ): array {
        $cached = wp_cache_get($cacheKey, $cacheGroup);
        if (is_array($cached)) {
            return $cached;
        }

        $cached = get_transient($transientKey);
        if (is_array($cached)) {
            wp_cache_set($cacheKey, $cached, $cacheGroup, $ttl);

            return $cached;
        }

        $data = $builder();

        set_transient($transientKey, $data, $ttl);
        wp_cache_set($cacheKey, $data, $cacheGroup, $ttl);

        return $data;
    }

    /**
     * Wspólne pola listingowe (id/title/link/thumb/thumb_tag/excerpt).
     *
     * Klasy potomne dorzucają własne pola przez tablicowy `+` merge.
     *
     * @param array<int, string> $thumbMetaKeys lista kluczy Carbon Fields dla obrazka
     * @return array<string, mixed>
     */
    protected function commonRecord(\WP_Post $post, array $thumbMetaKeys = [], string $title = ''): array
    {
        $title = $title !== '' ? $title : $post->post_title;

        return [
            'id'        => $post->ID,
            'title'     => $title,
            'link'      => get_the_permalink($post->ID),
            'thumb'     => np_get_post_image_url($post->ID, $thumbMetaKeys, 'medium_large')
                ?: (string) get_the_post_thumbnail_url($post->ID, 'medium_large'),
            'thumb_tag' => np_get_post_image_tag($post->ID, $thumbMetaKeys, 'medium_large', ['alt' => $title]),
            'excerpt'   => $post->post_excerpt ?: wp_trim_words($post->post_content, 20),
        ];
    }

    /**
     * Wspólny pipeline: WP_Query → mapowanie → opcjonalny sort.
     *
     * Domyślnie ładuje meta cache (eliminacja N+1) — klasa potomna może override'ować
     * przez `$queryArgs` (np. `update_post_term_cache => true`).
     *
     * @param array<string, mixed>                          $queryArgs   override WP_Query
     * @param callable(\WP_Post): array<string, mixed>      $mapper
     * @param ?callable(array<string, mixed>, array<string, mixed>): int $sorter
     * @return array<int, array<string, mixed>>
     */
    protected function buildList(array $queryArgs, callable $mapper, ?callable $sorter = null): array
    {
        $defaults = [
            'posts_per_page'         => -1,
            'post_status'            => 'publish',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ];

        $query = new \WP_Query(array_merge($defaults, $queryArgs));

        $data = array_map($mapper, $query->posts);

        if ($sorter !== null) {
            usort($data, $sorter);
        }

        return $data;
    }

    /**
     * Comparator po polu `sort_date` (ASC). Używany jako default sorter dla list zdarzeń.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    protected static function sortByDate(array $a, array $b): int
    {
        return strcmp((string) ($a['sort_date'] ?? ''), (string) ($b['sort_date'] ?? ''));
    }
}
