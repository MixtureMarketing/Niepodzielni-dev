<?php

declare(strict_types=1);

namespace Niepodzielni\Bookero;

/**
 * DTO konfiguracji konta Bookero zwracanej przez endpoint /init.
 *
 * Immutable value object — readonly class PHP 8.2+.
 *
 * workerServiceMap: workerId (string) → serviceId (int)
 *   Zbudowana z services_list[].workers z /init.
 *   Pozwala użyć właściwego service_id per worker zamiast jednego globalnego.
 *   Gdy worker nie jest w mapie, fallback do $serviceId (service z największą liczbą workers).
 */
readonly class AccountConfig
{
    /**
     * @param array<string, int> $workerServiceMap  workerId → serviceId
     */
    public function __construct(
        public int    $serviceId,
        public string $serviceName,
        public int    $paymentId,
        public array  $workerServiceMap = [],
    ) {}

    /**
     * Zwraca service_id dla konkretnego workera.
     * Fallback do domyślnego $serviceId gdy worker nie jest w mapie.
     */
    public function getServiceIdForWorker(string $workerId): int
    {
        return $this->workerServiceMap[$workerId] ?? $this->serviceId;
    }

    /**
     * Pusta konfiguracja — używana jako bezpieczny fallback gdy /init niedostępny.
     */
    public static function empty(): self
    {
        return new self(0, '', 0);
    }

    /**
     * Serializacja do tablicy — kompatybilność z get_transient() i starym kodem.
     *
     * @return array{service_id: int, service_name: string, payment_id: int, worker_service_map: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'service_id'         => $this->serviceId,
            'service_name'       => $this->serviceName,
            'payment_id'         => $this->paymentId,
            'worker_service_map' => $this->workerServiceMap,
        ];
    }

    /**
     * Deserializacja z tablicy (np. z get_transient()).
     *
     * @param array{service_id?: int, service_name?: string, payment_id?: int, worker_service_map?: array<string, int>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            serviceId: (int) ($data['service_id']   ?? 0),
            serviceName: (string) ($data['service_name'] ?? ''),
            paymentId: (int) ($data['payment_id']   ?? 0),
            workerServiceMap: (array) ($data['worker_service_map'] ?? []),
        );
    }
}
