<?php

declare(strict_types=1);

namespace Niepodzielni\Bookero;

/**
 * Wyjątek rzucany gdy API Bookero sygnalizuje przeciążenie.
 *
 * Rzucany przez BookeroApiClient::parseResponse() w dwóch sytuacjach:
 *   - HTTP 429 Too Many Requests  — serwer jawnie prosi o backoff
 *   - Timeout (cURL error 28)     — objaw przeciążenia lub rate limitingu IP
 *
 * Wychwytywany przez circuit breaker w np_bookero_worker_sync_oop():
 * ustawia transient blokady (BOOKERO_LOCKOUT_KEY) na 15 minut i
 * natychmiast zatrzymuje pętlę crona.
 *
 * W SharedCalendarService traktowany jak zwykły błąd API — graceful degradation
 * (negative cache, puste [] godziny), nie crash kalendarza frontendowego.
 */
class BookeroRateLimitException extends BookeroApiException
{
    public function __construct(
        string      $apiContext,
        string      $message,
        int         $code     = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($apiContext, $message, $code, $previous);
    }
}
