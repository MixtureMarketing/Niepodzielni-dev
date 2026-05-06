<?php

declare(strict_types=1);

namespace Niepodzielni\Donations;

/**
 * Wyjątki specyficzne dla integracji darowizn (Stripe / dompdf / DB).
 *
 * Caller decyduje o logowaniu (analogicznie do BookeroApiException).
 */
class DonationsApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $context = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
