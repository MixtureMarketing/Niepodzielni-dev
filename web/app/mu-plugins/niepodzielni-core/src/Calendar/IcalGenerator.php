<?php

declare(strict_types=1);

namespace Niepodzielni\Calendar;

/**
 * iCal (RFC 5545) generator dla wydarzeń, warsztatów i grup wsparcia.
 *
 * Strefa czasowa Europe/Warsaw — używamy VTIMEZONE block zamiast UTC,
 * żeby klient kalendarza wyświetlał dokładną godzinę z PL niezależnie
 * od strefy użytkownika.
 *
 * Wszystkie dane wejściowe są escapowane wg RFC 5545:
 *   - `\\` → `\\\\`
 *   - `,`  → `\,`
 *   - `;`  → `\;`
 *   - `\n` → `\n` (literal backslash-n)
 *   - `\r` → usuwane
 *
 * Linie dłuższe niż 75 oktetów są foldowane (CRLF + space continuation).
 */
class IcalGenerator
{
    private const PRODID   = '-//Fundacja Niepodzielni//Calendar//PL';
    private const TZ       = 'Europe/Warsaw';
    private const CRLF     = "\r\n";
    private const FOLD_LEN = 75;

    /**
     * Generuje pełny VCALENDAR z N×VEVENT dla feedu (.ics download / webcal subscribe).
     *
     * @param array<int, array<string, mixed>> $events  Tablica zdarzeń w formacie z EventListBuilder.
     */
    public function generateFeed(array $events): string
    {
        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:' . $this->escape(self::PRODID);
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-CALNAME:' . $this->escape('Fundacja Niepodzielni — wydarzenia');
        $lines[] = 'X-WR-TIMEZONE:' . self::TZ;

        $lines = array_merge($lines, $this->vtimezoneBlock());

        foreach ($events as $event) {
            $vevent = $this->veventBlock($event);
            if ($vevent !== null) {
                $lines = array_merge($lines, $vevent);
            }
        }

        $lines[] = 'END:VCALENDAR';

        return $this->fold($lines);
    }

    /**
     * Generuje pojedynczy VCALENDAR + 1 VEVENT (dla downloadu „dodaj do mojego kalendarza").
     *
     * @param array<string, mixed> $event
     */
    public function generateEvent(array $event): string
    {
        return $this->generateFeed([$event]);
    }

    /**
     * Buduje VEVENT block dla pojedynczego zdarzenia.
     *
     * Akceptowane klucze w $event:
     *   - id (int|string) — wymagany
     *   - cpt (string)    — wymagany dla UID (`event-{cpt}-{id}@niepodzielni.com`)
     *   - title (string)  — wymagany (SUMMARY)
     *   - date (string)   — wymagany, format Y-m-d
     *   - time_start (string|null) — HH:MM, gdy brak → ALL-DAY event
     *   - time_end (string|null)   — HH:MM, gdy brak i time_start jest → DTEND = DTSTART + 1h
     *   - location (string|null)
     *   - description (string|null)
     *   - url (string|null)
     *
     * @param array<string, mixed> $event
     * @return string[]|null  null gdy brak wymaganych pól
     */
    private function veventBlock(array $event): ?array
    {
        $id    = (string) ($event['id']    ?? '');
        $cpt   = (string) ($event['cpt']   ?? '');
        $title = (string) ($event['title'] ?? '');
        $date  = (string) ($event['date']  ?? '');

        if ($id === '' || $cpt === '' || $title === '' || $date === '') {
            return null;
        }

        $startTime = isset($event['time_start']) ? (string) $event['time_start'] : '';
        $endTime   = isset($event['time_end'])   ? (string) $event['time_end']   : '';

        $location    = (string) ($event['location']    ?? '');
        $description = (string) ($event['description'] ?? '');
        $url         = (string) ($event['url']         ?? '');

        $uid     = sprintf('event-%s-%s@niepodzielni.com', $cpt, $id);
        $dtstamp = gmdate('Ymd\THis\Z'); // UTC
        $created = $dtstamp;

        $lines   = [];
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . $this->escape($uid);
        $lines[] = 'DTSTAMP:' . $dtstamp;
        $lines[] = 'CREATED:' . $created;
        $lines[] = 'LAST-MODIFIED:' . $dtstamp;
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'SUMMARY:' . $this->escape($title);

        // DTSTART / DTEND
        if ($startTime !== '' && $this->isValidTime($startTime)) {
            $startStamp = $this->localStamp($date, $startTime);
            $lines[] = sprintf('DTSTART;TZID=%s:%s', self::TZ, $startStamp);

            if ($endTime !== '' && $this->isValidTime($endTime)) {
                $endStamp = $this->localStamp($date, $endTime);
            } else {
                // Fallback: DTEND = DTSTART + 1h
                $endStamp = $this->localStamp($date, $this->addHour($startTime));
            }
            $lines[] = sprintf('DTEND;TZID=%s:%s', self::TZ, $endStamp);
        } else {
            // ALL-DAY event — DTSTART;VALUE=DATE
            $compactDate = str_replace('-', '', $date);
            $lines[] = sprintf('DTSTART;VALUE=DATE:%s', $compactDate);

            // ALL-DAY end = next day (RFC 5545: DTEND is exclusive)
            $nextDay = (new \DateTimeImmutable($date))->modify('+1 day')->format('Ymd');
            $lines[] = sprintf('DTEND;VALUE=DATE:%s', $nextDay);
        }

        if ($location !== '') {
            $lines[] = 'LOCATION:' . $this->escape($location);
        }

        if ($description !== '' || $url !== '') {
            $desc = $description;
            if ($url !== '') {
                $desc = trim($desc . "\n\n" . 'Szczegóły: ' . $url);
            }
            $lines[] = 'DESCRIPTION:' . $this->escape($desc);
        }

        if ($url !== '') {
            $lines[] = 'URL:' . $this->escape($url);
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * VTIMEZONE block dla Europe/Warsaw — zawiera reguły DST (CET/CEST).
     *
     * @return string[]
     */
    private function vtimezoneBlock(): array
    {
        return [
            'BEGIN:VTIMEZONE',
            'TZID:' . self::TZ,
            'X-LIC-LOCATION:' . self::TZ,
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    /**
     * Escape value zgodnie z RFC 5545 §3.3.11.
     */
    public function escape(string $value): string
    {
        $value = str_replace("\r", '', $value);
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace([',', ';'], ['\,', '\;'], $value);
        $value = str_replace("\n", '\\n', $value);
        return $value;
    }

    /**
     * Folduje linie >75 oktetów (RFC 5545 §3.1).
     *
     * @param string[] $lines
     */
    private function fold(array $lines): string
    {
        $out = '';
        foreach ($lines as $line) {
            // Liczy bajty (octets), nie znaki — UTF-8 safe.
            if (strlen($line) <= self::FOLD_LEN) {
                $out .= $line . self::CRLF;
                continue;
            }

            $remaining = $line;
            while (strlen($remaining) > self::FOLD_LEN) {
                // Bądź ostrożny z UTF-8 — nie tnij w środku znaku wielobajtowego.
                $chunk = $this->safeChunk($remaining, self::FOLD_LEN);
                $out .= $chunk . self::CRLF;
                $remaining = ' ' . substr($remaining, strlen($chunk));
            }
            $out .= $remaining . self::CRLF;
        }
        return $out;
    }

    /**
     * Wycina fragment długości max $max bajtów, nie tnąc UTF-8 w połowie znaku.
     */
    private function safeChunk(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        $chunk = substr($value, 0, $max);
        // Cofnij się jeśli przeciąłeś sekwencję wielobajtową.
        // 0xxxxxxx = 1 bajt | 110xxxxx = 2 bajty | 1110xxxx = 3 bajty | 11110xxx = 4 bajty | 10xxxxxx = continuation
        $i = strlen($chunk) - 1;
        while ($i > 0 && (ord($chunk[$i]) & 0xC0) === 0x80) {
            $i--;
        }
        // Jeśli ostatni byte to początek sekwencji multibyte, też usuwamy.
        $byte = ord($chunk[$i]);
        if (($byte & 0x80) !== 0 && ($byte & 0xC0) !== 0xC0 && ($byte & 0xE0) !== 0xC0) {
            // jesteśmy na continuation byte — już cofnięte
        } elseif (($byte & 0xC0) === 0xC0) {
            // jesteśmy na start byte multibyte — odetnij go też
            $i--;
        }

        return substr($chunk, 0, $i + 1);
    }

    private function isValidTime(string $time): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    /**
     * Buduje stamp lokalny RFC 5545 (YYYYMMDDTHHMMSS).
     */
    private function localStamp(string $date, string $time): string
    {
        $compactDate = str_replace('-', '', $date);
        $compactTime = str_replace(':', '', $time) . '00';
        return $compactDate . 'T' . $compactTime;
    }

    /**
     * Dodaje godzinę do HH:MM (z owijaniem w 24h).
     */
    private function addHour(string $time): string
    {
        $parts = explode(':', $time);
        $h = ((int) $parts[0] + 1) % 24;
        return sprintf('%02d:%s', $h, $parts[1]);
    }
}
