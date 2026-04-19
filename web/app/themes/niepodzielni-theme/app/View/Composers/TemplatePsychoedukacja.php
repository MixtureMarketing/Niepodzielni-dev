<?php

namespace App\View\Composers;

use App\Services\EventsListingService;
use Roots\Acorn\View\Composer;

class TemplatePsychoedukacja extends Composer
{
    protected static $views = ['template-psychoedukacja'];

    public function __construct(
        private EventsListingService $service,
    ) {}

    public function with(): array
    {
        $data = $this->service->getPsychoedukacjaData();
        $tags = $this->service->getPsychoedukacjaTags();
        $tabs = array_merge(
            [['value' => 'all', 'label' => 'Wszystkie']],
            $tags,
        );

        return compact('data', 'tabs');
    }
}
