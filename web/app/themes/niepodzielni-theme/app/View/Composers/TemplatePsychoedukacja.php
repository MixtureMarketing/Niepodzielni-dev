<?php

namespace App\View\Composers;

use App\Services\EventsListingService;
use Illuminate\Contracts\View\View;
use Roots\Acorn\View\Composer;

class TemplatePsychoedukacja extends Composer
{
    protected static $views = ['template-psychoedukacja'];

    public function __construct(
        View $view,
        private EventsListingService $service,
    ) {
        parent::__construct($view);
    }

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
