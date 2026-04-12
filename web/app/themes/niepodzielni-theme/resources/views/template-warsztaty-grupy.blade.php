{{-- Template Name: Warsztaty i Grupy Wsparcia --}}

@extends('layouts.app')

@section('content')

<script>
window.npListingConfig = {!! json_encode([
    'type'    => 'warsztaty',
    'data'    => $data,
    'perPage' => 9,
    'tabs'    => [
        ['value' => 'all',           'label' => 'Wszystkie'],
        ['value' => 'grupy-wsparcia','label' => 'Grupy wsparcia'],
        ['value' => 'warsztaty',     'label' => 'Warsztaty'],
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
</script>

<div class="nlisting-page">

    @include('partials.listing.organisms.hero', [
        'title'     => 'Warsztaty<br>i Grupy Wsparcia',
        'desc'      => 'Organizujemy warsztaty psychologiczne i grupy wsparcia prowadzone przez doświadczonych specjalistów. To doskonała okazja, by poznać naszą działalność i spojrzeć z nami w kierunku zdrowia.',
        'image_url' => get_template_directory_uri() . '/resources/images/hero-warsztaty.svg',
        'buttons'   => [
            ['label' => 'SPRAWDŹ TERMINY', 'link' => '#listing', 'class' => 'psy-btn-green'],
        ],
    ])

    <form id="nlisting-tabs-form" aria-label="Filtruj listę">
        @include('partials.listing.molecules.listing-tabs', [
            'tabs' => [
                ['value' => 'all',            'label' => 'Wszystkie'],
                ['value' => 'grupy-wsparcia', 'label' => 'Grupy wsparcia'],
                ['value' => 'warsztaty',       'label' => 'Warsztaty'],
            ],
            'active' => 'all',
            'name'   => 'listing-tab',
        ])
    </form>

    <div class="nlisting-body" id="listing">
        <div class="nlisting-container">

            {{-- AKTYWNE --}}
            <div class="nlisting-section" id="nlisting-active">
                <h2 class="nlisting-section__title">Aktywne</h2>
                <div class="nlisting-grid">
                    @forelse( array_filter($data, fn($i) => $i['is_active']) as $item )
                        @include('partials.listing.molecules.card', ['variant' => 'workshop', 'item' => $item])
                    @empty
                        <p class="nlisting-no-results">Brak aktywnych wydarzeń.</p>
                    @endforelse
                </div>
            </div>

            {{-- NIEAKTYWNE --}}
            @php $inactive = array_filter($data, fn($i) => !$i['is_active']); @endphp
            @if( count($inactive) > 0 )
                <div class="nlisting-section" id="nlisting-inactive">
                    <h2 class="nlisting-section__title">Nieaktywne</h2>
                    <div class="nlisting-grid">
                        @foreach( $inactive as $item )
                            @include('partials.listing.molecules.card', ['variant' => 'workshop', 'item' => $item])
                        @endforeach
                    </div>
                </div>
            @endif

        </div>
    </div>

</div>
@endsection
