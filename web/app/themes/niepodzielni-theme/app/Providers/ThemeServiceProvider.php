<?php

namespace App\Providers;

use App\Services\EventsListingService;
use App\Services\PsychologistListingService;
use Roots\Acorn\Sage\SageServiceProvider;

class ThemeServiceProvider extends SageServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        parent::register();

        // Singletony serwisów listingów — jeden egzemplarz per request,
        // eliminuje wielokrotne rozwiązywanie przez kontener gdy kilka Composerów
        // korzysta z tego samego serwisu (np. TemplateWarsztatyGrupy + TemplateWydarzenia).
        $this->app->singleton(PsychologistListingService::class);
        $this->app->singleton(EventsListingService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();
    }
}
