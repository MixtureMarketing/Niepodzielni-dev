{{--
  Single view for WP post (post_type = post)
  Used primarily for Psychoedukacja articles (assigned the "psychoedukacja" tag).
--}}
@php
  $post_id    = get_the_ID();
  $bg_url     = get_the_post_thumbnail_url($post_id, 'full');
  $tags       = get_the_tags($post_id) ?: [];
@endphp

{{-- HERO with dark overlay --}}
<div class="nsingle-hero nsingle-hero--medium">
  @if($bg_url)
    <img class="nsingle-hero__bg" src="{{ esc_url($bg_url) }}" alt="{{ esc_attr(get_the_title()) }}">
  @else
    <div class="nsingle-hero__bg" style="background: linear-gradient(135deg, var(--mix-color-brand-secondary) 0%, #0b009e 100%);"></div>
  @endif
  <div class="nsingle-hero__overlay"></div>

  <div class="nsingle-post-hero__inner">
    {{-- top: ARTYKUŁ pill + tags --}}
    <div class="nsingle-post-hero__tags">
      <span class="nsingle-pill nsingle-pill--green">Artykuł</span>
      @foreach($tags as $tag)
        <span class="nsingle-pill nsingle-pill--outlined">{{ esc_html($tag->name) }}</span>
      @endforeach
    </div>

    {{-- bottom: title --}}
    <h1 class="nsingle-post-hero__title">{{ get_the_title() }}</h1>
  </div>
</div>

{{-- CONTENT --}}
<div class="nsingle-content-block">
  <div class="nsingle-content-block__content">
    {!! wp_kses_post(get_the_content()) !!}
  </div>
</div>
