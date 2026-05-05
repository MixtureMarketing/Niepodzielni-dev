{{-- Template Name: Psychomapa --}}

@extends('layouts.app')

@section('content')
@php
    $rodzaje_terms = get_terms(['taxonomy' => 'rodzaj-pomocy', 'hide_empty' => true]);
    $grupy_terms   = get_terms(['taxonomy' => 'grupa-docelowa', 'hide_empty' => true]);
    $api_url       = rest_url('niepodzielni/v1/psychomapa');
@endphp

<script data-cfasync="false">
window.npPsychomapa = {
    apiUrl: {{ json_encode($api_url) }},
    rodzajeTerms: {!! json_encode(
        (!is_wp_error($rodzaje_terms) && !empty($rodzaje_terms))
            ? array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name], $rodzaje_terms)
            : [],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) !!},
    grupyTerms: {!! json_encode(
        (!is_wp_error($grupy_terms) && !empty($grupy_terms))
            ? array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name], $grupy_terms)
            : [],
        JSON_HEX_TAG | JSON_HEX_AMP
    ) !!},
};
</script>

<div class="psychomapa-page">

    <div class="psychomapa-header">
        <div class="psychomapa-header__inner">
            <h1 class="psychomapa-header__title">Psychomapa</h1>
            <p class="psychomapa-header__desc">Znajdź ośrodek pomocy psychologicznej w swojej okolicy.</p>

            <div class="psychomapa-filters">
                <div class="psychomapa-search">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 21l-4.35-4.35M16.65 16.65A7.5 7.5 0 1 0 5.35 5.35a7.5 7.5 0 0 0 11.3 11.3z" stroke="#828282" stroke-width="2" stroke-linecap="round"/></svg>
                    <input
                        type="search"
                        id="psychomapa-search"
                        class="psychomapa-search__input"
                        placeholder="Szukaj po nazwie lub mieście…"
                        aria-label="Szukaj ośrodka"
                    >
                </div>

                @if(!is_wp_error($rodzaje_terms) && !empty($rodzaje_terms))
                <select id="psychomapa-filter-rodzaj" class="psychomapa-select" aria-label="Filtruj po rodzaju pomocy">
                    <option value="">Rodzaj pomocy</option>
                    @foreach($rodzaje_terms as $term)
                        <option value="{{ $term->term_id }}">{{ $term->name }}</option>
                    @endforeach
                </select>
                @endif

                @if(!is_wp_error($grupy_terms) && !empty($grupy_terms))
                <select id="psychomapa-filter-grupa" class="psychomapa-select" aria-label="Filtruj po grupie docelowej">
                    <option value="">Dla kogo</option>
                    @foreach($grupy_terms as $term)
                        <option value="{{ $term->term_id }}">{{ $term->name }}</option>
                    @endforeach
                </select>
                @endif
            </div>
        </div>
    </div>

    <div class="psychomapa-layout">

        {{-- Sidebar z listą --}}
        <aside class="psychomapa-sidebar" aria-label="Lista ośrodków">
            <div class="psychomapa-sidebar__count" id="psychomapa-count">Ładowanie…</div>
            <ul class="psychomapa-list" id="psychomapa-list" role="list">
                <li class="psychomapa-list__loading">
                    <div class="psychomapa-spinner"></div>
                </li>
            </ul>
        </aside>

        {{-- Mapa --}}
        <div class="psychomapa-map-wrap">
            <div id="psychomapa-map" class="psychomapa-map" aria-label="Mapa ośrodków pomocy"></div>
        </div>

    </div>

</div>
@endsection
