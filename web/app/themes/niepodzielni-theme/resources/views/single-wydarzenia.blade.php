@extends('layouts.app')

@section('content')
@php
  $post_id = get_the_ID();
  $m = [];
  $keys = [
    'data','godzina_rozpoczecia','godzina_zakonczenia','miasto','lokalizacja','koszt',
    'opis','opis_-_na_zdjecie','tutil_-_rozszerzony','wyrozniona_czesc',
    'zdjecie_tla','zdjecie','poprzednmie_edycje','id_uslugi',
  ];
  foreach ($keys as $k) {
    $m[$k] = get_post_meta($post_id, $k, true);
  }

  $title    = $m['tutil_-_rozszerzony'] ?: get_the_title($post_id);
  $subtitle = $m['wyrozniona_czesc'];

  // Background image — prefer zdjecie_tla, fallback to zdjecie, fallback to featured image
  $bg_id  = $m['zdjecie_tla'] ?: $m['zdjecie'] ?: get_post_thumbnail_id($post_id);
  $bg_url = $bg_id ? wp_get_attachment_image_url($bg_id, 'full') : '';

  // Gallery from poprzednmie_edycje (comma-sep IDs)
  $gallery_ids = $m['poprzednmie_edycje'] ? array_filter(explode(',', $m['poprzednmie_edycje'])) : [];
@endphp

{{-- 1. HERO --}}
<div class="nsingle-hero nsingle-hero--medium">
  @if($bg_url)
    <img class="nsingle-hero__bg" src="{{ esc_url($bg_url) }}" alt="{{ esc_attr($title) }}">
  @else
    <div class="nsingle-hero__bg" style="background: var(--mix-color-brand-secondary);"></div>
  @endif
  <div class="nsingle-hero__overlay"></div>

  <div class="nsingle-hero__inner">
    {{-- top pills --}}
    <div class="nsingle-hero__top-left">
      @if($m['data'])
        <span class="nsingle-pill nsingle-pill--green">
          {{ date_i18n('j F Y', strtotime($m['data'])) }}
        </span>
      @endif
      @if($m['miasto'])
        <span class="nsingle-pill nsingle-pill--outlined">📍 {{ esc_html($m['miasto']) }}</span>
      @endif
      @if($m['koszt'])
        <span class="nsingle-pill nsingle-pill--outlined">{{ esc_html($m['koszt']) }}</span>
      @endif
    </div>

    <div></div>{{-- spacer --}}

    {{-- center title --}}
    <div class="nsingle-hero__title-wrap">
      <div>
        @if($subtitle)
          <p style="color:rgba(255,255,255,0.75);text-align:center;font-size:.9rem;text-transform:uppercase;letter-spacing:.12em;margin-bottom:10px;">
            {{ esc_html($subtitle) }}
          </p>
        @endif
        <h1 class="nsingle-hero__title">{{ esc_html($title) }}</h1>
      </div>
    </div>

    {{-- bottom --}}
    <div class="nsingle-hero__bottom-left">
      @if($m['opis_-_na_zdjecie'])
        <p style="color:rgba(255,255,255,.85);font-size:.9rem;max-width:500px;">
          {{ esc_html($m['opis_-_na_zdjecie']) }}
        </p>
      @endif
    </div>

    @if($m['id_uslugi'])
    <div class="nsingle-hero__bottom-right">
      <a href="#bookero" class="nsingle-hero__cta">Zapisz się →</a>
    </div>
    @endif
  </div>
</div>

<div class="nsingle-wrap">

  {{-- 2. INFO BAR --}}
  @if($m['data'] || $m['godzina_rozpoczecia'] || $m['lokalizacja'] || $m['koszt'])
  <div class="nsingle-info-bar">
    @if($m['data'])
    <div class="nsingle-info-bar__item">
      <span class="nsingle-info-bar__label">Data</span>
      <span class="nsingle-info-bar__value">{{ date_i18n('j F Y', strtotime($m['data'])) }}</span>
    </div>
    @endif
    @if($m['godzina_rozpoczecia'])
    <div class="nsingle-info-bar__item">
      <span class="nsingle-info-bar__label">Godziny</span>
      <span class="nsingle-info-bar__value">
        {{ esc_html($m['godzina_rozpoczecia']) }}
        @if($m['godzina_zakonczenia'])–{{ esc_html($m['godzina_zakonczenia']) }}@endif
      </span>
    </div>
    @endif
    @if($m['lokalizacja'])
    <div class="nsingle-info-bar__item">
      <span class="nsingle-info-bar__label">Lokalizacja</span>
      <span class="nsingle-info-bar__value">{{ esc_html($m['lokalizacja']) }}</span>
    </div>
    @endif
    @if($m['koszt'])
    <div class="nsingle-info-bar__item">
      <span class="nsingle-info-bar__label">Koszt</span>
      <span class="nsingle-info-bar__value">{{ esc_html($m['koszt']) }}</span>
    </div>
    @endif
  </div>
  @endif

  {{-- 3. OPIS --}}
  @if($m['opis'])
  <div class="nsingle-section">
    <div class="nsingle-content-block__content" style="max-width:800px;">
      {!! wp_kses_post(wpautop($m['opis'])) !!}
    </div>
  </div>
  @endif

  {{-- 4. GALLERY (poprzednie edycje) --}}
  @if(!empty($gallery_ids))
  <div class="nsingle-section">
    <h2 class="nsingle-section-heading">Poprzednie edycje</h2>
    <div class="nsingle-gallery">
      @foreach($gallery_ids as $gid)
        @php $gurl = wp_get_attachment_image_url(trim($gid), 'medium_large'); @endphp
        @if($gurl)
          <div class="nsingle-gallery__item">
            <img src="{{ esc_url($gurl) }}" alt="" loading="lazy">
          </div>
        @endif
      @endforeach
    </div>
  </div>
  @endif

  {{-- 5. PRICE + BOOKERO --}}
  @if($m['koszt'] || $m['id_uslugi'])
  <div class="nsingle-section">
    <div class="nsingle-price-topics">

      <div class="nsingle-price-box">
        <p class="nsingle-price-box__heading">Koszt uczestnictwa</p>
        @php $is_free = empty($m['koszt']) || strtolower(trim($m['koszt'])) === 'bezpłatne' || strtolower(trim($m['koszt'])) === '0'; @endphp
        @if($is_free)
          <p class="nsingle-price-box__free">Bezpłatne</p>
          <p class="nsingle-price-box__note">
            Wydarzenie jest organizowane bezpłatnie w ramach projektu Niepodzielni.
          </p>
        @else
          <p class="nsingle-price-box__amount">{{ esc_html($m['koszt']) }}</p>
        @endif
      </div>

      @php
        $wyd_status    = get_post_meta($post_id, 'status', true);
        $zapisy_off    = $wyd_status === 'Zapisy zakończone';
        $calendar_html = (!$zapisy_off && $m['id_uslugi']) ? do_shortcode('[bookero_kalendarz]') : '';
      @endphp
      @if($m['id_uslugi'] || $zapisy_off)
      <div id="bookero">
        @if($zapisy_off)
          <div class="nsingle-zapisy-zakonczone">
            <p class="nsingle-zapisy-zakonczone__icon">🔒</p>
            <p class="nsingle-zapisy-zakonczone__title">Zapisy zakończone</p>
            <p class="nsingle-zapisy-zakonczone__desc">Rejestracja na to wydarzenie jest już zamknięta.</p>
          </div>
        @else
          {!! $calendar_html !!}
          <div id="bookero-fallback" class="nsingle-zapisy-zakonczone" style="display:none;">
            <p class="nsingle-zapisy-zakonczone__icon">🔒</p>
            <p class="nsingle-zapisy-zakonczone__title">Zapisy zakończone</p>
            <p class="nsingle-zapisy-zakonczone__desc">Rejestracja na to wydarzenie jest już zamknięta.</p>
          </div>
          <script>
          (function() {
            setTimeout(function() {
              var plugin  = document.getElementById('bookero-plugin');
              var wrapper = document.getElementById('bookero_wrapper');
              var fallback = document.getElementById('bookero-fallback');
              if (!fallback) return;
              var hasContent = plugin && plugin.innerText && plugin.innerText.trim().length > 10;
              if (!hasContent) {
                if (wrapper) wrapper.style.display = 'none';
                fallback.style.display = 'block';
              }
            }, 8000);
          })();
          </script>
        @endif
      </div>
      @endif

    </div>
  </div>
  @endif

</div>{{-- /.nsingle-wrap --}}

@endsection
