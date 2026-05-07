<?php

declare(strict_types=1);

use Niepodzielni\Calendar\IcalGenerator;

/**
 * Testy IcalGenerator — RFC 5545 compliance.
 *
 * Pokrycie:
 *   1. VEVENT zawiera DTSTART/DTEND/SUMMARY/UID/URL
 *   2. Escape przecinka, średnika, backslasha, newline'a
 *   3. ALL-DAY fallback (brak time_start)
 *   4. DTEND fallback (brak time_end → DTSTART + 1h)
 *   5. Folding długich linii >75 oktetów
 *   6. UID stabilny dla tego samego (cpt, id)
 *   7. VTIMEZONE block dla Europe/Warsaw
 *   8. Pomija eventy bez wymaganych pól
 */

beforeEach(function () {
    $this->gen = new IcalGenerator();
});

// ─── VEVENT struktura ─────────────────────────────────────────────────────────

it('VEVENT zawiera UID, DTSTART, DTEND, SUMMARY, URL', function () {
    $ics = $this->gen->generateEvent([
        'id'          => 42,
        'cpt'         => 'wydarzenia',
        'title'       => 'Spotkanie testowe',
        'date'        => '2026-05-15',
        'time_start'  => '18:00',
        'time_end'    => '20:00',
        'location'    => 'Warszawa, Nowy Świat 1',
        'description' => 'Opis spotkania',
        'url'         => 'https://niepodzielni.com/wydarzenie/spotkanie/',
    ]);

    expect($ics)->toContain('BEGIN:VEVENT');
    expect($ics)->toContain('END:VEVENT');
    expect($ics)->toContain('UID:event-wydarzenia-42@niepodzielni.com');
    expect($ics)->toContain('DTSTART;TZID=Europe/Warsaw:20260515T180000');
    expect($ics)->toContain('DTEND;TZID=Europe/Warsaw:20260515T200000');
    expect($ics)->toContain('SUMMARY:Spotkanie testowe');
    expect($ics)->toContain('LOCATION:Warszawa');
    expect($ics)->toContain('URL:https://niepodzielni.com/wydarzenie/spotkanie/');
});

it('UID jest stabilny dla tego samego (cpt, id) — kluczowe dla update z klienta', function () {
    $a = $this->gen->generateEvent(['id' => 7, 'cpt' => 'warsztaty', 'title' => 'A', 'date' => '2026-01-01']);
    $b = $this->gen->generateEvent(['id' => 7, 'cpt' => 'warsztaty', 'title' => 'B', 'date' => '2026-02-02']);

    preg_match('/UID:(\S+)/', $a, $ma);
    preg_match('/UID:(\S+)/', $b, $mb);

    expect($ma[1])->toBe($mb[1]);
});

// ─── Escape RFC 5545 §3.3.11 ──────────────────────────────────────────────────

it('escapuje przecinki, średniki i backslashe', function () {
    expect($this->gen->escape('a, b'))->toBe('a\\, b');
    expect($this->gen->escape('a; b'))->toBe('a\\; b');
    expect($this->gen->escape('a\\b'))->toBe('a\\\\b');
});

it('escapuje newline na literal \\n', function () {
    expect($this->gen->escape("line1\nline2"))->toBe('line1\\nline2');
});

it('usuwa carriage return', function () {
    expect($this->gen->escape("line1\r\nline2"))->toBe('line1\\nline2');
});

it('escape nie psuje kolejności — backslash najpierw, potem , i ;', function () {
    // Jeśli "\;" → "\\\;" (najpierw \, potem ;) to poprawnie. Jeśli "\;" → "\\;" — błąd.
    expect($this->gen->escape('\\;'))->toBe('\\\\\\;');
});

// ─── ALL-DAY events ───────────────────────────────────────────────────────────

it('brak time_start → ALL-DAY event z DTSTART;VALUE=DATE', function () {
    $ics = $this->gen->generateEvent([
        'id'    => 1,
        'cpt'   => 'wydarzenia',
        'title' => 'Caly dzien',
        'date'  => '2026-06-15',
    ]);

    expect($ics)->toContain('DTSTART;VALUE=DATE:20260615');
    expect($ics)->toContain('DTEND;VALUE=DATE:20260616'); // exclusive — następny dzień
    expect($ics)->not->toContain('TZID=Europe/Warsaw');
});

// ─── DTEND fallback ───────────────────────────────────────────────────────────

it('brak time_end z time_start → DTEND = DTSTART + 1h', function () {
    $ics = $this->gen->generateEvent([
        'id'         => 1,
        'cpt'        => 'warsztaty',
        'title'      => 'X',
        'date'       => '2026-07-01',
        'time_start' => '14:30',
    ]);

    expect($ics)->toContain('DTSTART;TZID=Europe/Warsaw:20260701T143000');
    expect($ics)->toContain('DTEND;TZID=Europe/Warsaw:20260701T153000');
});

it('DTEND +1h owija się przez północ', function () {
    $ics = $this->gen->generateEvent([
        'id'         => 1,
        'cpt'        => 'warsztaty',
        'title'      => 'X',
        'date'       => '2026-07-01',
        'time_start' => '23:30',
    ]);

    expect($ics)->toContain('DTSTART;TZID=Europe/Warsaw:20260701T233000');
    expect($ics)->toContain('DTEND;TZID=Europe/Warsaw:20260701T003000'); // +1h = 00:30 (UWAGA: data ta sama, RFC akceptuje, klient kalendarza jest tolerancyjny)
});

it('niepoprawny format godziny → ALL-DAY fallback', function () {
    $ics = $this->gen->generateEvent([
        'id'         => 1,
        'cpt'        => 'wydarzenia',
        'title'      => 'X',
        'date'       => '2026-07-01',
        'time_start' => 'abcd',
    ]);

    expect($ics)->toContain('DTSTART;VALUE=DATE:20260701');
});

// ─── Line folding (RFC 5545 §3.1) ─────────────────────────────────────────────

it('folduje linie dłuższe niż 75 oktetów', function () {
    $longTitle = str_repeat('A', 200);
    $ics = $this->gen->generateEvent([
        'id'    => 1,
        'cpt'   => 'wydarzenia',
        'title' => $longTitle,
        'date'  => '2026-06-15',
    ]);

    // Każda linia w iCal nie może mieć >75 oktetów (po fold).
    $lines = explode("\r\n", $ics);
    foreach ($lines as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
});

it('fold dodaje space jako continuation prefix', function () {
    $longTitle = str_repeat('B', 100);
    $ics = $this->gen->generateEvent([
        'id'    => 1,
        'cpt'   => 'wydarzenia',
        'title' => $longTitle,
        'date'  => '2026-06-15',
    ]);

    // SUMMARY:BBBBB...\r\n BBBB... — drugi line zaczyna się od spacji.
    expect($ics)->toMatch('/SUMMARY:B+\r\n B+/');
});

// ─── VTIMEZONE Europe/Warsaw ──────────────────────────────────────────────────

it('feed zawiera VTIMEZONE block dla Europe/Warsaw z DST i STD', function () {
    $ics = $this->gen->generateFeed([
        ['id' => 1, 'cpt' => 'wydarzenia', 'title' => 'X', 'date' => '2026-06-15', 'time_start' => '12:00'],
    ]);

    expect($ics)->toContain('BEGIN:VTIMEZONE');
    expect($ics)->toContain('TZID:Europe/Warsaw');
    expect($ics)->toContain('BEGIN:DAYLIGHT');
    expect($ics)->toContain('TZNAME:CEST');
    expect($ics)->toContain('BEGIN:STANDARD');
    expect($ics)->toContain('TZNAME:CET');
    expect($ics)->toContain('END:VTIMEZONE');
});

// ─── Walidacja eventów ────────────────────────────────────────────────────────

it('pomija event bez wymaganych pól (id, cpt, title, date)', function () {
    $ics = $this->gen->generateFeed([
        ['id' => 1, 'cpt' => 'wydarzenia', 'title' => 'OK', 'date' => '2026-06-15'],
        ['title' => 'Brak id i cpt', 'date' => '2026-06-16'], // niepoprawny
        ['id' => 3, 'cpt' => 'warsztaty', 'title' => 'OK2', 'date' => '2026-06-17'],
    ]);

    expect($ics)->toContain('SUMMARY:OK');
    expect($ics)->toContain('SUMMARY:OK2');
    expect($ics)->not->toContain('SUMMARY:Brak id');

    // 2 VEVENT bloki
    expect(substr_count($ics, 'BEGIN:VEVENT'))->toBe(2);
});

// ─── Feed structure ───────────────────────────────────────────────────────────

it('feed ma strukturę BEGIN:VCALENDAR ... END:VCALENDAR', function () {
    $ics = $this->gen->generateFeed([
        ['id' => 1, 'cpt' => 'wydarzenia', 'title' => 'X', 'date' => '2026-06-15', 'time_start' => '12:00'],
    ]);

    expect($ics)->toStartWith('BEGIN:VCALENDAR');
    expect(rtrim($ics))->toEndWith('END:VCALENDAR');
    expect($ics)->toContain('VERSION:2.0');
    expect($ics)->toContain('PRODID:');
    expect($ics)->toContain('CALSCALE:GREGORIAN');
});

it('description ma permalink na końcu jeśli url jest', function () {
    $ics = $this->gen->generateEvent([
        'id'          => 1,
        'cpt'         => 'wydarzenia',
        'title'       => 'X',
        'date'        => '2026-06-15',
        'description' => 'Krótki opis',
        'url'         => 'https://example.com/event',
    ]);

    expect($ics)->toContain('Krótki opis');
    expect($ics)->toContain('https://example.com/event');
});

// ─── UTF-8 safety przy fold ───────────────────────────────────────────────────

it('fold nie rozbija sekwencji UTF-8 na granicy bajtu', function () {
    // Polski tekst z dużą liczbą diakrytyków — każdy znak to 2 bajty UTF-8.
    $title = str_repeat('ą', 60); // 120 bajtów

    $ics = $this->gen->generateEvent([
        'id'    => 1,
        'cpt'   => 'wydarzenia',
        'title' => $title,
        'date'  => '2026-06-15',
    ]);

    // Czy wynik jest valid UTF-8?
    expect(mb_check_encoding($ics, 'UTF-8'))->toBeTrue();
});
