{{-- Wspólny layout listingu psychologów (pelno + nisko).
     Konfiguracja (`$config`) i `$rodzaj` dostarczane przez TemplatePsyListing composer. --}}

<script>
    window.allPsycholodzy = {!! json_encode($all_psy_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
</script>

<div class="psy-page-wrapper">

    @include('partials.psy-listing.organisms.hero', [
        'rodzaj' => $rodzaj,
        'title'  => $config['hero']['title'],
        'desc'   => $config['hero']['desc'],
    ])

    @include('partials.psy-listing.organisms.steps', ['steps' => $config['steps']])

    @include('partials.psy-listing.organisms.mission', $config['mission'])

    @include('partials.psy-listing.organisms.bk-shared-calendar', ['rodzaj' => $rodzaj])

    @include('partials.psy-listing.organisms.filter-bar', ['rodzaj' => $rodzaj])

</div>
