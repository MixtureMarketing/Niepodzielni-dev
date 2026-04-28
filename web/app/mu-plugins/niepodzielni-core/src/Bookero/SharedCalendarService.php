<?php

declare(strict_types=1);

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
class SharedCalendarService
{
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
    public function buildMonthData(string $typ, int $plusMonths): array
    {
        // L1: transient cache
        $cached = $this->repo->getSharedMonthTransient($typ, $plusMonths);
        if ($cached !== false) {
            /** @var array{month_name: string, year_month: string, first_dow: int, days_in_month: int, today: string, oldest_sync: int, dates: array<string, array<int, array<string, mixed>>>} $cached */
            return $cached;
        }

        $meta     = $this->buildMonthMeta($plusMonths);
        $config   = $this->safeGetAccountConfig($typ);
        $calHash  = np_bookero_cal_id_for($typ);

        // Batch load: 2 SQL zamiast 1 + N×get_post_meta
        $workers = $this->repo->getAllWorkersWithMeta($typ);

        $dates = [];

        foreach ($workers as $worker) {
            // Ustal dostępne daty dla żądanego miesiąca
            $monthDates = $this->filterDatesForMonth($worker, $meta['year_month']);

            if (empty($monthDates)) {
                continue;
            }

            $entry = [
                'bookero_id'  => $worker->workerId,
                'cal_hash'    => $calHash,
                'service_id'  => $config->getServiceIdForWorker($worker->workerId) ?: null,
                'name'        => $worker->name,
                'avatar'      => $worker->avatar,
                'price'       => $worker->price,
                'rodzaj'      => $worker->rodzaj,
                'profile_url' => $worker->profileUrl,
                'hours'       => [],  // godziny ładowane lazily przez getDateSlots
            ];

            foreach ($monthDates as $date) {
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

        $this->repo->setSharedMonthTransient($typ, $plusMonths, $data);

        return $data;
    }

    /**
     * Zwraca psychologów dostępnych w konkretnym dniu wraz z godzinami.
     *
     * Godziny: L1 DB cache → L2 negative cache (90s) → L3 API (batch cURL multi).
     * Wszystkie braki cache pobierane równolegle — czas ≈ najwolniejszy request.
     *
     * @return array{workers: array<int, array<string, mixed>>}
     */
    public function getDateSlots(string $typ, string $date): array
    {
        $config  = $this->safeGetAccountConfig($typ);
        $calHash = np_bookero_cal_id_for($typ);
        $today   = date('Y-m-d');

        // Batch load: 2 SQL zamiast 1 + N×get_post_meta
        $workers = $this->repo->getAllWorkersWithMeta($typ);

        // Faza 1: podziel workers na tych z cache i tych wymagających API
        /** @var WorkerRecord[] $needsApi */
        $needsApi      = [];  // workerId → WorkerRecord
        $resolvedHours = [];  // workerId → string[]

        foreach ($workers as $worker) {
            if (! $worker->hasDate($date)) {
                if (! $this->workerHasDateViaFallback($worker, $date)) {
                    continue;
                }
            }

            $cached = $worker->cachedHoursFor($date);
            if ($cached !== null) {
                $resolvedHours[ $worker->workerId ] = $cached;
                continue;
            }

            if ($this->repo->getHoursErrorTransient($typ, $worker->workerId, $date)) {
                $resolvedHours[ $worker->workerId ] = [];
                continue;
            }

            $needsApi[ $worker->workerId ] = $worker;
        }

        // Faza 2: batch cURL multi dla brakujących godzin (równolegle)
        if (! empty($needsApi) && $calHash) {
            $workerServiceMap = [];
            foreach ($needsApi as $wid => $w) {
                $workerServiceMap[ (string) $wid ] = $config->getServiceIdForWorker((string) $wid);
            }

            $batchResults = $this->client->getMonthDayBatch($calHash, $date, $workerServiceMap);

            foreach ($batchResults as $workerId => $hours) {
                $worker = $needsApi[ $workerId ] ?? $needsApi[ (int) $workerId ] ?? null;
                if (! $worker) {
                    continue;
                }

                if ($hours === null) {
                    // Błąd API — ustaw negative cache, nie blokuj pozostałych
                    $this->repo->setHoursErrorTransient($typ, $workerId, $date);
                    $resolvedHours[ $workerId ] = [];
                    continue;
                }

                // Zaktualizuj mapę godzin w DB (scal z istniejącymi, wyczyść przeszłość)
                $updatedMap          = $worker->hoursCache;
                $updatedMap[ $date ] = array_values($hours);
                foreach (array_keys($updatedMap) as $d) {
                    if ($d < $today) {
                        unset($updatedMap[ $d ]);
                    }
                }
                $this->repo->persistHoursMap($worker->postId, $typ, $updatedMap);

                if (empty($hours)) {
                    $this->repo->removeDateFromSlots($worker->postId, $typ, $date);
                    $this->repo->invalidateSharedMonthTransients($typ);
                }

                $resolvedHours[ $workerId ] = $hours;
            }
        }

        // Faza 3: zbuduj wynik ze wszystkich zgromadzonych godzin
        $result = [];
        foreach ($workers as $worker) {
            if (! isset($resolvedHours[ $worker->workerId ])) {
                continue;
            }
            $hours = $resolvedHours[ $worker->workerId ];
            if (empty($hours)) {
                continue;
            }

            $result[] = [
                'bookero_id'  => $worker->workerId,
                'cal_hash'    => $calHash,
                'service_id'  => $config->getServiceIdForWorker($worker->workerId) ?: null,
                'name'        => $worker->name,
                'avatar'      => $worker->avatar,
                'price'       => $worker->price,
                'rodzaj'      => $worker->rodzaj,
                'profile_url' => $worker->profileUrl,
                'hours'       => $hours,
            ];
        }

        usort($result, static fn($a, $b) => strcmp($a['name'], $b['name']));

        return [ 'workers' => $result ];
    }

    // ─── Prywatne — budowanie miesiąca ───────────────────────────────────────────

    /**
     * Oblicza metadane kalendarza dla żądanego przesunięcia miesięcznego.
     *
     * @return array{month_name: string, year_month: string, first_dow: int, days_in_month: int, today: string}
     */
    private function buildMonthMeta(int $plusMonths): array
    {
        $tsStart   = strtotime("+{$plusMonths} months", mktime(0, 0, 0, (int) date('n'), 1) ?: time()) ?: time();
        $year      = (int) date('Y', $tsStart);
        $month     = (int) date('n', $tsStart);
        $yearMonth = date('Y-m', $tsStart);

        return [
            'month_name'    => self::MONTHS_PL[ $month ] . ' ' . $year,
            'year_month'    => $yearMonth,
            'first_dow'     => (int) date('N', $tsStart),  // 1=Pn, 7=Nd
            'days_in_month' => (int) date('t', $tsStart),
            'today'         => date('Y-m-d'),
        ];
    }

    /**
     * Zwraca daty z danego miesiąca dla psychologa.
     * Jeśli slots puste — fallback na nearestTerm (psycholog niezsynch. przez cron).
     *
     * @return string[]
     */
    private function filterDatesForMonth(WorkerRecord $worker, string $yearMonth): array
    {
        $filtered = array_filter(
            $worker->slots,
            static fn(string $d) => str_starts_with($d, $yearMonth),
        );

        if (! empty($filtered)) {
            return array_values($filtered);
        }

        // Fallback: nearestTerm jako jedyna data (gdy cron jeszcze nie wypełnił slots)
        if ($worker->nearestTerm === '') {
            return [];
        }

        $sortable = np_get_sortable_date($worker->nearestTerm);
        if ($sortable === '99999999') {
            return [];
        }

        $dateYmd = substr($sortable, 0, 4) . '-' . substr($sortable, 4, 2) . '-' . substr($sortable, 6, 2);

        return str_starts_with($dateYmd, $yearMonth) ? [ $dateYmd ] : [];
    }

    // ─── Prywatne — godziny ───────────────────────────────────────────────────────

    /**
     * Sprawdza czy psycholog ma datę przez fallback nearestTerm (brak slots w DB).
     */
    private function workerHasDateViaFallback(WorkerRecord $worker, string $date): bool
    {
        if ($worker->nearestTerm === '') {
            return false;
        }

        $sortable = np_get_sortable_date($worker->nearestTerm);
        if ($sortable === '99999999') {
            return false;
        }

        $dateYmd = substr($sortable, 0, 4) . '-' . substr($sortable, 4, 2) . '-' . substr($sortable, 6, 2);

        return $dateYmd === $date;
    }

    // ─── Prywatne — account config ────────────────────────────────────────────────

    /**
     * Pobiera konfigurację konta przez BookeroSyncService (z transient cache).
     * Na błąd zwraca AccountConfig::empty() — graceful degradation, nie blokuje kalendarza.
     */
    private function safeGetAccountConfig(string $typ): AccountConfig
    {
        try {
            return $this->syncService->getAccountConfig($typ);
        } catch (BookeroApiException $e) {
            np_bookero_log_error('init', "typ={$typ}: " . $e->getMessage());
            return AccountConfig::empty();
        }
    }
}
