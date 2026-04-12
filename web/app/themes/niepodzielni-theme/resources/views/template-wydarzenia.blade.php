{{-- Template Name: Nasze Wydarzenia --}}

@extends('layouts.app')

@section('content')

<script>
window.npListingConfig = {!! json_encode([
    'type'    => 'wydarzenia',
    'data'    => $data,
    'perPage' => 9,
    'tabs'    => [
        ['value' => 'all',          'label' => 'Wszystkie'],
        ['value' => 'nadchodzace',  'label' => 'Nadchodzące'],
        ['value' => 'archiwalne',   'label' => 'Archiwalne'],
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
</script>

<div class="nlisting-page">

    @include('partials.listing.organisms.hero', [
        'title'     => 'Spotkajmy się!',
        'desc'      => 'Organizujemy i współtworzymy wydarzenia kulturalne, które łączą ciekawą wiedzę z dbałością o zdrowie psychiczne i promują dialog do pomocy psychologicznej.',
        'image_url' => get_template_directory_uri() . '/resources/images/hero-wydarzenia.svg',
        'buttons'   => [
            ['label' => 'NADCHODZĄCE', 'link' => '#listing', 'class' => 'psy-btn-green'],
        ],
    ])

    <form id="nlisting-tabs-form" aria-label="Filtruj listę">
        @include('partials.listing.molecules.listing-tabs', [
            'tabs' => [
                ['value' => 'all',         'label' => 'Wszystkie'],
                ['value' => 'nadchodzace', 'label' => 'Nadchodzące'],
                ['value' => 'archiwalne',  'label' => 'Archiwalne'],
            ],
            'active' => 'all',
            'name'   => 'listing-tab',
        ])
    </form>

    <div class="nlisting-body" id="listing">
        <div class="nlisting-container">
            <div class="nlisting-grid" id="nlisting-grid"></div>
            <div class="nlisting-pagination" id="nlisting-pagination"></div>
        </div>
    </div>

</div>
@endsection
