<?php

declare( strict_types=1 );

namespace Niepodzielni\Bookero;

/**
 * Serwis kalendarza współdzielonego — logika biznesowa endpointów AJAX.
 *
 * Zastępuje proceduralne funkcje np_bk_build_month_data() i logikę
 * z np_ajax_bk_get_date_slots() eliminując problem N+1 zapytań.
 *
 * Profil SQL dla typowego wywołania:
 *   buildMonthData()  → 2 zapytania (WP_Query + meta cache) + 1 get_transient check
 *   getDateSlots()    → 2 zapytania (WP_Query + meta cache) + N × update_post_meta (zapisy)
 *
 * Wcześniej było: 1 (get_posts) + N × 3-5 get_post_meta + ewentualne API calls
 */
class SharedCalendarService {

    // Polskie nazwy miesięcy do budowania nagłówka kalendarza
    private const MONTHS_PL = [
        '', 'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
        'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień',
    ];

    public function __construct(
        private readonly PsychologistRepository $repo,
        private readonly BookeroApiClient       $client,
        private readonly BookeroSyncService     $syncService,
    ) {}

    // ─── API publiczne ────────────────────────────────────────────────────────────

    /**
     * Buduje strukturę danych miesięcznego kalendarza dla frontendu.
     *
     * Zwracana struktura jest identyczna z np_bk_build_month_data() —
     * pełna kompatybilność z istniejącym JS bk-shared-calendar.js.
     *
     * Cache L1: transient (5 min) — klucz kompatybilny ze starym kodem.
     *
     * @return array{
     *   month_name: string,
     *   year_month: string,
     *   first_dow: int,
     *   days_in_month: int,
     *   today: string,
     *   oldest_sync: int,
     *   dates: array<string, array<int, array<string, mixed>>>
     * }
     */
    public function buildMonthData( string $typ, int $plusMonths ): array {
        // L1: transient cache
        $cached = $this->repo->getSharedMonthTransient( $typ, $plusMonths );
        if ( $cached !== false ) {
            return $cached;
        }

        $meta     = $this->buildMonthMeta( $plusMonths );
        $config   = $this->safeGetAccountConfig( $typ );
        $calHash  = np_bookero_cal_id_for( $typ );

        // Batch load: 2 SQL zamiast 1 + N×get_post_meta
        $workers = $this->repo->getAllWorkersWithMeta( $typ );

        $dates = [];

        foreach ( $workers as $worker ) {
            // Ustal dostępne daty dla żądanego miesiąca
            $monthDates = $this->filterDatesForMonth( $worker, $meta['year_month'] );

            if ( empty( $monthDates ) ) {
                continue;
            }

            $entry = [
                'bookero_id'  => $worker->workerId,
                'cal_hash'    => $calHash,
                'service_id'  => $config->serviceId ?: null,
                'name'        => $worker->name,
                'avatar'      => $worker->avatar,
                'price'       => $worker->price,
                'rodzaj'      => $worker->rodzaj,
                'profile_url' => $worker->profileUrl,
                'hours'       => [],  // godziny ładowane lazily przez getDateSlots
            ];

            foreach ( $monthDates as $date ) {
                $dates[ $date ][] = $entry;
            }
        }

        $data = [
            'month_name'    => $meta['month_name'],
            'year_month'    => $meta['year_month'],
            'first_dow'     => $meta['first_dow'],
            'days_in_month' => $meta['days_in_month'],
            'today'         => $meta['today'],
            'oldest_sync'   => time() - 60,
            'dates'         => $dates,
        ];

        $this->repo->setSharedMonthTransient( $typ, $plusMonths, $data );

        return $data;
    }

    /**
     * Zwraca psychologów dostępnych w konkretnym dniu wraz z godzinami.
     *
     * Godziny: najpierw DB cache (WorkerRecord::$hoursCache), potem API.
     * Zapis do DB odbywa się przez persistHoursMap() — bez dodatkowego odczytu.
     *
     * @return array{workers: array<int, array<string, mixed>>}
     */
    public function getDateSlots( string $typ, string $date ): array {
        $config  = $this->safeGetAccountConfig( $typ );
        $calHash = np_bookero_cal_id_for( $typ );
        $today   = date( 'Y-m-d' );

        // Batch load: 2 SQL zamiast 1 + N×get_post_meta
        $workers = $this->repo->getAllWorkersWithMeta( $typ );

        $result  = [];

        foreach ( $workers as $worker ) {
            if ( ! $worker->hasDate( $date ) ) {
                // Fallback: stara logika dla psychologów bez bookero_slots_* (nowy lub niezsynch.)
                if ( ! $this->workerHasDateViaFallback( $worker, $date ) ) {
                    continue;
                }
            }

            $hours = $this->resolveHours( $worker, $typ, $date, $calHash, $config, $today );

            if ( empty( $hours ) ) {
                continue; // Brak wolnych godzin — pomiń w wynikach
            }

            $result[] = [
                'bookero_id'  => $worker->workerId,
                'cal_hash'    => $calHash,
                'service_id'  => $config->serviceId ?: null,
                'name'        => $worker->name,
                'avatar'      => $worker->avatar,
                'price'       => $worker->price,
                'rodzaj'      => $worker->rodzaj,
                'profile_url' => $worker->profileUrl,
                'hours'       => $hours,
            ];
        }

        usort( $result, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

        return [ 'workers' => $result ];
    }

    // ─── Prywatne — budowanie miesiąca ───────────────────────────────────────────

    /**
     * Oblicza metadane kalendarza dla żądanego przesunięcia miesięcznego.
     *
     * @return array{month_name: string, year_month: string, first_dow: int, days_in_month: int, today: string}
     */
    private function buildMonthMeta( int $plusMonths ): array {
        $tsStart     = strtotime( "+{$plusMonths} months", mktime( 0, 0, 0, (int) date( 'n' ), 1 ) );
        $year        = (int) date( 'Y', $tsStart );
        $month       = (int) date( 'n', $tsStart );
        $yearMonth   = date( 'Y-m', $tsStart );

        return [
            'month_name'    => self::MONTHS_PL[ $month ] . ' ' . $year,
            'year_month'    => $yearMonth,
            'first_dow'     => (int) date( 'N', $tsStart ),  // 1=Pn, 7=Nd
            'days_in_month' => (int) date( 't', $tsStart ),
            'today'         => date( 'Y-m-d' ),
        ];
    }

    /**
     * Zwraca daty z danego miesiąca dla psychologa.
     * Jeśli slots puste — fallback na nearestTerm (psycholog niezsynch. przez cron).
     *
     * @return string[]
     */
    private function filterDatesForMonth( WorkerRecord $worker, string $yearMonth ): array {
        $filtered = array_filter(
            $worker->slots,
            static fn( string $d ) => str_starts_with( $d, $yearMonth )
        );

        if ( ! empty( $filtered ) ) {
            return array_values( $filtered );
        }

        // Fallback: nearestTerm jako jedyna data (gdy cron jeszcze nie wypełnił slots)
        if ( $worker->nearestTerm === '' ) {
            return [];
        }

        $sortable = np_get_sortable_date( $worker->nearestTerm );
        if ( $sortable === '99999999' ) {
            return [];
        }

        $dateYmd = substr( $sortable, 0, 4 ) . '-' . substr( $sortable, 4, 2 ) . '-' . substr( $sortable, 6, 2 );

        return str_starts_with( $dateYmd, $yearMonth ) ? [ $dateYmd ] : [];
    }

    // ─── Prywatne — godziny ───────────────────────────────────────────────────────

    /**
     * Sprawdza czy psycholog ma datę przez fallback nearestTerm (brak slots w DB).
     */
    private function workerHasDateViaFallback( WorkerRecord $worker, string $date ): bool {
        if ( $worker->nearestTerm === '' ) {
            return false;
        }

        $sortable = np_get_sortable_date( $worker->nearestTerm );
        if ( $sortable === '99999999' ) {
            return false;
        }

        $dateYmd = substr( $sortable, 0, 4 ) . '-' . substr( $sortable, 4, 2 ) . '-' . substr( $sortable, 6, 2 );

        return $dateYmd === $date;
    }

    /**
     * Zwraca godziny dla psychologa w danym dniu.
     *
     * Priorytet: WorkerRecord::$hoursCache (DB) → API → zapis do DB.
     * Zapis używa persistHoursMap() z mapą scaloną w pamięci — zero dodatkowego odczytu DB.
     *
     * @return string[]
     */
    private function resolveHours(
        WorkerRecord  $worker,
        string        $typ,
        string        $date,
        string        $calHash,
        AccountConfig $config,
        string        $today,
    ): array {
        // L1: DB cache z WorkerRecord — zero SQL
        $cached = $worker->cachedHoursFor( $date );
        if ( $cached !== null ) {
            return $cached;
        }

        // L2: Brak w cache — pobierz z API
        if ( ! $calHash ) {
            return [];
        }

        try {
            $hours = $this->client->getMonthDay( $calHash, $worker->workerId, $date, $config->serviceId );
        } catch ( BookeroApiException $e ) {
            np_bookero_log_error( 'getMonthDay', "worker={$worker->workerId} date={$date}: " . $e->getMessage() );
            return [];
        }

        // Zapisz przez persistHoursMap() — scala z istniejącą mapą w pamięci,
        // omijając dodatkowy get_post_meta() read który byłby w saveHours().
        $updatedMap          = $worker->hoursCache;
        $updatedMap[ $date ] = array_values( $hours );

        // Wyczyść daty z przeszłości w pamięci przed zapisem
        foreach ( array_keys( $updatedMap ) as $d ) {
            if ( $d < $today ) {
                unset( $updatedMap[ $d ] );
            }
        }

        $this->repo->persistHoursMap( $worker->postId, $typ, $updatedMap );

        return $hours;
    }

    // ─── Prywatne — account config ────────────────────────────────────────────────

    /**
     * Pobiera konfigurację konta przez BookeroSyncService (z transient cache).
     * Na błąd zwraca AccountConfig::empty() — graceful degradation, nie blokuje kalendarza.
     */
    private function safeGetAccountConfig( string $typ ): AccountConfig {
        try {
            return $this->syncService->getAccountConfig( $typ );
        } catch ( BookeroApiException $e ) {
            np_bookero_log_error( 'init', "typ={$typ}: " . $e->getMessage() );
            return AccountConfig::empty();
        }
    }
}
