<?php

namespace App\View\Composers;

use App\Services\EventsListingService;
use Illuminate\Contracts\View\View;
use Roots\Acorn\View\Composer;

class TemplateWydarzenia extends Composer
{
    protected static $views = ['template-wydarzenia'];

    public function __construct(
        View $view,
        private EventsListingService $service,
    ) {
        parent::__construct($view);
    }

    public function with(): array
    {
        return ['data' => $this->service->getWydarzeniaData()];
    }
}
