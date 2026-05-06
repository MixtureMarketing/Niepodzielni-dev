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

    @php $currentUrl = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'); @endphp
    <div class="np-view-toggle" role="tablist" aria-label="Widok">
        <a class="np-view-toggle__btn @if($cal['view'] === 'list') is-active @endif"
           href="{{ esc_url($currentUrl) }}"
           role="tab"
           aria-selected="{{ $cal['view'] === 'list' ? 'true' : 'false' }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Lista
        </a>
        <a class="np-view-toggle__btn @if($cal['view'] === 'calendar') is-active @endif"
           href="{{ esc_url($currentUrl . '?view=calendar') }}"
           role="tab"
           aria-selected="{{ $cal['view'] === 'calendar' ? 'true' : 'false' }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="2"/>
                <path d="M3 10h18M9 3v4M15 3v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Kalendarz
        </a>
    </div>

    @if($cal['view'] === 'calendar')
        @include('partials.calendar-month', ['cal' => $cal])
    @else
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
    @endif

</div>
@endsection
