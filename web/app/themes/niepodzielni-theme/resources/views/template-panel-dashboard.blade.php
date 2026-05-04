{{-- Template Name: Panel — Dashboard --}}

@extends('layouts.app')

@php
    // ── Auth guard ─────────────────────────────────────────────────────────
    if (! is_user_logged_in()) {
        wp_safe_redirect(home_url('/panel/logowanie/'));
        exit;
    }

    $current_user = wp_get_current_user();
    $is_admin     = current_user_can('manage_options');
    $is_psycholog = in_array('psycholog', (array) $current_user->roles, true);

    if (! $is_psycholog && ! $is_admin) {
        wp_safe_redirect(home_url('/panel/logowanie/'));
        exit;
    }

    // ── Znajdź post psychologa powiązany z userem ─────────────────────────
    $psycholog_post = function_exists('np_panel_get_user_psycholog_post')
        ? np_panel_get_user_psycholog_post($current_user->ID)
        : null;

    // Dla admina (testowanie) — dopuść override przez ?post_id
    if (! $psycholog_post && $is_admin && isset($_GET['post_id'])) {
        $tmp = get_post((int) $_GET['post_id']);
        if ($tmp && $tmp->post_type === 'psycholog') {
            $psycholog_post = $tmp;
        }
    }
@endphp

@section('content')
<div class="panel-page">
    <div class="panel-container">

        {{-- Header z logoutem --}}
        <header class="panel-header">
            <div class="panel-header__user">
                <span class="panel-header__greeting">Witaj, <strong>{{ $current_user->display_name }}</strong></span>
                @if ($is_admin && ! $is_psycholog)
                    <span class="panel-header__badge">Tryb admin</span>
                @endif
            </div>
            <a class="panel-header__logout" href="{{ wp_logout_url(home_url('/panel/logowanie/')) }}">Wyloguj</a>
        </header>

        @if (! $psycholog_post)
            <div class="panel-empty">
                <h2>Twoje konto nie jest powiązane z profilem psychologa</h2>
                <p>Skontaktuj się z administratorem fundacji aby powiązać Twoje konto z odpowiednim profilem.</p>
                @if ($is_admin)
                    <p style="font-size:13px;color:#666;margin-top:20px">
                        <strong>Tryb admin:</strong> dodaj <code>?post_id=ID</code> do URL aby edytować dowolny profil psychologa testowo.
                    </p>
                @endif
            </div>
        @else
            @php
                $post_id   = $psycholog_post->ID;
                $biogram   = (string) get_post_meta($post_id, 'biogram', true);
                $tryb_info = (string) get_post_meta($post_id, 'tryb_konsultacji_info', true);
                $thumb_url = get_the_post_thumbnail_url($post_id, 'medium_large') ?: '';

                // Aktualnie zaznaczone slugi per taksonomia (z Carbon Fields meta)
                $current_specjalizacje  = (array) get_post_meta($post_id, 'cf_specjalizacje', true);
                $current_nurty          = (array) get_post_meta($post_id, 'cf_nurty', true);
                $current_obszary        = (array) get_post_meta($post_id, 'cf_obszary_pomocy', true);
                $current_jezyki         = (array) get_post_meta($post_id, 'cf_jezyki', true);

                // Dostępne termy (Carbon Fields zapisuje wartości jako stringi — nie zawsze tablica)
                $current_specjalizacje  = array_filter(array_map('strval', is_array($current_specjalizacje)  ? $current_specjalizacje  : []));
                $current_nurty          = array_filter(array_map('strval', is_array($current_nurty)          ? $current_nurty          : []));
                $current_obszary        = array_filter(array_map('strval', is_array($current_obszary)        ? $current_obszary        : []));
                $current_jezyki         = array_filter(array_map('strval', is_array($current_jezyki)         ? $current_jezyki         : []));

                $tax_options = function ($taxonomy) {
                    $terms = get_terms([
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                        'orderby'    => 'name',
                        'order'      => 'ASC',
                    ]);
                    return is_array($terms) ? $terms : [];
                };

                $opt_spec    = $tax_options('specjalizacja');
                $opt_nurty   = $tax_options('nurt');
                $opt_obszary = $tax_options('obszar-pomocy');
                $opt_jezyki  = $tax_options('jezyk');
            @endphp

            <div id="np-panel" data-post-id="{{ $post_id }}">
                <h1 class="panel-title">{{ $psycholog_post->post_title }}</h1>
                <p class="panel-subtitle">Zarządzaj swoim profilem widocznym na stronie fundacji.</p>

                {{-- ── Sekcja: Zdjęcie profilowe ────────────────────────── --}}
                <section class="panel-section">
                    <h2 class="panel-section__title">Zdjęcie profilowe</h2>
                    <div class="panel-photo">
                        <div class="panel-photo__preview" id="panel-photo-preview">
                            @if ($thumb_url)
                                <img src="{{ $thumb_url }}" alt="{{ $psycholog_post->post_title }}">
                            @else
                                <div class="panel-photo__placeholder">Brak zdjęcia</div>
                            @endif
                        </div>
                        <form class="panel-photo__form" id="panel-photo-form" enctype="multipart/form-data">
                            <label class="panel-button panel-button--secondary" for="panel-photo-input">
                                Wybierz nowe zdjęcie
                                <input type="file" id="panel-photo-input" name="photo" accept="image/jpeg,image/png,image/webp" hidden>
                            </label>
                            <p class="panel-help">JPG, PNG lub WebP. Max 5 MB. Zalecany rozmiar: 600×600 px.</p>
                            <button type="submit" class="panel-button panel-button--primary" disabled>Zapisz zdjęcie</button>
                        </form>
                    </div>
                </section>

                {{-- ── Sekcja: Biogram + tryb konsultacji ───────────────── --}}
                <section class="panel-section">
                    <h2 class="panel-section__title">Opis profilu</h2>
                    <form id="panel-profile-form" class="panel-form">
                        <div class="panel-field">
                            <label for="panel-biogram">Biogram</label>
                            <textarea id="panel-biogram" name="biogram" rows="10" class="panel-textarea">{{ $biogram }}</textarea>
                            <p class="panel-help">Krótki opis siebie widoczny na stronie profilu i listingu psychologów.</p>
                        </div>
                        <div class="panel-field">
                            <label for="panel-tryb">Tryb konsultacji (info)</label>
                            <textarea id="panel-tryb" name="tryb_konsultacji_info" rows="2" class="panel-textarea panel-textarea--short">{{ $tryb_info }}</textarea>
                            <p class="panel-help">Dodatkowa informacja o dostępności, np. "Przyjmuję od poniedziałku do piątku".</p>
                        </div>
                        <button type="submit" class="panel-button panel-button--primary">Zapisz opis</button>
                    </form>
                </section>

                {{-- ── Sekcja: Taksonomie ───────────────────────────────── --}}
                <section class="panel-section">
                    <h2 class="panel-section__title">Specjalizacje, nurty, obszary, języki</h2>
                    <form id="panel-taxonomies-form" class="panel-form">

                        @foreach ([
                            ['key' => 'specjalizacje',  'label' => 'Specjalizacje',  'options' => $opt_spec,    'current' => $current_specjalizacje],
                            ['key' => 'nurty',          'label' => 'Nurty terapeutyczne', 'options' => $opt_nurty, 'current' => $current_nurty],
                            ['key' => 'obszary_pomocy', 'label' => 'Obszary pomocy', 'options' => $opt_obszary, 'current' => $current_obszary],
                            ['key' => 'jezyki',         'label' => 'Języki konsultacji', 'options' => $opt_jezyki, 'current' => $current_jezyki],
                        ] as $field)
                            <div class="panel-field">
                                <label>{{ $field['label'] }}</label>
                                <div class="panel-tags">
                                    @if (empty($field['options']))
                                        <p class="panel-help">Brak dostępnych opcji. Skontaktuj się z administratorem.</p>
                                    @else
                                        @foreach ($field['options'] as $term)
                                            @php $checked = in_array($term->slug, $field['current'], true); @endphp
                                            <label class="panel-tag {{ $checked ? 'panel-tag--checked' : '' }}">
                                                <input
                                                    type="checkbox"
                                                    name="{{ $field['key'] }}[]"
                                                    value="{{ $term->slug }}"
                                                    {{ $checked ? 'checked' : '' }}
                                                >
                                                <span>{{ $term->name }}</span>
                                            </label>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        @endforeach

                        <button type="submit" class="panel-button panel-button--primary">Zapisz wybór</button>
                    </form>
                </section>

                {{-- ── Sekcja: Opinie ───────────────────────────────────── --}}
                <section class="panel-section" id="panel-opinie-section">
                    <h2 class="panel-section__title">Opinie pacjentów</h2>
                    <p class="panel-help" style="margin-bottom:20px;">
                        Poniżej znajdziesz opinie wystawione Twojemu profilowi. Możesz odpowiedzieć na każdą z nich.
                    </p>
                    <div id="panel-reviews-list">
                        <p class="panel-help">Ładowanie opinii…</p>
                    </div>
                </section>

                {{-- Toast container --}}
                <div id="panel-toast" class="panel-toast" hidden></div>
            </div>
        @endif

    </div>
</div>
@endsection
