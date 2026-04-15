<?php

declare(strict_types=1);

namespace Niepodzielni\Bookero;

/**
 * Wyjątek rzucany przez BookeroApiClient przy każdym błędzie komunikacji z API.
 *
 * Przechowuje kontekst wywołania (np. 'getMonth', 'init') jako osobne pole,
 * co umożliwia precyzyjne logowanie bez parsowania wiadomości.
 */
class BookeroApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $apiContext,
        string                 $message,
        int                    $code     = 0,
        ?\Throwable            $previous = null,
    ) {
        parent::__construct("[Bookero:{$apiContext}] {$message}", $code, $previous);
    }
}
