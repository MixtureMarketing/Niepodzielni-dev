<?php

namespace App\View\Composers;

use App\Services\EventsListingService;
use Roots\Acorn\View\Composer;

class TemplateWarsztatyGrupy extends Composer
{
    protected static $views = ['template-warsztaty-grupy'];

    public function __construct(
        private EventsListingService $service,
    ) {}

    public function with(): array
    {
        return ['data' => $this->service->getWorkshopsData()];
    }
}
