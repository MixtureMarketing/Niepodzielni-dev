<?php

declare(strict_types=1);

use Niepodzielni\Bookero\AccountConfig;
use Niepodzielni\Bookero\BookeroApiClient;
use Niepodzielni\Bookero\BookeroApiException;
use Niepodzielni\Bookero\BookeroRateLimitException;
use Niepodzielni\Bookero\BookeroSyncService;
use Niepodzielni\Bookero\PsychologistRepository;
use Niepodzielni\Bookero\SyncResult;

/**
 * Testy BookeroSyncService — logika biznesowa synchronizacji terminów.
 *
 * Strategia mockowania: anonimowe klasy rozszerzające BookeroApiClient
 * i PsychologistRepository. Brak Mockery — zero zewnętrznych zależności.
 *
 * Testy pokrywają:
 *   1. BookeroRateLimitException propaguje się z syncSingleWorker()
 *      (circuit breaker w cron musi ją złapać)
 *   2. Zwykły BookeroApiException (nie-429) jest pochłaniany — serwis zwraca
 *      SyncResult z nearestPelny = '' zamiast rzucać wyjątek
 */

// ─── Fabryki mocków ───────────────────────────────────────────────────────────

/**
 * Tworzy mock repozytorium z pojedynczym worker ID.
 * Wszystkie operacje zapisu są no-ops — interesuje nas tylko propagacja wyjątków.
 */
function makeRepoWithWorker(string $workerId = 'worker-123'): PsychologistRepository
{
    return new class ($workerId) extends PsychologistRepository {
        public function __construct(private string $workerId) {}

        public function getWorkerId(int $postId, string $typ): string
        {
            // Zwraca worker ID tylko dla 'pelnoplatny', żeby test był przewidywalny
            return $typ === 'pelnoplatny' ? $this->workerId : '';
        }

        public function getMonthTransient(string $typ, string $workerId, int $plusMonths): array|false
        {
            return false; // Zawsze cache miss — wymusza wywołanie API
        }

        public function getAccountConfigTransient(string $typ): array|false
        {
            // Zwraca gotową konfigurację — omija getAccountConfig() → API /init
            return ['service_id' => 1, 'service_name' => 'Konsultacja', 'payment_id' => 0];
        }

        // ─── Operacje zapisu — no-ops ──────────────────────────────────────────
        public function setMonthTransient(string $typ, string $workerId, int $plusMonths, array $slots): void {}
        public function setMonthTransientBackoff(string $typ, string $workerId, int $plusMonths): void {}
        public function saveNearestDate(int $postId, string $typ, string $nearestLabel): void {}
        public function clearNearestDate(int $postId, string $typ): void {}
        public function saveAvailableDates(int $postId, string $typ, array $dates): void {}
        public function getCachedHours(int $postId, string $typ, string $date): ?array
        {
            return []; // Symuluje "już w cache" — blokuje wywołanie prewarmHours → API
        }
        public function touchSyncTimestamp(int $postId): void {}
    };
}

/**
 * Tworzy mock klienta API który rzuca BookeroRateLimitException na getMonth().
 */
function makeRateLimitClient(): BookeroApiClient
{
    return new class extends BookeroApiClient {
        public function getMonth(
            string $calHash,
            string $workerId,
            int    $serviceId,
            int    $plusMonths,
        ): array {
            throw new BookeroRateLimitException('getMonth', 'HTTP 429 Too Many Requests');
        }
    };
}

/**
 * Tworzy mock klienta API który rzuca zwykły BookeroApiException na getMonth().
 */
function makeRegularErrorClient(): BookeroApiClient
{
    return new class extends BookeroApiClient {
        public function getMonth(
            string $calHash,
            string $workerId,
            int    $serviceId,
            int    $plusMonths,
        ): array {
            throw new BookeroApiException('getMonth', 'HTTP 503');
        }
    };
}

/**
 * Tworzy mock klienta API który zwraca puste sloty (brak terminów).
 */
function makeEmptySlotsClient(): BookeroApiClient
{
    return new class extends BookeroApiClient {
        public function getMonth(
            string $calHash,
            string $workerId,
            int    $serviceId,
            int    $plusMonths,
        ): array {
            return [];
        }
    };
}

// ─── Testy ────────────────────────────────────────────────────────────────────

it('propaguje BookeroRateLimitException z syncSingleWorker gdy API zwraca HTTP 429', function () {
    // GIVEN — klient rzuca RateLimit, repo ma workera
    $client  = makeRateLimitClient();
    $repo    = makeRepoWithWorker('worker-abc');
    $service = new BookeroSyncService($client, $repo);

    // WHEN / THEN — wyjątek musi przejść przez serwis, nie zostać pochłonięty
    expect(fn () => $service->syncSingleWorker(42))
        ->toThrow(BookeroRateLimitException::class);
});

it('zwraca SyncResult z pustym nearestPelny gdy API zwraca błąd 503 (nie-rate-limit)', function () {
    // GIVEN — klient rzuca zwykły błąd API (nie 429, nie timeout)
    $client  = makeRegularErrorClient();
    $repo    = makeRepoWithWorker('worker-def');
    $service = new BookeroSyncService($client, $repo);

    // WHEN — BookeroApiException (503) nie powinna propagować się na zewnątrz
    $result = $service->syncSingleWorker(42);

    // THEN — serwis pochłania błąd i zwraca pusty wynik
    expect($result)->toBeInstanceOf(SyncResult::class)
        ->and($result->nearestPelny)->toBe('')
        ->and($result->nearestNisko)->toBe('')
        ->and($result->hasPelny)->toBeTrue()  // worker był, ale brak terminów
        ->and($result->hasAnyAvailability())->toBeFalse();
});

it('zwraca SyncResult bez terminów gdy API zwraca puste sloty', function () {
    // GIVEN — brak błędu API, ale zero dostępnych dni
    $client  = makeEmptySlotsClient();
    $repo    = makeRepoWithWorker('worker-xyz');
    $service = new BookeroSyncService($client, $repo);

    // WHEN
    $result = $service->syncSingleWorker(99);

    // THEN
    expect($result->nearestPelny)->toBe('')
        ->and($result->hasSynced())->toBeTrue()
        ->and($result->hasAnyAvailability())->toBeFalse();
});

it('zwraca SyncResult z hasPelny=false gdy psycholog nie ma worker ID', function () {
    // GIVEN — repo bez żadnego worker ID
    $repo = new class extends PsychologistRepository {
        public function getWorkerId(int $postId, string $typ): string
        {
            return ''; // Brak worker ID dla obu typów
        }
    };

    $client  = makeEmptySlotsClient();
    $service = new BookeroSyncService($client, $repo);

    // WHEN
    $result = $service->syncSingleWorker(7);

    // THEN — worker nieobecny, żadna synchronizacja nie nastąpiła
    expect($result->hasPelny)->toBeFalse()
        ->and($result->hasNisko)->toBeFalse()
        ->and($result->hasSynced())->toBeFalse();
});

it('SyncResult::hasSynced() zwraca true gdy przynajmniej jedno worker ID istnieje', function () {
    $result = new SyncResult(
        postId: 1,
        hasPelny: true,
        hasNisko: false,
        nearestPelny: '',
        nearestNisko: '',
    );

    expect($result->hasSynced())->toBeTrue()
        ->and($result->hasAnyAvailability())->toBeFalse();
});

it('SyncResult::hasAnyAvailability() zwraca true gdy nearestPelny niepuste', function () {
    $result = new SyncResult(
        postId: 2,
        hasPelny: true,
        hasNisko: false,
        nearestPelny: '15 maja 2026',
        nearestNisko: '',
    );

    expect($result->hasAnyAvailability())->toBeTrue();
});
