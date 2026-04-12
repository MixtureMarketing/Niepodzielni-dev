<?php

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class TemplateWarsztatyGrupy extends Composer
{
    protected static $views = ['template-warsztaty-grupy'];

    public function with(): array
    {
        return ['data' => get_workshops_listing_data()];
    }
}
