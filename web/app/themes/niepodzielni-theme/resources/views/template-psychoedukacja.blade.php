{{-- Template Name: Psychoedukacja --}}

@extends('layouts.app')

@section('content')

<script>
window.npListingConfig = {!! json_encode([
    'type'    => 'artykuly',
    'data'    => $data,
    'perPage' => 9,
    'tabs'    => $tabs,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
</script>

<div class="nlisting-page">

    @include('partials.listing.organisms.hero', [
        'title'     => 'Psychoedukacja',
        'desc'      => 'Psychoedukacja to przestrzeń, w której dowiesz się więcej o emocjach, mechanizmach psychicznych i sposobach radzenia sobie z trudnościami. Bez skomplikowanego języka, bez ocen — mówimy o psychice tak, by było to zrozumiałe, praktyczne i pomocne na co dzień.',
        'image_url' => get_template_directory_uri() . '/resources/images/hero-psychoedukacja.svg',
        'buttons'   => [
            ['label' => 'ARTYKUŁY',        'link' => '#listing',          'class' => 'psy-btn-green'],
            ['label' => 'POMOCNA WIEDZA',  'link' => home_url('/pomocna-wiedza/'), 'class' => 'psy-btn-green'],
            ['label' => 'PSYCHOMAPA',      'link' => home_url('/psychomapa/'),     'class' => 'psy-btn-green'],
        ],
    ])

    <form id="nlisting-tabs-form" aria-label="Filtruj artykuły">
        @include('partials.listing.molecules.listing-tabs', [
            'tabs'   => $tabs,
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
