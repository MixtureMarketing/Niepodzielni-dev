<?php

declare(strict_types=1);

namespace Niepodzielni\Bookero;

/**
 * DTO jednego psychologa z wszystkimi danymi potrzebnymi przez SharedCalendarService.
 *
 * Budowany przez PsychologistRepository::getAllWorkersWithMeta() — jeden obiekt
 * na post, wszystkie pola wypełnione z WP object cache bez dodatkowych zapytań SQL.
 *
 * @immutable
 */
readonly class WorkerRecord
{
    /**
     * @param string[]              $slots      Daty YYYY-MM-DD z bookero_slots_*
     * @param array<string,string[]> $hoursCache Mapa date → hours[] z bookero_hours_*
     */
    public function __construct(
        public int    $postId,
        public string $name,
        public string $workerId,    // bookero_id_pelny lub bookero_id_niski
        public array  $slots,
        public string $nearestTerm, // "15 maja 2026" — fallback gdy slots puste
        public string $price,
        public string $rodzaj,
        public string $avatar,
        public string $profileUrl,
        public array  $hoursCache,
    ) {}

    /**
     * Czy psycholog ma podany dzień w swoich dostępnych slotach.
     */
    public function hasDate(string $date): bool
    {
        return in_array($date, $this->slots, true);
    }

    /**
     * Zwraca godziny dla daty z lokalnego cache (bez DB).
     * null = brak wpisu, [] = synchronizowane / brak wolnych.
     *
     * @return string[]|null
     */
    public function cachedHoursFor(string $date): ?array
    {
        return array_key_exists($date, $this->hoursCache)
            ? (array) $this->hoursCache[ $date ]
            : null;
    }
}
