<?php

namespace App\Services;

/**
 * Serwis danych listingów wydarzeń.
 *
 * Cztery typy listingów (warsztaty+grupy / wydarzenia / aktualności / psychoedukacja)
 * dzielą:
 *   - dwuwarstwowy cache: WP Object Cache (Redis) → Transient (1h TTL)
 *   - bazowy rekord (id, title, link, thumb, thumb_tag, excerpt)
 *   - WP_Query z bulk meta + term cache (eliminacja N+1)
 *
 * Wspólne mechanizmy w {@see AbstractListingService}; każdy listing dokleja własne
 * pola w `$mapper` przekazanym do `buildList()`.
 */
class EventsListingService extends AbstractListingService
{
    private const CACHE_GROUP = 'np_events_listing';

    /** Pełna mapa cache_key → transient_key — używana przez {@see clearCache()}. */
    private const CACHE_MAP = [
        'workshops'           => ['np_workshops_listing',     ['warsztaty', 'grupy-wsparcia']],
        'wydarzenia'          => ['np_wydarzenia_listing',    ['wydarzenia']],
        'aktualnosci'         => ['np_aktualnosci_listing',   ['aktualnosci']],
        'psychoedukacja'      => ['np_psychoedukacja_listing', ['post']],
        'psychoedukacja_tags' => ['np_psychoedukacja_tags',    ['post']],
    ];

    /**
     * Wrapper na {@see AbstractListingService::withCache()} — używa per-service
     * cache group i 1h TTL, mapując krótki klucz na transient z {@see CACHE_MAP}.
     *
     * @template T of array
     * @param callable(): T $builder
     * @return T
     */
    private function cached(string $cacheKey, callable $builder): array
    {
        $transientKey = self::CACHE_MAP[$cacheKey][0]
            ?? throw new \InvalidArgumentException("Unknown cache key: {$cacheKey}");

        return $this->withCache($cacheKey, $transientKey, self::CACHE_GROUP, HOUR_IN_SECONDS, $builder);
    }

    // ─── 1. Warsztaty + Grupy wsparcia ──────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWorkshopsData(): array
    {
        return $this->cached('workshops', function (): array {
            $today = current_time('Y-m-d');

            // Prefetch meta prowadzących (psycholog CPT) — eliminuje N+1 w np_get_event_leader_name().
            // Robimy najpierw szybki query po IDs, potem warmup postmeta cache, potem właściwy query.
            $idsQuery = new \WP_Query([
                'post_type'              => ['warsztaty', 'grupy-wsparcia'],
                'posts_per_page'         => -1,
                'post_status'            => 'publish',
                'no_found_rows'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
                'fields'                 => 'ids',
            ]);

            $facIds = array_values(array_unique(array_filter(
                array_map(static fn(int $pid) => (int) get_post_meta($pid, 'prowadzacy_id', true), $idsQuery->posts),
            )));
            if ($facIds) {
                update_postmeta_cache($facIds);
            }

            return $this->buildList(
                ['post_type' => ['warsztaty', 'grupy-wsparcia']],
                function (\WP_Post $post) use ($today): array {
                    $pid   = $post->ID;
                    $date  = (string) get_post_meta($pid, 'data', true);
                    $title = (string) get_post_meta($pid, 'temat', true) ?: $post->post_title;

                    // Etap 3 refactoru: ujednolicone klucze (godzina_rozpoczecia/cena).
                    // Fallback na stare klucze (godzina) na czas migracji DB.
                    return $this->commonRecord($post, ['zdjecie_glowne', 'zdjecie'], $title) + [
                        'post_type'   => $post->post_type,
                        'date'        => $date,
                        'time'        => get_post_meta($pid, 'godzina_rozpoczecia', true)
                            ?: get_post_meta($pid, 'godzina', true),
                        'time_end'    => get_post_meta($pid, 'godzina_zakonczenia', true),
                        'lokalizacja' => get_post_meta($pid, 'lokalizacja', true),
                        'status'      => get_post_meta($pid, 'status', true),
                        'cena'        => get_post_meta($pid, 'cena', true),
                        'cena_rodzaj' => get_post_meta($pid, 'cena_rodzaj', true),
                        'prowadzacy'  => np_get_event_leader_name($pid),
                        'stanowisko'  => get_post_meta($pid, 'stanowisko', true),
                        'is_active'   => $date !== '' && $date >= $today,
                        'sort_date'   => $date !== '' ? $date : '9999-12-31',
                    ];
                },
                self::sortByDate(...),
            );
        });
    }

    // ─── 2. Wydarzenia ──────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWydarzeniaData(): array
    {
        return $this->cached('wydarzenia', function (): array {
            $today = current_time('Y-m-d');

            return $this->buildList(
                ['post_type' => 'wydarzenia'],
                function (\WP_Post $post) use ($today): array {
                    $pid  = $post->ID;
                    $date = (string) get_post_meta($pid, 'data', true);

                    // Etap 3 refactoru: ujednolicony klucz `cena`.
                    // Fallback na stary `koszt` na czas migracji DB.
                    // 'koszt' w wyniku zachowane dla backward-compat z blade card (variant=event).
                    $cena = get_post_meta($pid, 'cena', true) ?: get_post_meta($pid, 'koszt', true);

                    return $this->commonRecord($post, ['zdjecie', 'zdjecie_tla']) + [
                        'date'        => $date,
                        'time_start'  => get_post_meta($pid, 'godzina_rozpoczecia', true),
                        'time_end'    => get_post_meta($pid, 'godzina_zakonczenia', true),
                        'miasto'      => get_post_meta($pid, 'miasto', true),
                        'lokalizacja' => get_post_meta($pid, 'lokalizacja', true),
                        'cena'        => $cena,
                        'koszt'       => $cena,
                        'opis'        => wp_trim_words((string) get_post_meta($pid, 'opis', true) ?: $post->post_excerpt, 20),
                        'is_upcoming' => $date !== '' && $date >= $today,
                        'sort_date'   => $date !== '' ? $date : '9999-12-31',
                    ];
                },
                self::sortByDate(...),
            );
        });
    }

    // ─── 3. Aktualności ─────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAktualnosciData(): array
    {
        return $this->cached('aktualnosci', function (): array {
            return $this->buildList(
                [
                    'post_type' => 'aktualnosci',
                    'orderby'   => 'date',
                    'order'     => 'DESC',
                ],
                fn(\WP_Post $post): array => $this->commonRecord($post, ['zdjecie_glowne']) + [
                    'date'    => get_post_meta($post->ID, 'data_wydarzenia', true) ?: get_the_date('Y-m-d', $post),
                    'miejsce' => get_post_meta($post->ID, 'miejsce', true),
                ],
            );
        });
    }

    // ─── 4. Psychoedukacja ──────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPsychoedukacjaData(): array
    {
        return $this->cached('psychoedukacja', function (): array {
            return $this->buildList(
                [
                    'post_type'              => 'post',
                    'orderby'                => 'date',
                    'order'                  => 'DESC',
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => true,
                ],
                fn(\WP_Post $post): array => $this->commonRecord($post) + [
                    'date' => get_the_date('Y-m-d', $post),
                    'tags' => array_map(static fn($t) => $t->slug, get_the_tags($post->ID) ?: []),
                ],
            );
        });
    }

    /**
     * Zwraca tagi do zakładek na stronie Psychoedukacja (tylko niepuste).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getPsychoedukacjaTags(): array
    {
        return $this->cached('psychoedukacja_tags', function (): array {
            $tags = get_tags(['hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC']);

            return array_map(static fn($t) => ['value' => $t->slug, 'label' => $t->name], $tags);
        });
    }

    // ─── Cache invalidation ─────────────────────────────────────────────────────

    /**
     * Czyści transient i Object Cache dla typu posta który został zapisany.
     * Wywoływana przez hook save_post zarejestrowany poniżej.
     */
    public static function clearCache(int $post_id): void
    {
        if (wp_is_post_revision($post_id)) {
            return;
        }

        $postType = get_post_type($post_id);
        if ($postType === false) {
            return;
        }

        foreach (self::CACHE_MAP as $cacheKey => [$transientKey, $matchPostTypes]) {
            if (in_array($postType, $matchPostTypes, true)) {
                delete_transient($transientKey);
                wp_cache_delete($cacheKey, self::CACHE_GROUP);
            }
        }
    }
}

// ─── Hooki czyszczenia cache ─────────────────────────────────────────────────

add_action('save_post', [EventsListingService::class, 'clearCache']);
