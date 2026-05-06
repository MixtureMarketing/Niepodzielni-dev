<?php

namespace App\View\Composers;

use App\Services\CalendarBuilder;
use App\Services\EventsListingService;
use Roots\Acorn\View\Composer;

class TemplateWydarzenia extends Composer
{
    protected static $views = ['template-wydarzenia'];

    public function __construct(
        private EventsListingService $service,
        private CalendarBuilder $calendar,
    ) {}

    public function with(): array
    {
        $events = $this->service->getWydarzeniaData();

        // Calendar view: filtruj tylko nadchodzące + bieżący/wybrany miesiąc
        $upcoming = array_values(array_filter(
            $events,
            fn($e) => ! empty($e['is_upcoming']) || ! empty($e['date']),
        ));

        $cal = $this->calendar->build($upcoming, ['wydarzenia']);

        return [
            'data' => $events,
            'cal'  => $cal,
        ];
    }
}
