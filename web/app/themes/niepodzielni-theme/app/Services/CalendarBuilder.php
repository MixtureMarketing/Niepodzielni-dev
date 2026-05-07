<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Buduje strukturę danych dla widoku kalendarza miesięcznego.
 *
 * Wywoływany z View Composerów (TemplateWydarzenia, TemplateWarsztatyGrupy)
 * — przyjmuje płaską listę wydarzeń (z EventsListingService) i parametry
 * URL (`?view=`, `?month=`), zwraca strukturę gotową do renderu w Blade:
 *
 *   [
 *     'view'         => 'list' | 'calendar',
 *     'monthLabel'   => 'Maj 2026',
 *     'monthFirst'   => DateTimeImmutable, // 1. dzień miesiąca
 *     'weeks'        => array<int, array<int, array{date: string, isCurrent: bool, isToday: bool, events: array[]}>>,
 *     'prevMonthUrl' => string,
 *     'nextMonthUrl' => string,
 *     'webcalUrl'    => string,
 *     'cpts'         => string[], // CPT'y w scope tej strony
 *   ]
 */
class CalendarBuilder
{
    private const PL_MONTHS = [
        1 => 'Styczeń', 2 => 'Luty', 3 => 'Marzec', 4 => 'Kwiecień',
        5 => 'Maj', 6 => 'Czerwiec', 7 => 'Lipiec', 8 => 'Sierpień',
        9 => 'Wrzesień', 10 => 'Październik', 11 => 'Listopad', 12 => 'Grudzień',
    ];

    /**
     * @param array<int, array<string, mixed>> $events  Płaska lista wydarzeń (z EventsListingService).
     * @param string[] $cpts  CPT'y w scope tej strony (do filtra w webcal feed).
     * @return array<string, mixed>
     */
    public function build(array $events, array $cpts): array
    {
        $view  = $this->resolveView();
        $month = $this->resolveMonth();

        $monthFirst = new \DateTimeImmutable($month . '-01');
        $monthLast  = $monthFirst->modify('last day of this month');

        // Grupuj eventy po dacie (Y-m-d).
        $byDate = [];
        foreach ($events as $event) {
            $date = (string) ($event['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $byDate[$date][] = $this->normalizeEvent($event);
        }

        $weeks = $this->buildWeeks($monthFirst, $monthLast, $byDate);

        $today        = current_time('Y-m-d');
        $prevMonth    = $monthFirst->modify('-1 month')->format('Y-m');
        $nextMonth    = $monthFirst->modify('+1 month')->format('Y-m');
        $baseUrl      = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
        $prevMonthUrl = $baseUrl . '?view=calendar&month=' . $prevMonth;
        $nextMonthUrl = $baseUrl . '?view=calendar&month=' . $nextMonth;

        // webcal:// URL — feed dla CPT tej strony
        $feedQuery = http_build_query(['cpt' => implode(',', $cpts)]);
        $feedUrl   = home_url('/wp-json/niepodzielni/v1/calendar/feed.ics?' . $feedQuery);
        $webcalUrl = preg_replace('#^https?://#', 'webcal://', $feedUrl);

        return [
            'view'         => $view,
            'monthLabel'   => self::PL_MONTHS[(int) $monthFirst->format('n')] . ' ' . $monthFirst->format('Y'),
            'monthFirst'   => $monthFirst,
            'today'        => $today,
            'weeks'        => $weeks,
            'prevMonthUrl' => $prevMonthUrl,
            'nextMonthUrl' => $nextMonthUrl,
            'webcalUrl'    => $webcalUrl,
            'cpts'         => $cpts,
            'monthEvents'  => array_filter($events, fn($e) => str_starts_with((string) ($e['date'] ?? ''), $monthFirst->format('Y-m'))),
        ];
    }

    /**
     * Buduje 6×7 grid (lub 5×7 / 4×7 zależnie od miesiąca) zaczynający się w poniedziałek.
     *
     * @param array<string, array<int, array<string, mixed>>> $byDate
     * @return array<int, array<int, array{date: string, day: int, isCurrent: bool, isToday: bool, events: array[]}>>
     */
    private function buildWeeks(\DateTimeImmutable $monthFirst, \DateTimeImmutable $monthLast, array $byDate): array
    {
        $today = current_time('Y-m-d');

        // ISO-8601: Mon=1, Sun=7
        $startWeekday = (int) $monthFirst->format('N');
        $gridStart    = $monthFirst->modify('-' . ($startWeekday - 1) . ' days');

        $endWeekday   = (int) $monthLast->format('N');
        $gridEnd      = $monthLast->modify('+' . (7 - $endWeekday) . ' days');

        $weeks   = [];
        $week    = [];
        $current = $gridStart;
        $month   = (int) $monthFirst->format('m');

        while ($current <= $gridEnd) {
            $iso = $current->format('Y-m-d');
            $week[] = [
                'date'      => $iso,
                'day'       => (int) $current->format('j'),
                'isCurrent' => (int) $current->format('m') === $month,
                'isToday'   => $iso === $today,
                'events'    => $byDate[$iso] ?? [],
            ];
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
            $current = $current->modify('+1 day');
        }

        return $weeks;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function normalizeEvent(array $event): array
    {
        return [
            'id'       => (int) ($event['id'] ?? 0),
            'cpt'      => (string) ($event['post_type'] ?? 'wydarzenia'),
            'title'    => (string) ($event['title'] ?? ''),
            'time'     => (string) ($event['time_start'] ?? $event['time'] ?? ''),
            'link'     => (string) ($event['link'] ?? ''),
        ];
    }

    private function resolveView(): string
    {
        $param = isset($_GET['view']) ? sanitize_key((string) $_GET['view']) : 'list';
        return $param === 'calendar' ? 'calendar' : 'list';
    }

    private function resolveMonth(): string
    {
        $param = isset($_GET['month']) ? (string) $_GET['month'] : '';
        if (preg_match('/^(\d{4})-(\d{2})$/', $param, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            if ($y >= 2000 && $y <= 2100 && $mo >= 1 && $mo <= 12) {
                return sprintf('%04d-%02d', $y, $mo);
            }
        }
        return current_time('Y-m');
    }
}
