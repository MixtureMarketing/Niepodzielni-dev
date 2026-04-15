<?php

declare( strict_types=1 );

namespace Niepodzielni\Bookero;

/**
 * DTO wyniku synchronizacji jednego psychologa.
 *
 * Immutable value object zwracany przez BookeroSyncService::syncSingleWorker().
 */
readonly class SyncResult {

    public function __construct(
        public int    $postId,
        public bool   $hasPelny,
        public bool   $hasNisko,
        public string $nearestPelny,
        public string $nearestNisko,
    ) {}

    /**
     * Czy psycholog miał przynajmniej jedno worker ID (był w ogóle synchronizowany).
     */
    public function hasSynced(): bool {
        return $this->hasPelny || $this->hasNisko;
    }

    /**
     * Czy znaleziono jakikolwiek wolny termin (w dowolnym typie konta).
     */
    public function hasAnyAvailability(): bool {
        return $this->nearestPelny !== '' || $this->nearestNisko !== '';
    }
}
