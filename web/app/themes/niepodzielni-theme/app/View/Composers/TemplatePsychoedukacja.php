<?php

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class TemplatePsychoedukacja extends Composer
{
    protected static $views = ['template-psychoedukacja'];

    public function with(): array
    {
        $data = get_psychoedukacja_listing_data();
        $tags = get_psychoedukacja_tags();
        $tabs = array_merge(
            [['value' => 'all', 'label' => 'Wszystkie']],
            $tags,
        );

        return compact('data', 'tabs');
    }
}
