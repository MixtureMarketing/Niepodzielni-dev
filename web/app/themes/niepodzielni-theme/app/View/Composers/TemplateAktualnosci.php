<?php

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class TemplateAktualnosci extends Composer
{
    protected static $views = ['template-aktualnosci'];

    public function with(): array
    {
        return ['data' => get_aktualnosci_listing_data()];
    }
}
