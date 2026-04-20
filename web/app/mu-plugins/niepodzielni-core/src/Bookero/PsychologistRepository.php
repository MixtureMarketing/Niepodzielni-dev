<?php

declare(strict_types=1);

namespace Niepodzielni\Bookero;

/**
 * Repozytorium psychologa — warstwa dostępu do danych.
 *
 * Odpowiada WYŁĄCZNIE za operacje na bazie danych WordPress:
 * postmeta (worker IDs, sloty, godziny, timestamp) i transienty
 * (cache getMonth, getMonthDay, konfiguracja konta).
 *
 * Klucze postmeta i transientów w jednym miejscu — zero duplikacji w kodzie.
 */
class PsychologistRepository
{
    // ─── TTL ──────────────────────────────────────────────────────────────────────
    private const MONTH_TTL   = 5 * MINUTE_IN_SECONDS;    // cache wyników getMonth
    private const CONFIG_TTL  = 24 * HOUR_IN_SECONDS;     // cache konfiguracji konta /init
    private const BACKOFF_TTL = 2 * MINUTE_IN_SECONDS;    // krótki cooldown po błędzie HTTP 429

    // ─── Worker IDs ───────────────────────────────────────────────────────────────

    /**
     * Zwraca worker ID psychologa dla danego typu konta.
     * Pusty string gdy psycholog nie ma konta danego typu.
     *
     * @param string $typ  'pelnoplatny' | 'nisko'
     */
    public function getWorkerId(int $postId, string $typ): string
    {
        $metaKey = $this->isNisko($typ) ? 'bookero_id_niski' : 'bookero_id_pelny';
        return (string) get_post_meta($postId, $metaKey, true);
    }

    // ─── Dostępność terminów ──────────────────────────────────────────────────────

    /**
     * Zapisuje posortowaną listę dostępnych dat do postmeta.
     *
     * @param string[] $dates  Daty w formacie YYYY-MM-DD
     * @param int      $postId Post ID psychologa
     * @param string   $typ    'pelnoplatny' | 'nisko'
     */
    public function saveAvailableDates(int $postId, string $typ, array $dates): void
    {
        $metaKey = $this->isNisko($typ) ? 'bookero_slots_nisko' : 'bookero_slots_pelno';
        update_post_meta($postId, $metaKey, wp_json_encode(array_values($dates)));
    }

    /**
     * Zapisuje sformatowaną datę najbliższego terminu (np. "15 maja 2026").
     */
    public function saveNearestDate(int $postId, string $typ, string $nearestLabel): void
    {
        $metaKey = $this->nearestDateMetaKey($typ);
        update_post_meta($postId, $metaKey, $nearestLabel);
    }

    /**
     * Usuwa klucz najbliższego terminu — wywoływane gdy brak wolnych slotów.
     * Zapobiega wyświetlaniu nieaktualnych danych po wygaśnięciu dostępności.
     */
    public function clearNearestDate(int $postId, string $typ): void
    {
        delete_post_meta($postId, $this->nearestDateMetaKey($typ));
    }

    /**
     * Aktualizuje timestamp ostatniej synchronizacji.
     */
    public function touchSyncTimestamp(int $postId): void
    {
        update_post_meta($postId, 'np_termin_updated_at', time());
    }

    // ─── Cache godzin (DB-level) ──────────────────────────────────────────────────

    /**
     * Zwraca godziny z cache DB dla psychologa w danym dniu.
     *
     * @return string[]|null  Lista godzin, null gdy brak w cache, [] gdy zsynchronizowane/brak miejsc
     */
    public function getCachedHours(int $postId, string $typ, string $date): ?array
    {
        $metaKey = $this->isNisko($typ) ? 'bookero_hours_nisko' : 'bookero_hours_pelno';
        $json    = get_post_meta($postId, $metaKey, true);

        if (! $json) {
            return null;
        }

        $map = json_decode($json, true);
        if (! is_array($map)) {
            return null;
        }

        // null = brak w cache; [] = zsynchronizowane, brak dostępności
        return array_key_exists($date, $map) ? (array) $map[ $date ] : null;
    }

    /**
     * Zapisuje godziny do cache DB. Przy okazji czyści przeszłe daty.
     *
     * @param int      $postId Post ID psychologa
     * @param string   $typ    'pelnoplatny' | 'nisko'
     * @param string   $date   Data YYYY-MM-DD
     * @param string[] $hours  Lista godzin, np. ['10:00', '11:00']
     */
    public function saveHours(int $postId, string $typ, string $date, array $hours): void
    {
        $metaKey = $this->isNisko($typ) ? 'bookero_hours_nisko' : 'bookero_hours_pelno';
        $json    = get_post_meta($postId, $metaKey, true);
        $map     = $json ? json_decode($json, true) : [];

        if (! is_array($map)) {
            $map = [];
        }

        $map[ $date ] = array_values($hours);

        // Automatyczne czyszczenie starych dat — bez tego mapa rośnie bez ograniczeń
        $today = date('Y-m-d');
        foreach (array_keys($map) as $d) {
            if ($d < $today) {
                unset($map[ $d ]);
            }
        }

        update_post_meta($postId, $metaKey, wp_json_encode($map));
    }

    // ─── Transienty: getMonth ─────────────────────────────────────────────────────

    /**
     * @return array<array{date: string, hour: string}>|false  False gdy brak w cache
     */
    public function getMonthTransient(string $typ, string $workerId, int $plusMonths): array|false
    {
        $cached = get_transient($this->monthCacheKey($typ, $workerId, $plusMonths));
        return is_array($cached) ? $cached : false;
    }

    /**
     * @param array<array{date: string, hour: string}> $slots
     */
    public function setMonthTransient(string $typ, string $workerId, int $plusMonths, array $slots): void
    {
        set_transient(
            $this->monthCacheKey($typ, $workerId, $plusMonths),
            $slots,
            self::MONTH_TTL,
        );
    }

    /**
     * Krótki backoff po błędzie API — zapobiega thundering herd po HTTP 429.
     * Zapisuje pustą tablicę z TTL=2min, żeby następny cron nie odpytał od razu.
     */
    public function setMonthTransientBackoff(string $typ, string $workerId, int $plusMonths): void
    {
        set_transient(
            $this->monthCacheKey($typ, $workerId, $plusMonths),
            [],
            self::BACKOFF_TTL,
        );
    }

    /**
     * Czyści transienty getMonth dla N miesięcy do przodu — używane przy ręcznym odświeżeniu.
     */
    public function clearMonthTransients(string $workerId, string $typ, int $monthCount = 3): void
    {
        for ($i = 0; $i < $monthCount; $i++) {
            delete_transient($this->monthCacheKey($typ, $workerId, $i));
        }
    }

    // ─── Transienty: konfiguracja konta ──────────────────────────────────────────

    /**
     * @return array{service_id: int, service_name: string, payment_id: int}|false
     */
    public function getAccountConfigTransient(string $typ): array|false
    {
        $cached = get_transient($this->configCacheKey($typ));
        if (! is_array($cached)) {
            return false;
        }
        /** @var array{service_id: int, service_name: string, payment_id: int} $cached */
        return $cached;
    }

    /**
     * Cache konfiguracji tylko gdy service_id > 0 (ochrona przed cachowaniem pustych odpowiedzi).
     */
    public function setAccountConfigTransient(string $typ, AccountConfig $config): void
    {
        if ($config->serviceId > 0) {
            set_transient($this->configCacheKey($typ), $config->toArray(), self::CONFIG_TTL);
        }
    }

    // ─── Budowniczowie kluczy cache (publiczne — używane przez zewnętrzny kod) ────

    /**
     * Klucz transienta dla wyników getMonth.
     * Kompatybilny ze starym np_bookero_get_terminy() — ten sam hash.
     */
    public function monthCacheKey(string $typ, string $workerId, int $plusMonths): string
    {
        return 'np_bk_' . md5($typ . $workerId) . '_m' . $plusMonths;
    }

    /**
     * Klucz transienta dla wyników getMonthDay.
     * Kompatybilny ze starym np_bookero_get_month_day() — ten sam hash.
     */
    public function dayCacheKey(string $typ, string $workerId, string $date): string
    {
        return 'np_bkday_' . md5($typ . $workerId . $date);
    }

    /**
     * Klucz transienta dla konfiguracji konta.
     * Kompatybilny ze starym np_bookero_get_account_config() — ten sam klucz.
     */
    public function configCacheKey(string $typ): string
    {
        return 'np_bk_cfg_' . sanitize_key($typ);
    }

    // ─── Batch load — eliminacja N+1 ─────────────────────────────────────────────

    // ─── Object Cache — lista pracowników ────────────────────────────────────────

    private const WORKERS_CACHE_GROUP = 'np_bookero';
    private const WORKERS_CACHE_TTL   = 7 * MINUTE_IN_SECONDS;  // 7 min — między 5-10 jak zalecono

    /**
     * Inwaliduje WP Object Cache listy pracowników dla danego typu.
     *
     * Wołaj przy każdej zmianie postmeta psychologa: save_post_psycholog,
     * niepodzielni_bookero_batch_synced, ręczna synchronizacja z panelu admina.
     */
    public function invalidateWorkersCache(string $typ): void
    {
        wp_cache_delete($this->workersCacheKey($typ), self::WORKERS_CACHE_GROUP);
    }

    private function workersCacheKey(string $typ): string
    {
        return 'workers_' . ($this->isNisko($typ) ? 'nisko' : 'pelno');
    }

    // ─── Batch load — eliminacja N+1 ─────────────────────────────────────────────

    /**
     * Pobiera aktywnych psychologów dla danej daty (SQL-level filtering).
     *
     * Bolt Optimization: Uses MySQL 8.0 JSON_CONTAINS to fetch only relevant workers.
     * This avoids loading and filtering all workers in PHP memory.
     *
     * @return array<int, WorkerRecord>
     */
    public function findByAvailableDate(string $typ, string $date): array
    {
        global $wpdb;

        $isNisko   = $this->isNisko($typ);
        $metaBkKey = $isNisko ? 'bookero_id_niski' : 'bookero_id_pelny';
        $metaSlots = $isNisko ? 'bookero_slots_nisko' : 'bookero_slots_pelno';

        // 1. Fetch IDs of psychologists available on $date using JSON_CONTAINS
        // We use $wpdb->prepare for safety, though $date is validated as YYYY-MM-DD.
        $jsonDate = '"' . $date . '"';
        $pids     = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND JSON_CONTAINS(meta_value, %s)",
                $metaSlots,
                $jsonDate,
            ),
        );

        if (empty($pids)) {
            return [];
        }

        // 2. Fetch full objects using getAllWorkersWithMeta but limited to these IDs.
        // We modify the query to include only these IDs.
        return $this->getWorkersByIds($typ, array_map('intval', $pids));
    }

    /**
     * @param int[] $pids
     * @return array<int, WorkerRecord>
     */
    public function getWorkersByIds(string $typ, array $pids): array
    {
        if (empty($pids)) {
            return [];
        }

        $isNisko     = $this->isNisko($typ);
        $metaBkKey   = $isNisko ? 'bookero_id_niski' : 'bookero_id_pelny';
        $metaSlots   = $isNisko ? 'bookero_slots_nisko' : 'bookero_slots_pelno';
        $metaTerm    = $isNisko ? 'najblizszy_termin_niskoplatny' : 'najblizszy_termin_pelnoplatny';
        $metaStawka  = $isNisko ? 'stawka_niskoplatna' : 'stawka_wysokoplatna';
        $metaHours   = $isNisko ? 'bookero_hours_nisko' : 'bookero_hours_pelno';

        $query = new \WP_Query([
            'post_type'              => 'psycholog',
            'post__in'               => $pids,
            'posts_per_page'         => -1,
            'post_status'            => 'publish',
            'orderby'                => 'post__in',
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        // Bolt Optimization: Batch pre-fetch attachment meta to avoid N+1 queries.
        np_warm_attachment_meta_cache(wp_list_pluck($query->posts, 'ID'));

        $records = [];
        foreach ($query->posts as $post) {
            $pid      = (int) $post->ID;
            $workerId = (string) get_post_meta($pid, $metaBkKey, true);

            if ($workerId === '') {
                continue;
            }

            $slotsJson = get_post_meta($pid, $metaSlots, true);
            $slots     = $slotsJson ? (array) json_decode($slotsJson, true) : [];

            $hoursJson  = get_post_meta($pid, $metaHours, true);
            $hoursCache = $hoursJson ? (array) json_decode($hoursJson, true) : [];

            $records[ $pid ] = new WorkerRecord(
                postId: $pid,
                name: $post->post_title,
                workerId: $workerId,
                slots: $slots,
                nearestTerm: (string) get_post_meta($pid, $metaTerm, true),
                price: (string) get_post_meta($pid, $metaStawka, true)
                                ?: ($isNisko ? '55 zł' : '145 zł'),
                rodzaj: (string) get_post_meta($pid, 'rodzaj_wizyty', true),
                avatar: np_get_post_image_url($pid, [ '_thumbnail_id', 'zdjecie_profilowe', 'zdjecie' ], 'medium'),
                profileUrl: (string) get_the_permalink($pid),
                hoursCache: $hoursCache,
            );
        }

        return $records;
    }

    /**
     * Pobiera wszystkich aktywnych psychologów z pełnymi metadanymi w 2 zapytaniach SQL.
     *
     * Wyniki są cache'owane w WP Object Cache (Redis) przez WORKERS_CACHE_TTL (7 min),
     * eliminując wielokrotne WP_Query + batch postmeta dla każdego żądania AJAX
     * bk_get_shared_month / bk_get_date_slots w obrębie tego samego okna czasowego.
     *
     * Mechanizm:
     *   L1 — WP Object Cache (Redis):  zero SQL, zero PHP, ~0.1 ms RTT do Redisa
     *   L2 — WP_Query + batch postmeta: 2 zapytania SQL, ~5-20 ms
     *
     *   SQL 1 (WP_Query): SELECT ID FROM posts WHERE type='psycholog' AND worker_id EXISTS
     *   SQL 2 (WP automat): SELECT meta_key,meta_value FROM postmeta WHERE post_id IN (...)
     *
     * Po wykonaniu WP_Query z update_post_meta_cache=>true wszystkie kolejne wywołania
     * get_post_meta() dla tych postów trafiają do pamięci (WP object cache / Redis) —
     * zero dodatkowego SQL niezależnie od liczby psychologów.
     *
     * Inwalidacja: invalidateWorkersCache() — wywoływana przez hook save_post_psycholog
     * oraz niepodzielni_bookero_batch_synced.
     *
     * @param  string $typ  'pelnoplatny' | 'nisko'
     * @return array<int, WorkerRecord>  Indeksowane po post_id
     */
    public function getAllWorkersWithMeta(string $typ): array
    {
        // L1: WP Object Cache (Redis) — zero SQL przy trafieniu w cache
        $cacheKey = $this->workersCacheKey($typ);
        $cached   = wp_cache_get($cacheKey, self::WORKERS_CACHE_GROUP);
        if (is_array($cached)) {
            /** @var array<int, WorkerRecord> $cached */
            return $cached;
        }

        $isNisko     = $this->isNisko($typ);
        $metaBkKey   = $isNisko ? 'bookero_id_niski' : 'bookero_id_pelny';
        $metaSlots   = $isNisko ? 'bookero_slots_nisko' : 'bookero_slots_pelno';
        $metaTerm    = $isNisko ? 'najblizszy_termin_niskoplatny' : 'najblizszy_termin_pelnoplatny';
        $metaStawka  = $isNisko ? 'stawka_niskoplatna' : 'stawka_wysokoplatna';
        $metaHours   = $isNisko ? 'bookero_hours_nisko' : 'bookero_hours_pelno';

        // Zapytanie 1 — identyfikacja postów + pre-load meta cache (update_post_meta_cache)
        // posts_per_page=500 zamiast -1 — zabezpieczenie przed memory bomb przy dużej bazie.
        // Przy 500 psychologach (każdy ~5 meta klucze × ~500B) = ~2,5MB w RAM — akceptowalne.
        // Jeśli baza przekroczy 500 wpisów, wdrożyć chunking: kilka WP_Query z offset.
        $query = new \WP_Query([
            'post_type'              => 'psycholog',
            'posts_per_page'         => 500,
            'post_status'            => 'publish',
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'update_post_meta_cache' => true,   // SQL 2 — ładuje ALL meta w jednym IN()
            'update_post_term_cache' => false,  // taksonomie niepotrzebne tutaj
            'meta_query'             => [
                'relation' => 'AND',
                [ 'key' => $metaBkKey, 'compare' => 'EXISTS' ],
                [ 'key' => $metaBkKey, 'value'   => '', 'compare' => '!=' ],
            ],
        ]);

        // Bolt Optimization: Batch pre-fetch attachment meta to avoid N+1 queries.
        np_warm_attachment_meta_cache(wp_list_pluck($query->posts, 'ID'));

        $records = [];

        // Zapytanie 2 zostało już wykonane automatycznie przez WP_Query.
        // Poniższe get_post_meta() / np_get_post_image_url() / get_the_permalink()
        // trafiają wyłącznie do WP object cache — zero SQL w tej pętli.
        foreach ($query->posts as $post) {
            $pid      = (int) $post->ID;
            $workerId = (string) get_post_meta($pid, $metaBkKey, true);

            if ($workerId === '') {
                continue;
            }

            // Dekoduj sloty — JSON → string[]
            $slotsJson = get_post_meta($pid, $metaSlots, true);
            $slots     = [];
            if ($slotsJson) {
                $decoded = json_decode($slotsJson, true);
                if (is_array($decoded)) {
                    $slots = $decoded;
                }
            }

            // Dekoduj cache godzin — JSON → array<string, string[]>
            $hoursJson  = get_post_meta($pid, $metaHours, true);
            $hoursCache = [];
            if ($hoursJson) {
                $decoded = json_decode($hoursJson, true);
                if (is_array($decoded)) {
                    $hoursCache = $decoded;
                }
            }

            $records[ $pid ] = new WorkerRecord(
                postId: $pid,
                name: $post->post_title,
                workerId: $workerId,
                slots: $slots,
                nearestTerm: (string) get_post_meta($pid, $metaTerm, true),
                price: (string) get_post_meta($pid, $metaStawka, true)
                                ?: ($isNisko ? '55 zł' : '145 zł'),
                rodzaj: (string) get_post_meta($pid, 'rodzaj_wizyty', true),
                // np_get_post_image_url() używa get_post_meta() wewnętrznie — cache hit
                avatar: np_get_post_image_url($pid, [ '_thumbnail_id', 'zdjecie_profilowe', 'zdjecie' ], 'medium'),
                profileUrl: (string) get_the_permalink($pid),
                hoursCache: $hoursCache,
            );
        }

        // L1 miss — zapisz wynik w WP Object Cache (Redis) na WORKERS_CACHE_TTL
        wp_cache_set($cacheKey, $records, self::WORKERS_CACHE_GROUP, self::WORKERS_CACHE_TTL);

        return $records;
    }

    // ─── Zapis cache godzin (bez odczytu) ────────────────────────────────────────

    /**
     * Zapisuje gotową mapę godzin do postmeta — bez wstępnego odczytu DB.
     *
     * Używane przez SharedCalendarService, który scalił mapę w pamięci
     * z WorkerRecord::$hoursCache, eliminując dodatkowy get_post_meta() read.
     *
     * Przyjmuje mapę już oczyszczoną z przeszłych dat przez wywołującego.
     *
     * @param array<string, string[]> $map  date → hours[]
     */
    public function persistHoursMap(int $postId, string $typ, array $map): void
    {
        $metaKey = $this->isNisko($typ) ? 'bookero_hours_nisko' : 'bookero_hours_pelno';
        update_post_meta($postId, $metaKey, wp_json_encode($map));
    }

    // ─── Transienty: negative cache godzin ───────────────────────────────────────

    /**
     * TTL negative cache: 90 sekund.
     *
     * Wystarczająco długo, by nie spamować przeciążonego Bookero przy
     * namiętnym klikaniu w ten sam dzień przez wielu użytkowników.
     * Wystarczająco krótko, by przy chwilowej awarii API dane wróciły szybko.
     */
    private const HOURS_ERROR_TTL = 90;

    /**
     * Sprawdza czy ostatnie zapytanie o godziny dla tego konkretnego pracownika
     * zwróciło błąd API.
     *
     * Klucz jest skoped per (typ × workerId × date) — awaria API jednego psychologa
     * nie blokuje pozostałych na tę samą datę.
     *
     * True = nie odpytuj API ponownie, zwróć [] natychmiast.
     */
    public function getHoursErrorTransient(string $typ, string $workerId, string $date): bool
    {
        return (bool) get_transient($this->hoursErrorCacheKey($typ, $workerId, $date));
    }

    /**
     * Ustawia flagę błędu API dla konkretnego pracownika na HOURS_ERROR_TTL sekund.
     * Kolejne żądania o te same godziny dla TEGO samego pracownika będą
     * zwracane z cache (pusty wynik) zamiast ponownie odpytywać API.
     * Pozostali pracownicy w tym samym dniu nie są tym dotknięci.
     */
    public function setHoursErrorTransient(string $typ, string $workerId, string $date): void
    {
        set_transient(
            $this->hoursErrorCacheKey($typ, $workerId, $date),
            1,
            self::HOURS_ERROR_TTL,
        );
    }

    /**
     * Klucz negative cache — 'ERROR' wewnątrz hasha gwarantuje izolację per pracownik.
     *
     * Format: np_bkday_ + md5(typ + 'ERROR' + date + workerId)
     *
     * Sentinel 'ERROR' oddziela domenę błędów od domeny wyników (dayCacheKey),
     * eliminując kolizję kluczy nawet przy identycznych wartościach typ/workerId/date.
     * WorkerId w hashu = awaria API jednego psychologa nie blokuje pozostałych.
     */
    private function hoursErrorCacheKey(string $typ, string $workerId, string $date): string
    {
        return 'np_bkday_' . md5($typ . 'ERROR' . $date . $workerId);
    }

    // ─── Transienty: shared calendar (buildMonthData) ─────────────────────────────

    private const SHARED_MONTH_TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * @return array<string, mixed>|false
     */
    public function getSharedMonthTransient(string $typ, int $plusMonths): array|false
    {
        $cached = get_transient($this->sharedMonthCacheKey($typ, $plusMonths));
        return is_array($cached) ? $cached : false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setSharedMonthTransient(string $typ, int $plusMonths, array $data): void
    {
        set_transient($this->sharedMonthCacheKey($typ, $plusMonths), $data, self::SHARED_MONTH_TTL);
    }

    public function invalidateSharedMonthTransients(string $typ, int $monthCount = 3): void
    {
        for ($i = 0; $i < $monthCount; $i++) {
            delete_transient($this->sharedMonthCacheKey($typ, $i));
        }
    }

    /**
     * Klucz kompatybilny ze starym kodem w np_bk_build_month_data().
     */
    public function sharedMonthCacheKey(string $typ, int $plusMonths): string
    {
        return 'np_bk_month_' . $typ . '_' . $plusMonths;
    }

    // ─── Prywatne pomocnicze ──────────────────────────────────────────────────────

    private function isNisko(string $typ): bool
    {
        return in_array($typ, [ 'nisko', 'niskoplatny', 'niskoplatne' ], true);
    }

    private function nearestDateMetaKey(string $typ): string
    {
        return $this->isNisko($typ)
            ? 'najblizszy_termin_niskoplatny'
            : 'najblizszy_termin_pelnoplatny';
    }
}
