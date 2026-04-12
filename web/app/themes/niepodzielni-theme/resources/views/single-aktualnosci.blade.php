@extends('layouts.app')

@section('content')
@php
  $post_id = get_the_ID();
  $zdj_g   = get_post_meta($post_id, 'zdjecie_glowne', true);
  $zdj_d   = get_post_meta($post_id, 'zdjeci_dodatkowe', true);
  $data_w  = get_post_meta($post_id, 'data_wydarzenia', true);
  $miejsce = get_post_meta($post_id, 'miejsce', true);

  $hero_url    = $zdj_g ? wp_get_attachment_image_url($zdj_g, 'full') : get_the_post_thumbnail_url($post_id, 'full');
  $gallery_ids = $zdj_d ? array_filter(explode(',', $zdj_d)) : [];
@endphp

{{-- 1. HERO — full-width photo --}}
@if($hero_url)
<div class="nsingle-hero nsingle-hero--medium">
  <img class="nsingle-hero__bg" src="{{ esc_url($hero_url) }}" alt="{{ esc_attr(get_the_title()) }}">
  <div class="nsingle-hero__overlay" style="background:linear-gradient(to bottom,rgba(0,0,0,.25) 0%,rgba(0,0,0,.05) 100%);"></div>
</div>
@endif

{{-- 2. GALLERY (zdjecia_dodatkowe) --}}
@if(!empty($gallery_ids))
<div class="nsingle-wrap">
  <div class="nsingle-gallery" style="margin-top:32px;">
    @foreach($gallery_ids as $gid)
      @php($gurl = wp_get_attachment_image_url(trim($gid), 'medium_large'))
      @if($gurl)
        <div class="nsingle-gallery__item">
          <img src="{{ esc_url($gurl) }}" alt="" loading="lazy">
        </div>
      @endif
    @endforeach
  </div>
</div>
@endif

{{-- 3. CONTENT BLOCK --}}
<div class="nsingle-content-block">
  <h1 class="nsingle-content-block__title">{{ get_the_title() }}</h1>

  <div class="nsingle-content-block__meta">
    @if($data_w)
      <span class="nsingle-pill nsingle-pill--green">{{ date_i18n('j F Y', strtotime($data_w)) }}</span>
    @endif
    @if($miejsce)
      <span class="nsingle-pill nsingle-pill--outlined-dark">📍 {{ esc_html($miejsce) }}</span>
    @endif
  </div>

  <div class="nsingle-content-block__content">
    {!! wp_kses_post(get_the_content()) !!}
  </div>
</div>

@endsection
