<?php

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class TemplateWydarzenia extends Composer
{
    protected static $views = ['template-wydarzenia'];

    public function with(): array
    {
        return ['data' => get_wydarzenia_listing_data()];
    }
}
