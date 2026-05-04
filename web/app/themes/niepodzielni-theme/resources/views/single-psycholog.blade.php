@extends('layouts.app')

@section('content')
@php
    $post_id  = get_the_ID();
    $thumb_id = get_post_thumbnail_id($post_id);
    $kons_type = sanitize_key($_GET['konsultacje'] ?? '');
    $back_url  = ($kons_type === 'nisko') ? '/konsultacje-niskoplatne/' : '/konsultacje-psychologiczne-pelnoplatne/';
    $specs     = get_the_terms($post_id, 'specjalizacja');
    $spec_name = (!empty($specs) && !is_wp_error($specs)) ? $specs[0]->name : 'Psycholog';
    $visit_types = get_post_meta($post_id, 'rodzaj_wizyty', true);
    $has_online       = stripos($visit_types, 'Online') !== false;
    $has_stacjonarnie = stripos($visit_types, 'Stacjonarnie') !== false;
    $icon = '';
    if ($has_online)       $icon .= get_niepodzielni_svg_icon('online');
    if ($has_stacjonarnie) $icon .= get_niepodzielni_svg_icon('stacjonarnie');

    // ── Opinie ─────────────────────────────────────────────────────────────
    $avg_rating    = (float) get_post_meta($post_id, '_average_rating', true);
    $reviews_count = (int)   get_post_meta($post_id, '_reviews_count',  true);

    $reviews = get_comments([
        'post_id' => $post_id,
        'type'    => 'review',
        'status'  => 'approve',
        'parent'  => 0,
        'orderby' => 'comment_date',
        'order'   => 'DESC',
        'number'  => 50,
    ]);

    // Magic token z URL
    $magic_token = sanitize_text_field($_GET['magic_token'] ?? '');
    $rvw_email   = sanitize_email($_GET['rvw_email'] ?? '');
    $is_magic    = $magic_token && $rvw_email
        && function_exists('np_reviews_generate_magic_token')
        && hash_equals(np_reviews_generate_magic_token($rvw_email, $post_id), $magic_token);

    // CF Turnstile site key
    $cf_site_key = '';
    foreach (['NP_CF_TURNSTILE_SITE_KEY', 'CF_TURNSTILE_SITE_KEY'] as $_c) {
        if (defined($_c) && constant($_c)) { $cf_site_key = (string) constant($_c); break; }
    }
    if (!$cf_site_key) $cf_site_key = (string) get_option('np_cf_turnstile_site_key', '');

    // Helper: gwiazdki HTML
    $stars_html = function(float $rating, string $class = '') use (&$stars_html): string {
        $full  = (int) floor($rating);
        $half  = ($rating - $full) >= 0.5;
        $empty = 5 - $full - ($half ? 1 : 0);
        return '<span class="' . esc_attr($class) . '" aria-hidden="true">'
             . str_repeat('★', $full)
             . ($half ? '½' : '')
             . str_repeat('☆', $empty)
             . '</span>';
    };
@endphp

<main id="main" class="osoba-template">

    <div class="psy-back-container">
        <a href="{{ home_url($back_url) }}" class="psy-back-link">
            <span class="psy-back-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="19" viewBox="0 0 32 19" fill="none"><rect x="-0.75" y="0.75" width="30.5" height="17.5" rx="8.75" transform="matrix(-1 0 0 1 30.5 0)" stroke="#01BE4A" stroke-width="1.5"></rect><path d="M21 8.75C21.4142 8.75 21.75 9.08579 21.75 9.5C21.75 9.91421 21.4142 10.25 21 10.25V8.75ZM10.4697 10.0303C10.1768 9.73744 10.1768 9.26256 10.4697 8.96967L15.2426 4.1967C15.5355 3.90381 16.0104 3.90381 16.3033 4.1967C16.5962 4.48959 16.5962 4.96447 16.3033 5.25736L12.0607 9.5L16.3033 13.7426C16.5962 14.0355 16.5962 14.5104 16.3033 14.8033C16.0104 15.0962 15.5355 15.0962 15.2426 14.8033L10.4697 10.0303ZM21 9.5V10.25L11 10.25V9.5V8.75L21 8.75V9.5Z" fill="#01BE4A"></path></svg>
            </span>
            <span class="psy-back-text">Zobacz więcej</span>
        </a>
    </div>

    <article class="psy-profile-card">

        <header class="psy-card-header">
            <div class="psy-card-photo">
                @if($thumb_id)
                    {!! wp_get_attachment_image($thumb_id, 'large') !!}
                @else
                    <div class="psy-card-photo-placeholder">
                        @php
                            $initials = implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(explode(' ', get_post_meta($post_id, 'imie_i_nazwisko', true) ?: get_the_title($post_id)), 0, 2)));
                        @endphp
                        <span>{{ $initials }}</span>
                    </div>
                @endif
            </div>

            <div class="psy-card-main-info">
                {!! do_shortcode('[tytul_wyrozniony]') !!}

                <div class="psy-tag-profession">{{ $spec_name }}</div>

                @if($avg_rating > 0)
                    <div class="psy-header-rating">
                        {!! $stars_html($avg_rating, 'psy-header-rating__stars') !!}
                        <span>{{ number_format($avg_rating, 1) }}</span>
                        <span>({{ $reviews_count }} {{ $reviews_count === 1 ? 'opinia' : ($reviews_count < 5 ? 'opinie' : 'opinii') }})</span>
                    </div>
                @endif

                <div class="psy-specializations-row">
                    {!! do_shortcode('[specjalizacje_produktu]') !!}
                </div>

                <div class="psy-languages-row">
                    {!! do_shortcode('[jezyki_profil_psychologa]') !!}
                </div>
            </div>

            <div class="psy-header-meta">
                <div class="psy-status-online">
                    {!! $icon !!} {{ $visit_types }}
                </div>
                <div class="psy-header-price">
                    {!! do_shortcode('[dynamiczna_stawka_konsultacji]') !!}
                </div>
                @php
                    $termin_typ = ($kons_type === 'nisko') ? 'niskoplatny' : 'pelnoplatny';
                    $termin_html = do_shortcode('[termin_z_bazy typ="' . $termin_typ . '"]');
                @endphp
                @if($termin_html)
                <div class="psy-header-termin">
                    {!! $termin_html !!}
                </div>
                @endif
            </div>
        </header>

        <div id="doswiadczenie" class="psy-tab-content is-active" style="display: block;">
            <h4>O mnie</h4>
            <div class="psy-bio-text">
                {!! do_shortcode('[opis_psychologa word_limit="60"]') !!}
            </div>

            <h4>Obszary pomocy</h4>
            <div class="psy-help-tags">
                {!! do_shortcode('[lista_obszarow_pomocy limit="15"]') !!}
            </div>

            @php $nurty_html = do_shortcode('[nurty_produktu]'); @endphp
            @if($nurty_html)
            <h4>Podejście terapeutyczne</h4>
            <div class="psy-nurty-row">
                {!! $nurty_html !!}
            </div>
            @endif
        </div>

        <section class="psy-booking-area">
            <div class="psy-calendar-wrapper">
                {!! do_shortcode('[bookero_kalendarz]') !!}
            </div>
        </section>

        <section class="psy-how-to-book">
            <h2>Jak zarezerwować wizytę?</h2>
            <div class="psy-steps-container">
                <div class="psy-step-item">
                    <span class="psy-step-number">1</span>
                    <span class="psy-step-text">Wybierz usługę, odpowiedni termin i godzinę</span>
                </div>
                <div class="psy-step-item">
                    <span class="psy-step-number">2</span>
                    <span class="psy-step-text">Uzupełnij dane i opłać wizytę</span>
                </div>
                <div class="psy-step-item">
                    <span class="psy-step-number">3</span>
                    <span class="psy-step-text">Jeśli zarezerwowałeś/aś termin online, link do spotkania znajduje się w wiadomości e-mail</span>
                </div>
            </div>
        </section>

        {{-- ── Sekcja opinii ──────────────────────────────────────────────── --}}
        <section class="psy-reviews" id="opinie">
            <h2 class="psy-reviews__title">Opinie</h2>

            @if($avg_rating > 0)
                <div class="psy-rating-summary">
                    {!! $stars_html($avg_rating, 'psy-rating-summary__stars') !!}
                    <span class="psy-rating-summary__value">{{ number_format($avg_rating, 1) }}</span>
                    <span class="psy-rating-summary__count">/ 5 ({{ $reviews_count }} {{ $reviews_count === 1 ? 'opinia' : ($reviews_count < 5 ? 'opinie' : 'opinii') }})</span>
                </div>
            @endif

            {{-- Lista opinii --}}
            @if(!empty($reviews))
                <ul class="rvw-list">
                @foreach($reviews as $review)
                    @php
                        $r_id      = (int) $review->comment_ID;
                        $r_rating  = (int) get_comment_meta($r_id, '_rating', true);
                        $r_verified = (bool) get_comment_meta($r_id, '_verified_visit', true);
                        $r_date    = get_comment_date('j F Y', $r_id);

                        // Odpowiedź psychologa (pierwsze child comment)
                        $replies = get_comments([
                            'parent' => $r_id,
                            'status' => 'approve',
                            'number' => 1,
                        ]);
                        $reply = $replies[0] ?? null;
                    @endphp
                    <li class="rvw-item">
                        <div class="rvw-item__header">
                            <span class="rvw-item__author">{{ esc_html($review->comment_author) }}</span>
                            @if($r_rating > 0)
                                <span class="rvw-item__stars" aria-label="{{ $r_rating }} na 5">{{ str_repeat('★', $r_rating) . str_repeat('☆', 5 - $r_rating) }}</span>
                            @endif
                            @if($r_verified)
                                <span class="rvw-badge">✓ Zweryfikowana wizyta</span>
                            @endif
                            <span class="rvw-item__date">{{ $r_date }}</span>
                        </div>
                        @if($review->comment_content)
                            <p class="rvw-item__content">{{ $review->comment_content }}</p>
                        @endif

                        @if($reply)
                            <div class="rvw-reply">
                                <p class="rvw-reply__label">Odpowiedź psychologa</p>
                                <p class="rvw-reply__content">{{ $reply->comment_content }}</p>
                            </div>
                        @endif
                    </li>
                @endforeach
                </ul>
            @else
                <p style="color:var(--mix-color-text-subtle);margin-bottom:32px;">Brak opinii. Bądź pierwszy!</p>
            @endif

            {{-- Formularz dodawania opinii --}}
            <div class="rvw-form-section">
                <h3 class="rvw-form-section__title">
                    @if($is_magic) Dodaj opinię (zweryfikowany pacjent) @else Dodaj opinię @endif
                </h3>

                <form
                    class="rvw-form"
                    data-post-id="{{ $post_id }}"
                    novalidate
                >
                    {{-- Gwiazdki --}}
                    <div class="rvw-star-group">
                        <span class="rvw-star-group__label">Twoja ocena <span style="color:#c0392b">*</span></span>
                        <div class="rvw-star-row" role="group" aria-label="Wybierz ocenę">
                            @for($i = 1; $i <= 5; $i++)
                                <button
                                    type="button"
                                    class="rvw-star"
                                    aria-label="{{ $i }} gwiazdka"
                                    tabindex="0"
                                >★</button>
                            @endfor
                        </div>
                        <input type="hidden" name="rating" value="0">
                        <span class="field-error" role="alert"></span>
                    </div>

                    {{-- Imię (opcjonalne) --}}
                    <div class="form-field">
                        <label class="form-field__label" for="rvw-author">Imię (opcjonalne)</label>
                        <input type="text" id="rvw-author" name="author_name" class="form-field__input" maxlength="60" autocomplete="given-name">
                        <span class="field-error" role="alert"></span>
                    </div>

                    {{-- E-mail --}}
                    <div class="form-field rvw-email-field">
                        <label class="form-field__label" for="rvw-email">
                            Adres e-mail <span style="color:#c0392b">*</span>
                        </label>
                        <input
                            type="email"
                            id="rvw-email"
                            name="email"
                            class="form-field__input"
                            required
                            autocomplete="email"
                            @if($is_magic) value="{{ esc_attr($rvw_email) }}" @endif
                        >
                        <p class="form-field__hint">Nie będzie opublikowany.</p>
                        <span class="field-error" role="alert"></span>
                    </div>

                    {{-- Treść (opcjonalna) --}}
                    <div class="form-field">
                        <label class="form-field__label" for="rvw-content">Opinia (opcjonalna)</label>
                        <textarea id="rvw-content" name="content" class="form-field__input form-field__input--textarea" rows="4" maxlength="1000"></textarea>
                        <span class="field-error" role="alert"></span>
                    </div>

                    {{-- CF Turnstile --}}
                    @if(!$is_magic && $cf_site_key)
                        <div class="form-field rvw-turnstile-field">
                            <div class="rvw-turnstile-container"></div>
                            <input type="hidden" name="cf-turnstile-response" value="">
                        </div>
                    @endif

                    <div class="rvw-general-error" hidden role="alert"></div>

                    <button type="submit" class="btn btn--primary">Wyślij opinię</button>
                </form>
            </div>
        </section>

    </article>

</main>

@if(!$is_magic && $cf_site_key)
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endif
@endsection
