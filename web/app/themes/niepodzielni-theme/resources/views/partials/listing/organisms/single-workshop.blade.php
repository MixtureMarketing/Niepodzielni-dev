{{--
  Shared single view for: warsztaty + grupy-wsparcia
  Expected variables:
    $post_id   — int
    $label     — string, e.g. "Warsztat poprowadzi" / "Grupę poprowadzi"
--}}
@php
  $m = [];
  $keys = [
    'temat','tekst_-_wstep','opis','jesli_doswiadczasz','temat_718','tekst_dodatkowy',
    'data','godzina','godzina_zakonczenia','lokalizacja','typ','ulica_i_numer',
    'cena','cena_-_rodzaj',
    'prowadzacy_id','stanowisko','nurt','opis_117',
    // pola ręczne — fallback gdy prowadzacy_id nie ustawiony
    'imie_i_nazwisko','zdjecie_prowadzacego','link_do_psychologa',
    'zdjecie_glowne','id_uslugi',
  ];
  foreach ($keys as $k) {
    $m[$k] = get_post_meta($post_id, $k, true);
  }

  $title   = $m['temat'] ?: get_the_title($post_id);
  $bg_url  = $m['zdjecie_glowne'] ? wp_get_attachment_image_url($m['zdjecie_glowne'], 'full') : '';
  $is_free = empty($m['cena']) || (int)$m['cena'] === 0;

  // Dane prowadzącego — psycholog z bazy lub ręczne
  $prowadzacy_id = (int) $m['prowadzacy_id'];
  if ($prowadzacy_id) {
    $fac_name      = get_post_meta($prowadzacy_id, 'imie_i_nazwisko', true) ?: get_the_title($prowadzacy_id);
    $fac_photo_url = get_the_post_thumbnail_url($prowadzacy_id, 'medium') ?: '';
    $fac_link      = get_permalink($prowadzacy_id);

    // stanowisko — ręczny override lub specjalizacje z taksonomii
    if ($m['stanowisko']) {
      $fac_stanowisko = $m['stanowisko'];
    } else {
      $fac_stanowisko = implode( ', ', np_get_post_terms( $prowadzacy_id, 'specjalizacja' ) );
    }

    // nurt — ręczny override lub taksonomia
    if ($m['nurt']) {
      $fac_nurt = $m['nurt'];
    } else {
      $fac_nurt = implode( ', ', np_get_post_terms( $prowadzacy_id, 'nurt' ) );
    }

    // bio — ręczny override (opis_117) lub biogram psychologa
    $fac_bio = $m['opis_117'] ?: wp_strip_all_tags(get_post_meta($prowadzacy_id, 'biogram', true));
  } else {
    $fac_name       = $m['imie_i_nazwisko'];
    $fac_photo_url  = $m['zdjecie_prowadzacego']
      ? wp_get_attachment_image_url($m['zdjecie_prowadzacego'], 'medium')
      : '';
    $fac_link       = $m['link_do_psychologa'];
    $fac_stanowisko = $m['stanowisko'];
    $fac_nurt       = $m['nurt'];
    $fac_bio        = $m['opis_117'];
  }

  $symptoms = [];
  if (!empty($m['jesli_doswiadczasz'])) {
    $decoded = json_decode($m['jesli_doswiadczasz'], true);
    if (is_array($decoded)) $symptoms = array_column($decoded, 'value');
  }

  $topics = [];
  if (!empty($m['temat_718'])) {
    $decoded = json_decode($m['temat_718'], true);
    if (is_array($decoded)) $topics = $decoded;
  }
@endphp

{{-- 1. HERO --}}
<div class="nsingle-hero nsingle-hero--tall">
  @if($bg_url)
    <img class="nsingle-hero__bg" src="{{ esc_url($bg_url) }}" alt="{{ esc_attr($title) }}">
  @else
    <div class="nsingle-hero__bg" style="background: var(--mix-color-brand-secondary);"></div>
  @endif
  <div class="nsingle-hero__overlay"></div>

  <div class="nsingle-hero__inner">
    {{-- top-left: date + times --}}
    <div class="nsingle-hero__top-left">
      @if($m['data'])
        <span class="nsingle-pill nsingle-pill--green">
          {{ date_i18n('j F Y', strtotime($m['data'])) }}
        </span>
      @endif
      @if($m['godzina'])
        <span class="nsingle-pill nsingle-pill--outlined">
          {{ esc_html($m['godzina']) }}
          @if($m['godzina_zakonczenia'])–{{ esc_html($m['godzina_zakonczenia']) }}@endif
        </span>
      @endif
    </div>

    {{-- top-right: lokalizacja --}}
    <div class="nsingle-hero__top-right">
      @if($m['lokalizacja'])
        <span class="nsingle-pill nsingle-pill--outlined">
          📍 {{ esc_html($m['lokalizacja']) }}
        </span>
      @endif
    </div>

    {{-- center: title --}}
    <div class="nsingle-hero__title-wrap">
      <h1 class="nsingle-hero__title">{{ esc_html($title) }}</h1>
    </div>

    {{-- bottom-left: facilitator --}}
    <div class="nsingle-hero__bottom-left">
      @if($fac_name)
        <span class="nsingle-hero__meta-text">{{ esc_html($label) }}</span>
        <span class="nsingle-hero__facilitator-name">{{ esc_html($fac_name) }}</span>
      @endif
    </div>

    {{-- bottom-right: CTA --}}
    @if($m['id_uslugi'])
    <div class="nsingle-hero__bottom-right">
      <a href="#bookero" class="nsingle-hero__cta">Zapisz się →</a>
    </div>
    @endif
  </div>
</div>

{{-- 2. MAIN CONTENT --}}
<div class="nsingle-wrap">
  <div class="nsingle-section">
    <div class="nsingle-two-col">

      {{-- LEFT: wstep + symptoms + opis --}}
      <div class="nsingle-main-col">
        @if($m['tekst_-_wstep'])
          <p class="nsingle-intro">{{ esc_html($m['tekst_-_wstep']) }}</p>
        @endif

        @if(!empty($symptoms))
          <h3 class="nsingle-section-heading" style="font-size:1rem;">Jeśli doświadczasz…</h3>
          <ul class="nsingle-symptom-list">
            @foreach($symptoms as $s)
              @if($s)
                <li>{{ esc_html($s) }}</li>
              @endif
            @endforeach
          </ul>
        @endif

        @if($m['opis'])
          <div class="nsingle-content-block__content" style="margin-top:16px;">
            {!! wp_kses_post(wpautop($m['opis'])) !!}
          </div>
        @endif
      </div>

      {{-- RIGHT: facilitator card --}}
      <div class="nsingle-side-col">
        <div class="nsingle-facilitator-card">
          @if($fac_photo_url)
            <img class="nsingle-facilitator-card__photo"
                 src="{{ esc_url($fac_photo_url) }}"
                 alt="{{ esc_attr($fac_name) }}">
          @endif
          <p class="nsingle-facilitator-card__label">{{ esc_html($label) }}</p>
          @if($fac_name)
            <p class="nsingle-facilitator-card__name">{{ esc_html($fac_name) }}</p>
          @endif
          @if($fac_stanowisko)
            <p class="nsingle-facilitator-card__role">{{ esc_html($fac_stanowisko) }}</p>
          @endif
          @if($fac_nurt)
            <p class="nsingle-facilitator-card__role" style="font-style:italic;">{{ esc_html($fac_nurt) }}</p>
          @endif
          @if($fac_bio)
            <p class="nsingle-facilitator-card__bio">{{ esc_html($fac_bio) }}</p>
          @endif
          @if($fac_link)
            <a href="{{ esc_url($fac_link) }}" class="nsingle-facilitator-card__link">
              Zobacz profil →
            </a>
          @endif
        </div>
      </div>

    </div>
  </div>

  {{-- 3. START BAR --}}
  @if($m['data'] || $m['lokalizacja'])
  <div class="nsingle-start-bar">
    @if($m['data'])
    <div class="nsingle-start-bar__item">
      <span class="nsingle-start-bar__label">Start</span>
      <span class="nsingle-start-bar__value">
        {{ date_i18n('j F Y', strtotime($m['data'])) }}
        @if($m['godzina'])
          {{ $m['godzina'] }}@if($m['godzina_zakonczenia'])–{{ $m['godzina_zakonczenia'] }}@endif
        @endif
      </span>
    </div>
    @endif
    @if($m['typ'] || $m['lokalizacja'])
    <div class="nsingle-start-bar__item">
      <span class="nsingle-start-bar__label">Miejsce</span>
      <span class="nsingle-start-bar__value">
        @if($m['typ']){{ esc_html($m['typ']) }} – @endif{{ esc_html($m['lokalizacja']) }}
        @if($m['ulica_i_numer'])<br><small style="font-weight:400;opacity:.85;">{{ esc_html($m['ulica_i_numer']) }}</small>@endif
      </span>
    </div>
    @endif
  </div>
  @endif

  {{-- 4. PRICE + TOPICS --}}
  @if(!empty($topics) || $m['tekst_dodatkowy'] || $m['cena'])
  <div class="nsingle-section">
    <div class="nsingle-price-topics">

      {{-- LEFT: price --}}
      <div class="nsingle-price-box">
        <p class="nsingle-price-box__heading">Cena uczestnictwa</p>
        @if($is_free)
          <p class="nsingle-price-box__free">Bezpłatna</p>
          <p class="nsingle-price-box__note">
            Inicjatywa jest realizowana ze środków Samorządu Województwa Mazowieckiego
            w ramach projektu Niepodzielni.
          </p>
        @else
          <p class="nsingle-price-box__amount">{{ esc_html($m['cena']) }} zł</p>
          @if($m['cena_-_rodzaj'])
            <p class="nsingle-price-box__note">{{ esc_html($m['cena_-_rodzaj']) }}</p>
          @endif
          <p class="nsingle-price-box__note" style="color:#888;font-size:.78rem;">
            Możliwość płatności w ratach przez Klarna.
          </p>
        @endif
      </div>

      {{-- RIGHT: topics --}}
      <div>
        @if($m['tekst_dodatkowy'])
          <p style="color:var(--mix-color-text-subtle);font-size:.95rem;line-height:1.6;margin-bottom:20px;">
            {!! wp_kses_post(wpautop($m['tekst_dodatkowy'])) !!}
          </p>
        @endif
        @if(!empty($topics))
          <h3 class="nsingle-section-heading" style="font-size:1rem;">Poruszane tematy</h3>
          <ul class="nsingle-topic-list">
            @foreach($topics as $topic)
              <li class="nsingle-topic-list__item">
                @if(!empty($topic['temat']))
                  <p class="nsingle-topic-list__title">{{ esc_html($topic['temat']) }}</p>
                @endif
                @if(!empty($topic['opis']))
                  <p class="nsingle-topic-list__desc">{{ esc_html($topic['opis']) }}</p>
                @endif
              </li>
            @endforeach
          </ul>
        @endif
      </div>

    </div>
  </div>
  @endif

  {{-- 5. BOOKERO --}}
  @php
    $zapisy_off    = ($m['status'] ?? '') === 'Zapisy zakończone';
    $calendar_html = (!$zapisy_off && $m['id_uslugi']) ? do_shortcode('[bookero_kalendarz]') : '';
  @endphp
  @if($m['id_uslugi'] || $zapisy_off)
  <div id="bookero" class="nsingle-bookero">
    @if($zapisy_off)
      <div class="nsingle-zapisy-zakonczone">
        <p class="nsingle-zapisy-zakonczone__icon">🔒</p>
        <p class="nsingle-zapisy-zakonczone__title">Zapisy zakończone</p>
        <p class="nsingle-zapisy-zakonczone__desc">Rejestracja na to wydarzenie jest już zamknięta.</p>
      </div>
    @else
      {!! $calendar_html !!}
      {{-- JS fallback: gdy Bookero widget nie wyrenderuje treści po 8s --}}
      <div id="bookero-fallback" class="nsingle-zapisy-zakonczone" style="display:none;">
        <p class="nsingle-zapisy-zakonczone__icon">🔒</p>
        <p class="nsingle-zapisy-zakonczone__title">Zapisy zakończone</p>
        <p class="nsingle-zapisy-zakonczone__desc">Rejestracja na to wydarzenie jest już zamknięta.</p>
      </div>
      <script>
      (function() {
        var TIMEOUT = 8000;
        function checkBookero() {
          var plugin  = document.getElementById('bookero-plugin');
          var wrapper = document.getElementById('bookero_wrapper');
          var fallback = document.getElementById('bookero-fallback');
          if (!fallback) return;
          // Widget wyrenderował się poprawnie jeśli bookero-plugin istnieje i ma treść
          var hasContent = plugin && plugin.innerText && plugin.innerText.trim().length > 10;
          if (!hasContent) {
            if (wrapper) wrapper.style.display = 'none';
            fallback.style.display = 'block';
          }
        }
        setTimeout(checkBookero, TIMEOUT);
      })();
      </script>
    @endif
  </div>
  @endif

</div>{{-- /.nsingle-wrap --}}
