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

    </article>

</main>
@endsection
