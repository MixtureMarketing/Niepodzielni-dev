<?php

declare(strict_types=1);

namespace Niepodzielni\Bookero;

/**
 * Klient HTTP do publicznego API widgetu Bookero (v2).
 *
 * Odpowiada WYŁĄCZNIE za komunikację HTTP — nie dotyka bazy danych,
 * nie korzysta z transientów, nie loguje. Przy każdym błędzie rzuca
 * BookeroApiException — decyzja o logowaniu należy do warstwy wywołującej.
 *
 * Obsługiwane endpointy:
 *   GET  /getMonth    — dostępne dni w miesiącu
 *   GET  /getMonthDay — dostępne godziny w konkretnym dniu
 *   GET  /init        — konfiguracja konta (services, payments)
 *   POST /add         — tworzenie rezerwacji
 */
class BookeroApiClient
{
    private const BASE_URL   = 'https://plugin.bookero.pl/plugin-api/v2/';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    // ─── Publiczne metody API ──────────────────────────────────────────────────────

    /**
     * Pobiera dostępne dni dla pracownika w danym miesiącu.
     *
     * @return array<array{date: string, hour: string}>  Tylko dni z valid_day > 0
     * @throws BookeroApiException
     */
    public function getMonth(
        string $calHash,
        string $workerId,
        int    $serviceId,
        int    $plusMonths,
    ): array {
        $body = $this->get('getMonth', [
            'bookero_id'         => $calHash,
            'worker'             => $workerId,
            'service'            => $serviceId,
            'plus_months'        => $plusMonths,
            'people'             => 1,
            'lang'               => 'pl',
            'periodicity_id'     => 0,
            'custom_duration_id' => 0,
            'plugin_comment'     => wp_json_encode([ 'data' => [ 'parameters' => [] ] ]),
        ], timeout: 15);

        return $this->normalizeSlots($body);
    }

    /**
     * Pobiera dostępne godziny dla pracownika w konkretnym dniu.
     *
     * @return string[]  np. ['09:00', '10:00', '11:30']
     * @throws BookeroApiException
     */
    public function getMonthDay(
        string $calHash,
        string $workerId,
        string $date,
        int    $serviceId,
    ): array {
        $body = $this->get('getMonthDay', [
            'bookero_id'         => $calHash,
            'worker'             => $workerId,
            'date'               => $date,
            'service'            => $serviceId,
            'people'             => 1,
            'lang'               => 'pl',
            'periodicity_id'     => 0,
            'custom_duration_id' => 0,
            'hour'               => '',
            'phone'              => '',
            'email'              => '',
            'plugin_comment'     => wp_json_encode([ 'data' => [ 'parameters' => (object) [] ] ]),
        ], timeout: 10);

        $hours = [];
        foreach (($body['data']['hours'] ?? []) as $slot) {
            if (! empty($slot['valid']) && ! empty($slot['hour'])) {
                $hours[] = (string) $slot['hour'];
            }
        }

        return $hours;
    }

    /**
     * Pobiera konfigurację konta z endpointu /init.
     *
     * serviceId — usługa fallback (z największą liczbą workers) dla workerów nie znalezionych w mapie.
     * workerServiceMap — mapa workerId → serviceId zbudowana ze wszystkich services_list[].workers.
     *   Każdy worker jest przypisany do PIERWSZEGO serwisu w którym się pojawia.
     *   Dzięki temu getMonth jest wysyłany z właściwym service_id per worker.
     *
     * @throws BookeroApiException
     */
    public function getAccountConfig(string $calHash): AccountConfig
    {
        $body = $this->get('init', [
            'bookero_id' => $calHash,
            'lang'       => 'pl',
            'type'       => 'calendar',
        ], timeout: 10);

        $serviceId        = 0;
        $serviceName      = '';
        $workerServiceMap = [];

        if (! empty($body['services_list']) && is_array($body['services_list'])) {
            $best      = $body['services_list'][0];
            $bestCount = is_array($best['workers'] ?? null) ? count($best['workers']) : 0;

            foreach ($body['services_list'] as $svc) {
                $svcId   = (int) ($svc['id'] ?? 0);
                $workers = $svc['workers'] ?? [];

                if (! is_array($workers) || ! $svcId) {
                    continue;
                }

                // Buduj mapę worker → service (pierwszy serwis w którym worker się pojawia)
                foreach ($workers as $wId) {
                    $key = (string) $wId;
                    if (! isset($workerServiceMap[$key])) {
                        $workerServiceMap[$key] = $svcId;
                    }
                }

                // Fallback serviceId = serwis z największą liczbą workers
                $cnt = count($workers);
                if ($cnt > $bestCount) {
                    $bestCount = $cnt;
                    $best      = $svc;
                }
            }

            $serviceId   = (int) ($best['id']   ?? 0);
            $serviceName = (string) ($best['name'] ?? '');
        }

        $paymentId = 0;
        if (! empty($body['payment_methods']) && is_array($body['payment_methods'])) {
            foreach ($body['payment_methods'] as $pm) {
                if (! empty($pm['is_default'])) {
                    $paymentId = (int) ($pm['id'] ?? 0);
                    break;
                }
            }
            if (! $paymentId && isset($body['payment_methods'][0]['id'])) {
                $paymentId = (int) $body['payment_methods'][0]['id'];
            }
        }

        return new AccountConfig($serviceId, $serviceName, $paymentId, $workerServiceMap);
    }

    /**
     * Pobiera dostępne godziny dla wielu pracowników jednocześnie (cURL multi).
     *
     * Zamienia N sekwencyjnych requestów HTTP w jeden równoległy batch.
     * Czas odpowiedzi ≈ najwolniejszy pojedynczy request zamiast sumy wszystkich.
     *
     * @param  array<string, int> $workerServiceMap  workerId → serviceId
     * @return array<string, string[]|null>  workerId → godziny (null = błąd API)
     */
    public function getMonthDayBatch(string $calHash, string $date, array $workerServiceMap): array
    {
        if (empty($workerServiceMap)) {
            return [];
        }

        $pluginComment = wp_json_encode([ 'data' => [ 'parameters' => (object) [] ] ]);
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($workerServiceMap as $workerId => $serviceId) {
            $params = http_build_query([
                'bookero_id'         => $calHash,
                'worker'             => $workerId,
                'date'               => $date,
                'service'            => $serviceId,
                'people'             => 1,
                'lang'               => 'pl',
                'periodicity_id'     => 0,
                'custom_duration_id' => 0,
                'hour'               => '',
                'phone'              => '',
                'email'              => '',
                'plugin_comment'     => $pluginComment,
            ]);
            $ch = curl_init(self::BASE_URL . 'getMonthDay?' . $params);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_HTTPHEADER     => [ 'Accept: application/json' ],
            ]);
            $handles[ $workerId ] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        // Wykonaj wszystkie requesty równolegle
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);

        // Zbierz wyniki
        $results = [];
        foreach ($handles as $workerId => $ch) {
            $raw      = curl_multi_getcontent($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno    = curl_errno($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($errno !== 0 || $httpCode !== 200 || ! $raw) {
                $results[ $workerId ] = null; // błąd — caller użyje negative cache
                continue;
            }

            $body = json_decode($raw, true);
            if (! is_array($body) || (int) ($body['result'] ?? 0) !== 1) {
                $results[ $workerId ] = null;
                continue;
            }

            $hours = [];
            foreach (($body['data']['hours'] ?? []) as $slot) {
                if (! empty($slot['valid']) && ! empty($slot['hour'])) {
                    $hours[] = (string) $slot['hour'];
                }
            }
            $results[ $workerId ] = $hours;
        }

        curl_multi_close($mh);

        return $results;
    }

    /**
     * Tworzy rezerwację przez endpoint /add.
     *
     * @param  array<string, mixed> $payload  Gotowy payload (inquiries, customer data itd.)
     * @return array{payment_url: string, inquiry_id: int|null, plugin_inquiry_id: int|null, status: string|null}
     * @throws BookeroApiException
     */
    public function createBooking(string $calHash, array $payload): array
    {
        $payload['bookero_id'] = $calHash;

        // 8s zamiast 20s — fail-fast zapobiega wyczerpaniu puli workerów PHP-FPM
        $body    = $this->post('add', $payload, timeout: 8);
        $inquiry = $body['data']['inquiries'][0] ?? [];

        return [
            'payment_url'       => $body['data']['payment_url']    ?? '',
            'inquiry_id'        => $inquiry['id']                   ?? null,
            'plugin_inquiry_id' => $inquiry['plugin_inquiry_id']    ?? null,
            'status'            => $inquiry['status']               ?? null,
        ];
    }

    // ─── Prywatne metody transportowe ─────────────────────────────────────────────

    /**
     * Wykonuje żądanie GET i zwraca zdekodowane ciało odpowiedzi.
     *
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     * @throws BookeroApiException
     */
    private function get(string $endpoint, array $params, int $timeout): array
    {
        $url      = self::BASE_URL . $endpoint . '?' . http_build_query($params);
        $response = wp_remote_get($url, [
            'timeout' => $timeout,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ],
        ]);

        return $this->parseResponse($endpoint, $response);
    }

    /**
     * Wykonuje żądanie POST z JSON body i zwraca zdekodowane ciało odpowiedzi.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws BookeroApiException
     */
    private function post(string $endpoint, array $payload, int $timeout): array
    {
        $response = wp_remote_post(self::BASE_URL . $endpoint, [
            'timeout' => $timeout,
            'headers' => [
                'Accept'       => 'application/json, text/plain, */*',
                'Content-Type' => 'application/json',
                'User-Agent'   => self::USER_AGENT,
                'Origin'       => get_site_url(),
                'Referer'      => get_site_url() . '/',
            ],
            'body' => wp_json_encode($payload),
        ]);

        return $this->parseResponse($endpoint, $response);
    }

    /**
     * Waliduje odpowiedź HTTP i zwraca zdekodowaną tablicę lub rzuca wyjątek.
     *
     * Circuit Breaker — dwie sytuacje rzucają BookeroRateLimitException zamiast
     * zwykłego BookeroApiException:
     *   - HTTP 429 Too Many Requests  — jawny sygnał rate-limit od serwera
     *   - Timeout (cURL error 28)     — objaw przeciążenia lub blokady IP
     *
     * Pozostałe błędy (WP_Error != timeout, HTTP 4xx/5xx != 429, result != 1)
     * rzucają BookeroApiException — obsługiwane lokalnie przez caller.
     *
     * @param  \WP_Error|array<string, mixed> $response
     * @return array<string, mixed>
     * @throws BookeroRateLimitException  przy HTTP 429 lub timeout
     * @throws BookeroApiException        przy pozostałych błędach
     */
    private function parseResponse(string $endpoint, \WP_Error|array $response): array
    {
        if (is_wp_error($response)) {
            $message = $response->get_error_message();

            // cURL error 28 = CURLE_OPERATION_TIMEDOUT — timeout jest objawem rate limitingu
            if (
                str_contains($message, 'timed out')
                || str_contains($message, 'cURL error 28')
                || str_contains($message, 'Operation timed out')
            ) {
                throw new BookeroRateLimitException($endpoint, "Timeout: {$message}");
            }

            throw new BookeroApiException($endpoint, $message);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // HTTP 429 — Bookero jawnie prosi o backoff
        if ($code === 429) {
            $retryAfter = wp_remote_retrieve_header($response, 'retry-after');
            $hint       = $retryAfter ? " (Retry-After: {$retryAfter}s)" : '';
            throw new BookeroRateLimitException($endpoint, "HTTP 429 Too Many Requests{$hint}");
        }

        if ($code !== 200) {
            throw new BookeroApiException($endpoint, "HTTP {$code}");
        }

        if (! is_array($body) || (int) ($body['result'] ?? 0) !== 1) {
            throw new BookeroApiException(
                $endpoint,
                'result=' . ($body['result'] ?? 'N/A') . ', message=' . ($body['message'] ?? '—'),
            );
        }

        return $body;
    }

    /**
     * Normalizuje odpowiedź getMonth do tablicy slotów.
     *
     * Kluczowa zasada: valid_day > 0 = prawdziwe wolne miejsce.
     * open=1 przy valid_day=0 = grafik istnieje, ale zero wolnych slotów ("Nieczynne").
     *
     * @param  array<string, mixed> $body
     * @return array<array{date: string, hour: string}>
     */
    private function normalizeSlots(array $body): array
    {
        $slots = [];

        // Format v2: { "days": { "1": {"date":"YYYY-MM-DD","valid_day":1,"open":1}, ... } }
        if (! empty($body['days']) && is_array($body['days'])) {
            foreach ($body['days'] as $day) {
                if (! is_array($day)) {
                    continue;
                }
                $date = $day['date'] ?? '';
                if ($date && (int) ($day['valid_day'] ?? 0) > 0) {
                    $slots[] = [ 'date' => (string) $date, 'hour' => '' ];
                }
            }

            return $slots;
        }

        // Fallback: stary format { "calendar": { "YYYY-MM-DD": ["16:30", ...] } }
        if (! empty($body['calendar']) && is_array($body['calendar'])) {
            foreach (array_keys($body['calendar']) as $date) {
                $slots[] = [ 'date' => (string) $date, 'hour' => '' ];
            }
        }

        return $slots;
    }
}
