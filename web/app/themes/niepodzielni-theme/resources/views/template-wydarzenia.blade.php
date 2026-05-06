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
    @endif

</div>
@endsection
