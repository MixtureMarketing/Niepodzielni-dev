<?php

namespace App\View\Composers;

use App\Services\CalendarBuilder;
use App\Services\EventsListingService;
use Roots\Acorn\View\Composer;

class TemplateWarsztatyGrupy extends Composer
{
    protected static $views = ['template-warsztaty-grupy'];

    public function __construct(
        private EventsListingService $service,
        private CalendarBuilder $calendar,
    ) {}

    public function with(): array
    {
        $events = $this->service->getWorkshopsData();

        // Tylko aktywne wydarzenia (data >= today) trafiają do kalendarza.
        $active = array_values(array_filter(
            $events,
            fn($e) => ! empty($e['is_active']),
        ));

        $cal = $this->calendar->build($active, ['warsztaty', 'grupy-wsparcia']);

        return [
            'data' => $events,
            'cal'  => $cal,
        ];
    }
}
