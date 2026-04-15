<?php

declare( strict_types=1 );

namespace Niepodzielni\Bookero;

/**
 * DTO konfiguracji konta Bookero zwracanej przez endpoint /init.
 *
 * Immutable value object — readonly class PHP 8.2+.
 */
readonly class AccountConfig {

    public function __construct(
        public int    $serviceId,
        public string $serviceName,
        public int    $paymentId,
    ) {}

    /**
     * Pusta konfiguracja — używana jako bezpieczny fallback gdy /init niedostępny.
     */
    public static function empty(): self {
        return new self( 0, '', 0 );
    }

    /**
     * Serializacja do tablicy — kompatybilność z get_transient() i starym kodem.
     *
     * @return array{service_id: int, service_name: string, payment_id: int}
     */
    public function toArray(): array {
        return [
            'service_id'   => $this->serviceId,
            'service_name' => $this->serviceName,
            'payment_id'   => $this->paymentId,
        ];
    }

    /**
     * Deserializacja z tablicy (np. z get_transient()).
     *
     * @param array{service_id?: int, service_name?: string, payment_id?: int} $data
     */
    public static function fromArray( array $data ): self {
        return new self(
            serviceId:   (int) ( $data['service_id']   ?? 0 ),
            serviceName: (string) ( $data['service_name'] ?? '' ),
            paymentId:   (int) ( $data['payment_id']   ?? 0 ),
        );
    }
}
