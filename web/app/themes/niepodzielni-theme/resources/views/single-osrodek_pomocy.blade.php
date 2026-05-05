@extends('layouts.app')

@section('content')
@while(have_posts())
    @php
        the_post();
        $post_id = get_the_ID();

        $logo_url    = (string) get_post_meta($post_id, 'np_logo_url',      true);
        $ulica       = (string) get_post_meta($post_id, 'np_ulica',         true);
        $nr_domu     = (string) get_post_meta($post_id, 'np_nr_domu',       true);
        $nr_mieszk   = (string) get_post_meta($post_id, 'np_nr_mieszkania', true);
        $kod         = (string) get_post_meta($post_id, 'np_kod_pocztowy',  true);
        $miasto      = (string) get_post_meta($post_id, 'np_miasto',        true);
        $wojewodztwo = (string) get_post_meta($post_id, 'np_wojewodztwo',   true);
        $telefon     = (string) get_post_meta($post_id, 'np_telefon',       true);
        $telefon2    = (string) get_post_meta($post_id, 'np_telefon_2',     true);
        $telefon3    = (string) get_post_meta($post_id, 'np_telefon_3',     true);
        $email       = (string) get_post_meta($post_id, 'np_email',         true);
        $www         = (string) get_post_meta($post_id, 'np_www',           true);
        $facebook    = (string) get_post_meta($post_id, 'np_facebook',      true);
        $instagram   = (string) get_post_meta($post_id, 'np_instagram',     true);
        $tiktok      = (string) get_post_meta($post_id, 'np_tiktok',        true);
        $lat         = (float)  get_post_meta($post_id, 'lat',              true);
        $lng         = (float)  get_post_meta($post_id, 'lng',              true);

        $address_parts = array_filter([$ulica . ($nr_domu ? ' ' . $nr_domu : '') . ($nr_mieszk ? '/' . $nr_mieszk : ''), $kod . ($miasto ? ' ' . $miasto : ''), $wojewodztwo]);
        $full_address  = implode(', ', $address_parts);

        $rodzaje = get_the_terms($post_id, 'rodzaj-pomocy');
        $grupy   = get_the_terms($post_id, 'grupa-docelowa');

        $days = [
            'pon' => 'Poniedziałek',
            'wt'  => 'Wtorek',
            'sr'  => 'Środa',
            'czw' => 'Czwartek',
            'pt'  => 'Piątek',
            'sb'  => 'Sobota',
            'nd'  => 'Niedziela',
        ];
    @endphp

    <div class="osrodek-single">

        <div class="psy-back-container">
            <a href="{{ home_url('/psychomapa/') }}" class="psy-back-link">
                <span class="psy-back-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="19" viewBox="0 0 32 19" fill="none"><rect x="-0.75" y="0.75" width="30.5" height="17.5" rx="8.75" transform="matrix(-1 0 0 1 30.5 0)" stroke="#01BE4A" stroke-width="1.5"></rect><path d="M21 8.75C21.4142 8.75 21.75 9.08579 21.75 9.5C21.75 9.91421 21.4142 10.25 21 10.25V8.75ZM10.4697 10.0303C10.1768 9.73744 10.1768 9.26256 10.4697 8.96967L15.2426 4.1967C15.5355 3.90381 16.0104 3.90381 16.3033 4.1967C16.5962 4.48959 16.5962 4.96447 16.3033 5.25736L12.0607 9.5L16.3033 13.7426C16.5962 14.0355 16.5962 14.5104 16.3033 14.8033C16.0104 15.0962 15.5355 15.0962 15.2426 14.8033L10.4697 10.0303ZM21 9.5V10.25L11 10.25V9.5V8.75L21 8.75V9.5Z" fill="#01BE4A"></path></svg>
                </span>
                <span class="psy-back-text">Wróć do mapy</span>
            </a>
        </div>

        <article class="osrodek-card">

            {{-- Header --}}
            <header class="osrodek-header">
                @if($logo_url)
                    <div class="osrodek-logo">
                        <img src="{{ esc_url($logo_url) }}" alt="{{ esc_attr(get_the_title()) }}" loading="lazy">
                    </div>
                @endif
                <div class="osrodek-header__info">
                    <h1 class="osrodek-title">{{ get_the_title() }}</h1>
                    @if($full_address)
                        <p class="osrodek-address">
                            <svg width="14" height="18" viewBox="0 0 14 18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 0C3.134 0 0 3.134 0 7c0 5.25 7 11 7 11s7-5.75 7-11c0-3.866-3.134-7-7-7zm0 9.5A2.5 2.5 0 1 1 7 4.5a2.5 2.5 0 0 1 0 5z" fill="#01BE4A"/></svg>
                            {{ $full_address }}
                        </p>
                    @endif
                </div>
            </header>

            {{-- Taksonomie --}}
            @if((!empty($rodzaje) && !is_wp_error($rodzaje)) || (!empty($grupy) && !is_wp_error($grupy)))
            <div class="osrodek-tags">
                @if(!empty($rodzaje) && !is_wp_error($rodzaje))
                    @foreach($rodzaje as $term)
                        <span class="osrodek-tag osrodek-tag--rodzaj">{{ $term->name }}</span>
                    @endforeach
                @endif
                @if(!empty($grupy) && !is_wp_error($grupy))
                    @foreach($grupy as $term)
                        <span class="osrodek-tag osrodek-tag--grupa">{{ $term->name }}</span>
                    @endforeach
                @endif
            </div>
            @endif

            {{-- Opis --}}
            @if(get_the_content())
            <div class="osrodek-content">
                {!! apply_filters('the_content', get_the_content()) !!}
            </div>
            @endif

            <div class="osrodek-grid">

                {{-- Kontakt --}}
                <section class="osrodek-section">
                    <h2 class="osrodek-section__title">Kontakt</h2>
                    <ul class="osrodek-contact-list">
                        @foreach(array_filter([$telefon, $telefon2, $telefon3]) as $tel)
                            <li>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6.6 10.8a15.68 15.68 0 0 0 6.6 6.6l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.58.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C10.16 21 3 13.84 3 5c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.24 1.02L6.6 10.8z" fill="#01BE4A"/></svg>
                                <a href="tel:{{ preg_replace('/\s+/', '', $tel) }}">{{ esc_html($tel) }}</a>
                            </li>
                        @endforeach
                        @if($email)
                            <li>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z" fill="#01BE4A"/></svg>
                                <a href="mailto:{{ esc_attr($email) }}">{{ esc_html($email) }}</a>
                            </li>
                        @endif
                        @if($www)
                            <li>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z" fill="#01BE4A"/></svg>
                                <a href="{{ esc_url($www) }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://#', '', rtrim($www, '/')) }}</a>
                            </li>
                        @endif
                    </ul>

                    @if($facebook || $instagram || $tiktok)
                    <div class="osrodek-social">
                        @if($facebook)
                            <a href="{{ esc_url($facebook) }}" target="_blank" rel="noopener" class="osrodek-social__link" aria-label="Facebook">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                            </a>
                        @endif
                        @if($instagram)
                            <a href="{{ esc_url($instagram) }}" target="_blank" rel="noopener" class="osrodek-social__link" aria-label="Instagram">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                            </a>
                        @endif
                        @if($tiktok)
                            <a href="{{ esc_url($tiktok) }}" target="_blank" rel="noopener" class="osrodek-social__link" aria-label="TikTok">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.22 8.22 0 0 0 4.78 1.52V6.76a4.85 4.85 0 0 1-1.01-.07z"/></svg>
                            </a>
                        @endif
                    </div>
                    @endif
                </section>

                {{-- Godziny otwarcia --}}
                <section class="osrodek-section">
                    <h2 class="osrodek-section__title">Godziny otwarcia</h2>
                    <table class="osrodek-hours">
                        @foreach($days as $prefix => $label)
                            @php
                                $open     = (string) get_post_meta($post_id, "{$prefix}_otwarcie",   true);
                                $close    = (string) get_post_meta($post_id, "{$prefix}_zamkniecie", true);
                                $closed   = (string) get_post_meta($post_id, "{$prefix}_zamkniete",  true);
                                $isClosed = $closed === 'tak' || ($open === '' && $close === '');
                            @endphp
                            <tr class="osrodek-hours__row {{ $isClosed ? 'is-closed' : '' }}">
                                <td class="osrodek-hours__day">{{ $label }}</td>
                                <td class="osrodek-hours__time">
                                    @if(!$isClosed && ($open || $close))
                                        {{ $open }} – {{ $close }}
                                    @else
                                        <span class="osrodek-hours__closed">Zamknięte</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </section>

            </div>{{-- /.osrodek-grid --}}

            {{-- Mapa (jeśli są koordynaty) --}}
            @if($lat && $lng)
            <section class="osrodek-section osrodek-map-section">
                <h2 class="osrodek-section__title">Lokalizacja</h2>
                <div
                    id="osrodek-map"
                    class="osrodek-map"
                    data-lat="{{ $lat }}"
                    data-lng="{{ $lng }}"
                    data-title="{{ esc_attr(get_the_title()) }}"
                ></div>
            </section>
            @endif

        </article>

    </div>
@endwhile
@endsection