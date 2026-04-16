<?php

declare(strict_types=1);

use Niepodzielni\Bookero\PsychologistRepository;

/**
 * Testy PsychologistRepository — izolacja negative cache i semantyka transientów.
 *
 * SharedCalendarService jest zintegrowany z PsychologistRepository przez wstrzykiwanie.
 * Zamiast testować cały serwis (wymaga WP_Query, wielu stubów), testujemy repository
 * bezpośrednio — to jest właściwa jednostka odpowiedzialna za izolację cache.
 *
 * Testy pokrywają:
 *   1. N+1 isolation — setHoursErrorTransient dla worker-A NIE blokuje worker-B
 *      (klucz zawiera workerId w hashu → per-worker izolacja)
 *   2. Izolacja per-datę — ta sama data, różni workerzy = różne klucze
 *   3. Izolacja per-typ — pelnoplatny i nisko NIE współdzielą negative cache
 *   4. getMonthTransient zwraca false przy braku w cache (wymusza wywołanie API)
 *   5. setMonthTransientBackoff zapisuje pustą tablicę (nie false) — cron widzi "zsync."
 */

// ─── Testy negative cache (hoursErrorTransient) ───────────────────────────────

it('setHoursErrorTransient dla worker-A nie wpływa na worker-B w tej samej dacie', function () {
    $repo = new PsychologistRepository();
    $typ  = 'pelnoplatny';
    $date = '2026-05-15';

    // WHEN — worker-A ma błąd API
    $repo->setHoursErrorTransient($typ, 'worker-A', $date);

    // THEN — worker-B nie jest dotknięty
    expect($repo->getHoursErrorTransient($typ, 'worker-A', $date))->toBeTrue()
        ->and($repo->getHoursErrorTransient($typ, 'worker-B', $date))->toBeFalse();
});

it('setHoursErrorTransient jest izolowany per datę', function () {
    $repo     = new PsychologistRepository();
    $workerId = 'worker-99';
    $typ      = 'nisko';

    // WHEN — błąd tylko dla daty 2026-05-15
    $repo->setHoursErrorTransient($typ, $workerId, '2026-05-15');

    // THEN — inna data nie jest zablokowana
    expect($repo->getHoursErrorTransient($typ, $workerId, '2026-05-15'))->toBeTrue()
        ->and($repo->getHoursErrorTransient($typ, $workerId, '2026-05-16'))->toBeFalse();
});

it('setHoursErrorTransient jest izolowany per typ konta', function () {
    $repo     = new PsychologistRepository();
    $workerId = 'worker-42';
    $date     = '2026-06-01';

    // WHEN — błąd tylko dla pelnoplatny
    $repo->setHoursErrorTransient('pelnoplatny', $workerId, $date);

    // THEN — nisko nie jest zablokowane
    expect($repo->getHoursErrorTransient('pelnoplatny', $workerId, $date))->toBeTrue()
        ->and($repo->getHoursErrorTransient('nisko', $workerId, $date))->toBeFalse();
});

it('getHoursErrorTransient zwraca false przed ustawieniem flagi', function () {
    $repo = new PsychologistRepository();

    expect($repo->getHoursErrorTransient('pelnoplatny', 'worker-fresh', '2026-07-01'))->toBeFalse();
});

// ─── Testy month transient cache ─────────────────────────────────────────────

it('getMonthTransient zwraca false gdy brak w cache', function () {
    $repo = new PsychologistRepository();

    expect($repo->getMonthTransient('pelnoplatny', 'worker-X', 0))->toBeFalse();
});

it('getMonthTransient zwraca dane po setMonthTransient', function () {
    $repo  = new PsychologistRepository();
    $slots = [
        ['date' => '2026-06-15', 'hour' => ''],
        ['date' => '2026-06-22', 'hour' => ''],
    ];

    $repo->setMonthTransient('pelnoplatny', 'worker-X', 0, $slots);

    expect($repo->getMonthTransient('pelnoplatny', 'worker-X', 0))->toBe($slots);
});

it('setMonthTransientBackoff zapisuje pustą tablicę (nie false)', function () {
    $repo = new PsychologistRepository();

    $repo->setMonthTransientBackoff('nisko', 'worker-err', 1);

    // Backoff to pusta tablica, nie false — cron widzi "był już cache" i pomija
    $result = $repo->getMonthTransient('nisko', 'worker-err', 1);
    expect($result)->toBeArray()->toBeEmpty();
});

it('clearMonthTransients usuwa transient dla 3 miesięcy', function () {
    $repo = new PsychologistRepository();

    // Wypełnij transienty dla miesięcy 0,1,2
    $repo->setMonthTransient('pelnoplatny', 'worker-del', 0, [['date' => '2026-06-01', 'hour' => '']]);
    $repo->setMonthTransient('pelnoplatny', 'worker-del', 1, [['date' => '2026-07-01', 'hour' => '']]);
    $repo->setMonthTransient('pelnoplatny', 'worker-del', 2, [['date' => '2026-08-01', 'hour' => '']]);

    // Wyczyść
    $repo->clearMonthTransients('worker-del', 'pelnoplatny', 3);

    // Wszystkie miesiące wyczyszczone
    expect($repo->getMonthTransient('pelnoplatny', 'worker-del', 0))->toBeFalse()
        ->and($repo->getMonthTransient('pelnoplatny', 'worker-del', 1))->toBeFalse()
        ->and($repo->getMonthTransient('pelnoplatny', 'worker-del', 2))->toBeFalse();
});

// ─── Testy kluczy cache ───────────────────────────────────────────────────────

it('monthCacheKey dla różnych typów generuje różne klucze', function () {
    $repo = new PsychologistRepository();

    $keyPelny = $repo->monthCacheKey('pelnoplatny', 'worker-1', 0);
    $keyNisko = $repo->monthCacheKey('nisko', 'worker-1', 0);

    expect($keyPelny)->not->toBe($keyNisko);
});

it('dayCacheKey dla różnych dat generuje różne klucze', function () {
    $repo = new PsychologistRepository();

    $key1 = $repo->dayCacheKey('pelnoplatny', 'worker-1', '2026-06-01');
    $key2 = $repo->dayCacheKey('pelnoplatny', 'worker-1', '2026-06-02');

    expect($key1)->not->toBe($key2);
});

it('sharedMonthCacheKey jest deterministyczny', function () {
    $repo = new PsychologistRepository();

    expect($repo->sharedMonthCacheKey('pelnoplatny', 0))
        ->toBe('np_bk_month_pelnoplatny_0');
});

// ─── Testy postmeta (saveNearestDate / clearNearestDate) ─────────────────────

it('saveNearestDate zapisuje datę do postmeta', function () {
    $repo = new PsychologistRepository();

    $repo->saveNearestDate(100, 'pelnoplatny', '15 maja 2026');

    expect(get_post_meta(100, 'najblizszy_termin_pelnoplatny', true))
        ->toBe('["15 maja 2026"]');
})->skip('saveNearestDate używa update_post_meta z wp_json_encode — test integracyjny');

it('saveNearestDate i clearNearestDate zachowują spójność', function () {
    $repo = new PsychologistRepository();

    // Zapisz
    $repo->saveNearestDate(200, 'nisko', '20 czerwca 2026');
    expect(get_post_meta(200, 'najblizszy_termin_niskoplatny', true))->not->toBeEmpty();

    // Wyczyść
    $repo->clearNearestDate(200, 'nisko');
    expect(get_post_meta(200, 'najblizszy_termin_niskoplatny', true))->toBe('');
});

// ─── Test configCacheKey ──────────────────────────────────────────────────────

it('configCacheKey jest deterministyczny i bezpieczny dla klucza WP', function () {
    $repo = new PsychologistRepository();

    expect($repo->configCacheKey('pelnoplatny'))->toBe('np_bk_cfg_pelnoplatny')
        ->and($repo->configCacheKey('nisko'))->toBe('np_bk_cfg_nisko');
});
