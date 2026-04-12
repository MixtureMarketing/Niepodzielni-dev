@php
// ---- Mega menu — cache 1h, kasowany przy save_post ----
$wydarzenia_items = get_transient('np_mega_menu_events');
if ($wydarzenia_items === false) {
    $q = new WP_Query([
        'post_type'      => ['warsztaty', 'grupy-wsparcia'],
        'posts_per_page' => 4,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ]);
    $wydarzenia_items = array_map(fn($id) => [
        'title' => get_the_title($id),
        'link'  => get_permalink($id),
    ], $q->posts);
    set_transient('np_mega_menu_events', $wydarzenia_items, HOUR_IN_SECONDS);
}

$posts_items = get_transient('np_mega_menu_posts');
if ($posts_items === false) {
    $q = new WP_Query([
        'post_type'      => 'post',
        'posts_per_page' => 5,
        'post_status'    => 'publish',
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ]);
    $posts_items = array_map(fn($id) => [
        'title' => get_the_title($id),
        'link'  => get_permalink($id),
        'thumb' => get_the_post_thumbnail_url($id, 'medium_large') ?: '',
    ], $q->posts);
    set_transient('np_mega_menu_posts', $posts_items, HOUR_IN_SECONDS);
}
@endphp

<header id="site-header" class="site-header">
    <div class="psy-container header-flex">

        {{-- LOGO --}}
        <div class="header-logo">
            <a href="{{ home_url('/') }}">
                <img src="/wp-content/uploads/2025/06/Clip-path-group.svg" alt="Niepodzielni Logo" width="180" height="auto">
            </a>
        </div>

        {{-- NAVIGATION RIGHT --}}
        <div class="header-actions">

            {{-- WIDGET UMÓW SIĘ --}}
            @include('partials.shortcodes.widget-umow-sie')

            {{-- BURGER (TRIGGER) --}}
            <label class="burger" for="burger">
                <input type="checkbox" id="burger">
                <span></span>
                <span></span>
                <span></span>
            </label>

        </div>
    </div>
</header>

{{-- MEGA MENU CONTENT --}}
<div class="mega_menu">
    <div class="psy-container mega-menu-split">

        {{-- LEWA STRONA: LINKI I STOPKA --}}
        <div class="mega-menu-main">
            <div class="mega-menu-grid">

                {{-- KOLUMNA 1: UMÓW SIĘ --}}
                <div class="mega-menu-col">
                    <h3 class="mega-menu-title title-green">UMÓW SIĘ</h3>
                    <ul class="mega-menu-list">
                        <li><a href="/konsultacje-niskoplatne/">KONSULTACJE NISKOPŁATNE</a></li>
                        <li><a href="/konsultacje-psychologiczne-pelnoplatne/">KONSULTACJE PEŁNOPŁATNE</a></li>
                        <li><a href="/asystenci-zdrowienia/">ASYSTENT ZDROWIENIA</a></li>
                        <li><a href="/diagnoza-adhd-u-doroslych/">DIAGNOZA ADHD</a></li>
                    </ul>
                </div>

                {{-- KOLUMNA 2: WYDARZENIA --}}
                <div class="mega-menu-col">
                    <h3 class="mega-menu-title">Wydarzenia</h3>
                    <ul class="mega-menu-list">
                        @foreach($wydarzenia_items as $item)
                            <li><a href="{{ $item['link'] }}">{{ $item['title'] }}</a></li>
                        @endforeach
                        <li class="mega-menu-more-link"><a href="/warsztaty-i-grupy-wsparcia/" class="mega-menu-more">ZOBACZ WSZYSTKIE</a></li>
                    </ul>
                </div>

                {{-- KOLUMNA 3: PSYCHOEDUKACJA --}}
                <div class="mega-menu-col">
                    <h3 class="mega-menu-title">Psychoedukacja</h3>
                    <ul class="mega-menu-list">
                        @foreach(array_slice($posts_items, 0, 4) as $item)
                            <li><a href="{{ $item['link'] }}">{{ $item['title'] }}</a></li>
                        @endforeach
                        <li class="mega-menu-more-link"><a href="/psycho-edukacja/" class="mega-menu-more">ZOBACZ WSZYSTKIE</a></li>
                    </ul>
                </div>

                {{-- KOLUMNA 4: WIĘCEJ --}}
                <div class="mega-menu-col">
                    <h3 class="mega-menu-title">WIĘCEJ</h3>
                    <ul class="mega-menu-list">
                        <li><a href="/o-nas/">O nas</a></li>
                        <li><a href="/wesprzyj-nas/">Wesprzyj Nas</a></li>
                        <li><a href="/projekt-psychon/">PsychON</a></li>
                        <li><a href="/aktualnosci/">Aktualności</a></li>
                        <li><a href="/kontakt/">Kontakt</a></li>
                        <li><a href="/warsztaty-i-grupy-wsparcia/">Wydarzenia</a></li>
                    </ul>
                </div>
            </div>

            {{-- DOLNY PASEK MENU --}}
            <div class="mega-menu-footer">
                <div class="mega-menu-emergency">
                    <span class="emergency-number">112</span>
                    <p>Numer alarmowy w sytuacji<br>zagrożenia życia lub zdrowia</p>
                </div>

                <div class="mega-menu-contact">
                    <h4>KONTAKT</h4>
                    <div class="contact-links">
                        <a href="mailto:kontakt@niepodzielni.com">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 17 17" fill="none"><rect x="1" y="1" width="15" height="15" rx="7.5" stroke="#01BE4A"></rect><path d="M5.50002 7.99988C5.22388 7.99987 5.00001 8.22372 5 8.49986C4.99999 8.776 5.22384 8.99987 5.49998 8.99988L5.50002 7.99988ZM11.8535 8.85369C12.0488 8.65844 12.0488 8.34185 11.8536 8.14658L8.67172 4.96447C8.47646 4.7692 8.15988 4.76919 7.96461 4.96444C7.76934 5.1597 7.76933 5.47628 7.96458 5.67155L10.7929 8.50009L7.96435 11.3284C7.76908 11.5237 7.76907 11.8402 7.96432 12.0355C8.15958 12.2308 8.47616 12.2308 8.67143 12.0355L11.8535 8.85369ZM5.5 8.49988L5.49998 8.99988L11.5 9.00012L11.5 8.50012L11.5 8.00012L5.50002 7.99988L5.5 8.49988Z" fill="#01BE4A"></path></svg>
                            kontakt@niepodzielni.com
                        </a>
                        <a href="tel:+48668277176">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 17 17" fill="none"><rect x="1" y="1" width="15" height="15" rx="7.5" stroke="#01BE4A"></rect><path d="M5.50002 7.99988C5.22388 7.99987 5.00001 8.22372 5 8.49986C4.99999 8.776 5.22384 8.99987 5.49998 8.99988L5.50002 7.99988ZM11.8535 8.85369C12.0488 8.65844 12.0488 8.34185 11.8536 8.14658L8.67172 4.96447C8.47646 4.7692 8.15988 4.76919 7.96461 4.96444C7.76934 5.1597 7.76933 5.47628 7.96458 5.67155L10.7929 8.50009L7.96435 11.3284C7.76908 11.5237 7.76907 11.8402 7.96432 12.0355C8.15958 12.2308 8.47616 12.2308 8.67143 12.0355L11.8535 8.85369ZM5.5 8.49988L5.49998 8.99988L11.5 9.00012L11.5 8.50012L11.5 8.00012L5.50002 7.99988L5.5 8.49988Z" fill="#01BE4A"></path></svg>
                            (+48) 668 277 176
                        </a>
                    </div>
                </div>

                <div class="mega-menu-socials">
                    <h4>OBSERWUJ NAS</h4>
                    @include('partials.social-icons')
                </div>

                <div class="mega-menu-legal">
                    <a href="/standardy-ochrony-maloletnich-fundacji-niepodzielni/">STANDARDY<br>OCHRONY<br>MAŁOLETNICH</a>
                </div>
            </div>
        </div>

        {{-- PRAWA STRONA: SLIDER --}}
        <div class="mega-menu-sidebar">
            <div class="mega-menu-slider" id="megaMenuSlider">
                <div class="slider-track">
                    @foreach($posts_items as $item)
                        <div class="slider-slide">
                            <a href="{{ $item['link'] }}" class="mega-menu-featured-card">
                                <div class="featured-image-wrapper">
                                    @if($item['thumb'])
                                        <img src="{{ $item['thumb'] }}" alt="{{ $item['title'] }}" loading="lazy">
                                    @endif
                                </div>
                                <div class="featured-content">
                                    <span class="featured-tag">Polecane</span>
                                    <div class="featured-header-row">
                                        <h4 class="featured-title">{{ $item['title'] }}</h4>
                                        <div class="featured-arrow">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="52" height="30" viewBox="0 0 52 30" fill="none"><rect x="0.75" y="0.75" width="50.5" height="28.5" rx="14.25" stroke="#01BE4A" stroke-width="1.5"></rect><path d="M21 14.25C20.5858 14.25 20.25 14.5858 20.25 15C20.25 15.4142 20.5858 15.75 21 15.75V14.25ZM32.5303 15.5303C32.8232 15.2374 32.8232 14.7626 32.5303 14.4697L27.7574 9.6967C27.4645 9.40381 26.9896 9.40381 26.6967 9.6967C26.4038 9.98959 26.4038 10.4645 26.6967 10.7574L30.9393 15L26.6967 19.2426C26.4038 19.5355 26.4038 20.0104 26.6967 20.3033C26.9896 20.5962 27.4645 20.5962 27.7574 20.3033L32.5303 15.5303ZM21 15V15.75L32 15.75V15V14.25L21 14.25V15Z" fill="#01BE4A"></path></svg>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    @endforeach
                </div>
                <div class="slider-dots" id="megaMenuSliderDots"></div>
            </div>
        </div>

    </div>
</div>
