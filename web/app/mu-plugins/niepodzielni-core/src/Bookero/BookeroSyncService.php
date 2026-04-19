<?php

declare(strict_types=1);

namespace Niepodzielni\Bookero;

/**
 * Serwis synchronizacji Bookero — warstwa logiki biznesowej.
 *
 * Orkiestruje przepływ danych między BookeroApiClient (HTTP)
 * a PsychologistRepository (DB/cache). Sam nie dotyka bazy ani HTTP.
 *
 * Wstrzykiwane zależności umożliwiają testowanie przez podstawienie
 * mocków bez uruchamiania środowiska WordPress.
 *
 * Jedynym globalnym wołaniem pozostaje np_bookero_cal_id_for($typ),
 * która czyta stałe PHP z .env — jest to świadomy kompromis,
 * ponieważ hash konta to konfiguracja środowiskowa, nie dane DB.
 */
class BookeroSyncService
{
    public function __construct(
        private readonly BookeroApiClient       $client,
        private readonly PsychologistRepository $repo,
    ) {}

    // ─── API publiczne ────────────────────────────────────────────────────────────

    /**
     * Synchronizuje dostępność jednego psychologa (oba konta: pełno i nisko).
     *
     * Schemat dla każdego konta:
     *   1. Pobierz worker ID z repo
     *   2. Pobierz availability (3 × getMonth, przez repo cache)
     *   3. Zapisz nearest date lub wyczyść stary wpis
     *   4. Zapisz listę dostępnych dat
     *   5. Pre-warm cache godzin dla pierwszej daty
     *   6. Dotknij timestamp synchronizacji
     */
    public function syncSingleWorker(int $postId): SyncResult
    {
        $workerPelny  = $this->repo->getWorkerId($postId, 'pelnoplatny');
        $workerNisko  = $this->repo->getWorkerId($postId, 'nisko');
        $nearestPelny = '';
        $nearestNisko = '';

        if ($workerPelny !== '') {
            $avail        = $this->getAvailability($workerPelny, 'pelnoplatny');
            $nearestPelny = $avail['nearest'];

            if ($nearestPelny !== '') {
                $this->repo->saveNearestDate($postId, 'pelnoplatny', $nearestPelny);
            } else {
                $this->repo->clearNearestDate($postId, 'pelnoplatny');
            }

            $this->repo->saveAvailableDates($postId, 'pelnoplatny', $avail['dates']);
            $this->prewarmHours($postId, $workerPelny, 'pelnoplatny', $avail['dates']);
        }

        if ($workerNisko !== '') {
            $avail        = $this->getAvailability($workerNisko, 'nisko');
            $nearestNisko = $avail['nearest'];

            if ($nearestNisko !== '') {
                $this->repo->saveNearestDate($postId, 'nisko', $nearestNisko);
            } else {
                $this->repo->clearNearestDate($postId, 'nisko');
            }

            $this->repo->saveAvailableDates($postId, 'nisko', $avail['dates']);
            $this->prewarmHours($postId, $workerNisko, 'nisko', $avail['dates']);
        }

        // Zapisz timestamp nawet gdy brak terminów — odróżnia "zsynchronizowany" od "jeszcze nie"
        if ($workerPelny !== '' || $workerNisko !== '') {
            $this->repo->touchSyncTimestamp($postId);
        }

        return new SyncResult(
            postId: $postId,
            hasPelny: $workerPelny !== '',
            hasNisko: $workerNisko !== '',
            nearestPelny: $nearestPelny,
            nearestNisko: $nearestNisko,
        );
    }

    /**
     * Agreguje dostępność z 3 miesięcy w jeden wynik.
     *
     * Wywoływane bezpośrednio przez AJAX handlers gdy potrzeba świeżych danych
     * poza kontekstem crona (np. po ręcznym odświeżeniu).
     *
     * @return array{nearest: string, dates: string[]}
     */
    public function getAvailability(string $workerId, string $typ): array
    {
        $today   = date('Y-m-d');
        $dates   = [];
        $nearest = '';

        for ($i = 0; $i <= 2; $i++) {
            $slots = $this->getMonthSlots($workerId, $typ, $i);

            foreach ($slots as $slot) {
                $date = $slot['date'];
                if (! $date || $date < $today) {
                    continue;
                }

                $ts = strtotime($date);
                if (! $ts) {
                    continue;
                }

                if ($nearest === '') {
                    $nearest = date_i18n('j F Y', $ts);
                }

                $dates[] = $date;
            }
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        return [ 'nearest' => $nearest, 'dates' => $dates ];
    }

    /**
     * Zwraca konfigurację konta — najpierw z cache (transient), potem z /init.
     *
     * Publiczne, bo używane przez AJAX handlers które muszą znać service_id
     * do budowania payload rezerwacji.
     *
     * @throws BookeroApiException  gdy /init niedostępny i brak cache
     */
    public function getAccountConfig(string $typ): AccountConfig
    {
        $cached = $this->repo->getAccountConfigTransient($typ);
        if ($cached !== false) {
            return AccountConfig::fromArray($cached);
        }

        $calHash = np_bookero_cal_id_for($typ);
        if (! $calHash) {
            return AccountConfig::empty();
        }

        $config = $this->client->getAccountConfig($calHash);
        $this->repo->setAccountConfigTransient($typ, $config);

        return $config;
    }

    // ─── Prywatne pomocnicze ──────────────────────────────────────────────────────

    /**
     * Loguje błąd API do np_bookero_log_error (ring buffer WP) i error_log (Apache).
     */
    private function logApiError(string $context, string $details, BookeroApiException $e): void
    {
        np_bookero_log_error($context, "{$details}: " . $e->getMessage());
        error_log('[Bookero] ApiException ' . $context . ' ' . $details . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }

    /**
     * Zwraca sloty dla jednego miesiąca — repo jako L1 cache, klient jako źródło danych.
     * Błędy HTTP są łapane tutaj, logowane i przekształcane w pustą tablicę + backoff TTL.
     *
     * @return array<array{date: string, hour: string}>
     */
    private function getMonthSlots(string $workerId, string $typ, int $plusMonths): array
    {
        $cached = $this->repo->getMonthTransient($typ, $workerId, $plusMonths);
        if ($cached !== false) {
            return $cached;
        }

        $calHash = np_bookero_cal_id_for($typ);
        if (! $calHash) {
            return [];
        }

        try {
            $config = $this->getAccountConfig($typ);
            $slots  = $this->client->getMonth($calHash, $workerId, $config->serviceId, $plusMonths);
            $this->repo->setMonthTransient($typ, $workerId, $plusMonths, $slots);

            return $slots;
        } catch (BookeroRateLimitException $e) {
            // Rate limit / timeout — nie ustawiaj backoffu per-worker, rzuć wyżej.
            // Circuit breaker w cron przechwyci to i ustawi globalny lockout.
            error_log('[Bookero] RateLimit getMonth worker=' . $workerId . ' typ=' . $typ . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        } catch (BookeroApiException $e) {
            $this->logApiError('getMonth', "worker={$workerId} typ={$typ}", $e);
            $this->repo->setMonthTransientBackoff($typ, $workerId, $plusMonths);

            return [];
        }
    }

    /**
     * Pre-warm cache godzin dla pierwszej dostępnej daty.
     * Pomijany gdy data już jest w DB — nie generuje zbędnych requestów API.
     *
     * @param string[] $dates  Posortowane daty YYYY-MM-DD
     */
    private function prewarmHours(int $postId, string $workerId, string $typ, array $dates): void
    {
        if (empty($dates)) {
            return;
        }

        $nearestDate = $dates[0];

        // Sprawdź DB — jeśli null (brak wpisu), pobierz z API
        if ($this->repo->getCachedHours($postId, $typ, $nearestDate) !== null) {
            return;
        }

        $calHash = np_bookero_cal_id_for($typ);
        if (! $calHash) {
            return;
        }

        try {
            $config = $this->getAccountConfig($typ);
            $hours  = $this->client->getMonthDay($calHash, $workerId, $nearestDate, $config->serviceId);
            $this->repo->saveHours($postId, $typ, $nearestDate, $hours);
        } catch (BookeroRateLimitException $e) {
            // Rate limit podczas pre-warmu — rzuć wyżej do circuit breaker w cron.
            error_log('[Bookero] RateLimit prewarm worker=' . $workerId . ' date=' . $nearestDate . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        } catch (BookeroApiException $e) {
            $this->logApiError('getMonthDay', "worker={$workerId} date={$nearestDate}", $e);
        }
    }
}
