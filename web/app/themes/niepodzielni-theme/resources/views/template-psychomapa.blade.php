{{-- Template Name: Psychomapa --}}

@extends('layouts.app')

@section('content')
@php
    $rodzaje_terms = get_terms(['taxonomy' => 'rodzaj-pomocy', 'hide_empty' => true]);
    $grupy_terms   = get_terms(['taxonomy' => 'grupa-docelowa', 'hide_empty' => true]);
    $api_url       = rest_url('niepodzielni/v1/psychomapa');

    $rodzaje_data = (!is_wp_error($rodzaje_terms) && !empty($rodzaje_terms))
        ? array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name], $rodzaje_terms)
        : [];

    $grupy_data = (!is_wp_error($grupy_terms) && !empty($grupy_terms))
        ? array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name], $grupy_terms)
        : [];
@endphp

<script data-cfasync="false">
window.npPsychomapa = {
    apiUrl: {!! json_encode($api_url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!},
    rodzajeTerms: {!! json_encode(array_values($rodzaje_data), JSON_UNESCAPED_UNICODE) !!},
    grupyTerms:   {!! json_encode(array_values($grupy_data),   JSON_UNESCAPED_UNICODE) !!}
};
</script>

<div class="pm-page">

    {{-- Hero --}}
    <div class="pm-hero">
        <div class="psy-container">
            <h1 class="pm-hero__title">Psychomapa</h1>
            <p class="pm-hero__desc">Znajdź ośrodek pomocy psychologicznej w swojej okolicy. Kliknij w pin na mapie lub wyszukaj poniżej.</p>
        </div>
    </div>

    {{-- Mapa (full-width) --}}
    <div class="pm-map-wrap">
        <div id="psychomapa-map" class="pm-map" aria-label="Mapa ośrodków pomocy"></div>
        <div class="pm-map-overlay" id="pm-map-loading" aria-hidden="true">
            <div class="pm-spinner"></div>
        </div>
    </div>

    {{-- Filters + listing --}}
    <div class="pm-body">
        <div class="psy-container">

            {{-- Pasek filtrów --}}
            <div class="pm-filters" role="search" aria-label="Filtry ośrodków">
                <div class="pm-filters__controls">

                    {{-- Rodzaj pomocy — multi-select --}}
                    @if(!is_wp_error($rodzaje_terms) && !empty($rodzaje_terms))
                    <div class="pm-dropdown" id="pm-drop-rodzaj" data-filter="rodzaj" data-mode="multi">
                        <button class="pm-dropdown__trigger" type="button" aria-haspopup="listbox" aria-expanded="false" aria-controls="pm-drop-rodzaj-panel">
                            <span class="pm-dropdown__label">Rodzaj pomocy</span>
                            <svg class="pm-dropdown__arrow" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                                <path d="M3 5l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <div class="pm-dropdown__panel" id="pm-drop-rodzaj-panel" hidden role="listbox" aria-multiselectable="true" aria-label="Rodzaj pomocy">
                            <div class="pm-dropdown__search-wrap">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M21 21l-4.35-4.35M16.65 16.65A7.5 7.5 0 1 0 5.35 5.35a7.5 7.5 0 0 0 11.3 11.3z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                                <input type="text" class="pm-dropdown__search" placeholder="Szukaj…" autocomplete="off" aria-label="Szukaj rodzaju pomocy">
                            </div>
                            <ul class="pm-dropdown__list">
                                @foreach($rodzaje_terms as $term)
                                <li class="pm-dropdown__item" role="option" aria-selected="false" data-value="{{ $term->term_id }}" tabindex="-1">
                                    <span class="pm-dropdown__check" aria-hidden="true">
                                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </span>
                                    <span class="pm-dropdown__item-text">{{ $term->name }}</span>
                                </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                    @endif

                    {{-- Dla kogo — single-select --}}
                    @if(!is_wp_error($grupy_terms) && !empty($grupy_terms))
                    <div class="pm-dropdown" id="pm-drop-grupa" data-filter="grupa" data-mode="single">
                        <button class="pm-dropdown__trigger" type="button" aria-haspopup="listbox" aria-expanded="false" aria-controls="pm-drop-grupa-panel">
                            <span class="pm-dropdown__label">Dla kogo</span>
                            <svg class="pm-dropdown__arrow" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                                <path d="M3 5l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <div class="pm-dropdown__panel" id="pm-drop-grupa-panel" hidden role="listbox" aria-label="Dla kogo">
                            <div class="pm-dropdown__search-wrap">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M21 21l-4.35-4.35M16.65 16.65A7.5 7.5 0 1 0 5.35 5.35a7.5 7.5 0 0 0 11.3 11.3z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                                <input type="text" class="pm-dropdown__search" placeholder="Szukaj…" autocomplete="off" aria-label="Szukaj grupy docelowej">
                            </div>
                            <ul class="pm-dropdown__list">
                                <li class="pm-dropdown__item is-selected" role="option" aria-selected="true" data-value="" tabindex="-1">
                                    <span class="pm-dropdown__check" aria-hidden="true">
                                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </span>
                                    <span class="pm-dropdown__item-text">Wszyscy</span>
                                </li>
                                @foreach($grupy_terms as $term)
                                <li class="pm-dropdown__item" role="option" aria-selected="false" data-value="{{ $term->term_id }}" tabindex="-1">
                                    <span class="pm-dropdown__check" aria-hidden="true">
                                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </span>
                                    <span class="pm-dropdown__item-text">{{ $term->name }}</span>
                                </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                    @endif

                    {{-- Szukaj --}}
                    <div class="pm-search">
                        <svg class="pm-search__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M21 21l-4.35-4.35M16.65 16.65A7.5 7.5 0 1 0 5.35 5.35a7.5 7.5 0 0 0 11.3 11.3z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <input
                            type="search"
                            id="psychomapa-search"
                            class="pm-search__input"
                            placeholder="Szukaj…"
                            aria-label="Szukaj ośrodka"
                            autocomplete="off"
                        >
                    </div>

                    <button class="pm-reset" id="pm-reset" type="button" hidden aria-label="Wyczyść filtry">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <path d="M1 1l10 10M11 1L1 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                        Wyczyść
                    </button>
                </div>

                <div class="pm-filters__meta">
                    <span id="psychomapa-count" class="pm-count">Ładowanie…</span>
                </div>
            </div>

            {{-- Grid kart --}}
            <div class="pm-grid" id="psychomapa-list" role="list" aria-label="Lista ośrodków pomocy">
                <div class="pm-loading" aria-busy="true">
                    <div class="pm-spinner"></div>
                    <p>Ładowanie ośrodków…</p>
                </div>
            </div>

        </div>
    </div>

</div>
@endsection
