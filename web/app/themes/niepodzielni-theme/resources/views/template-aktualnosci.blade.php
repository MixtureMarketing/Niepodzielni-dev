{{-- Template Name: Aktualności --}}

@extends('layouts.app')

@section('content')

<script>
window.npListingConfig = {!! json_encode([
    'type'    => 'aktualnosci',
    'data'    => $data,
    'perPage' => 9,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
</script>

<div class="nlisting-page">

    @include('partials.listing.organisms.hero', [
        'title'     => 'Aktualności',
        'desc'      => 'Bądź na bieżąco z tym, co dzieje się w Fundacji Niepodzielni — aktualności, relacje z wydarzeń i ważne informacje.',
        'image_url' => get_template_directory_uri() . '/resources/images/hero-aktualnosci.svg',
        'buttons'   => [
            ['label' => 'CZYTAJ WIĘCEJ', 'link' => '#listing', 'class' => 'psy-btn-green'],
        ],
    ])

    <div class="nlisting-body" id="listing">
        <div class="nlisting-container">
            <div class="nlisting-grid" id="nlisting-grid"></div>
            <div class="nlisting-pagination" id="nlisting-pagination"></div>
        </div>
    </div>

</div>
@endsection
