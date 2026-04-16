<?php

namespace App\View\Composers;

use App\Services\PsychologistListingService;
use Illuminate\Contracts\View\View;
use Roots\Acorn\View\Composer;

class TemplatePsyListing extends Composer
{
    protected static $views = [
        'template-psy-listing-nisko',
        'template-psy-listing-pelno',
    ];

    public function __construct(
        View $view,
        private PsychologistListingService $service,
    ) {
        parent::__construct($view);
    }

    public function with(): array
    {
        $rodzaj = str_contains($this->view->getName(), 'nisko') ? 'nisko' : 'pelno';

        return ['all_psy_data' => $this->service->getData($rodzaj)];
    }
}
