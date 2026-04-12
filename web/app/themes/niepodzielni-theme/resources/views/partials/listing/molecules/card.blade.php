{{--
  Universal Listing Card — article | event | workshop
  Required: $variant (string), $item (array)

  article keys: id, title, date, excerpt, thumb, link, miejsce (opt), tags (opt)
  event   keys: id, title, date, time_start, time_end, miasto, lokalizacja, koszt, opis, thumb, link, is_upcoming
  workshop keys: id, post_type, title, date, time, lokalizacja, status, cena, cena_rodzaj, prowadzacy, stanowisko, thumb, link, excerpt, is_active
--}}
@php
    $variant = $variant ?? 'article';

    // Workshop-specific logic
    if ( $variant === 'workshop' ) {
        $type_label = ( $item['post_type'] === 'grupy-wsparcia' ) ? 'Grupa wsparcia' : 'Warsztat';
        $type_class  = ( $item['post_type'] === 'grupy-wsparcia' ) ? 'nlisting-card--group' : 'nlisting-card--workshop';

        $status_raw  = $item['status'] ?? '';
        $badge_label = '';
        $badge_class = '';
        if ( $item['is_active'] ) {
            if ( $status_raw === 'Zapisy trwają' || $status_raw === '' ) {
                $badge_label = 'Wolne zapisy';
                $badge_class = 'badge--green';
            } elseif ( $status_raw === 'Zapisy wstrzymane' ) {
                $badge_label = 'Zapisy wstrzymane';
                $badge_class = 'badge--orange';
            } elseif ( $status_raw === 'Zapisy zakończone' ) {
                $badge_label = 'Zapisy zakończone';
                $badge_class = 'badge--grey';
            } else {
                $badge_label = $status_raw;
                $badge_class = 'badge--grey';
            }
        }
    }

    // Excerpt key differs per variant
    $excerpt_text = match( $variant ) {
        'event'    => $item['opis'] ?? '',
        default    => $item['excerpt'] ?? '',
    };

    // CTA label
    $cta_label = ( $variant === 'workshop' ) ? 'Szczegóły' : 'Czytaj więcej';

    // Location label (event combines miasto + lokalizacja)
    $place = '';
    if ( $variant === 'event' ) {
        $place = implode( ', ', array_filter( [ $item['miasto'] ?? '', $item['lokalizacja'] ?? '' ] ) );
    } elseif ( $variant === 'article' ) {
        $place = $item['miejsce'] ?? '';
    } else {
        $place = $item['lokalizacja'] ?? '';
    }
@endphp

<article
    class="nlisting-card nlisting-card--{{ $variant }}{{ $variant === 'workshop' ? ' ' . $type_class . ( !$item['is_active'] ? ' is-inactive' : '' ) : '' }}"
    @if( $variant === 'article' ) data-tags="{{ implode(',', $item['tags'] ?? []) }}" @endif
    @if( $variant === 'event' ) data-upcoming="{{ $item['is_upcoming'] ? '1' : '0' }}" @endif
    @if( $variant === 'workshop' ) data-post-type="{{ $item['post_type'] }}" data-active="{{ $item['is_active'] ? '1' : '0' }}" @endif
>
    <a href="{{ $item['link'] }}" class="nlisting-card__media-link" tabindex="-1" aria-hidden="true">
        <div class="nlisting-card__media">
            @if( !empty($item['thumb']) )
                <img src="{{ $item['thumb'] }}" alt="{{ $item['title'] }}" loading="lazy">
            @else
                <div class="nlisting-card__media-placeholder"></div>
            @endif
            @if( $variant === 'article' )
                <div class="nlisting-card__overlay">
                    <h3 class="nlisting-card__overlay-title">{{ $item['title'] }}</h3>
                </div>
            @endif
            @if( $variant === 'workshop' )
                <span class="nlisting-card__type-tag">{{ $type_label }}</span>
            @endif
        </div>
    </a>

    <div class="nlisting-card__body">

        {{-- META BAR: date + location --}}
        @if( !empty($item['date']) || !empty($place) )
            <div class="nlisting-card__meta">
                @if( !empty($item['date']) )
                    <span class="nlisting-card__date">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><rect x="1" y="2" width="10" height="9" rx="1" stroke="currentColor" stroke-width="1.2"/><path d="M1 5h10M4 1v2M8 1v2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                        {{ date_i18n( 'j F Y', strtotime( $item['date'] ) ) }}
                        @if( $variant === 'event' && !empty($item['time_start']) )
                            &nbsp;{{ $item['time_start'] }}@if( !empty($item['time_end']) )–{{ $item['time_end'] }}@endif
                        @endif
                        @if( $variant === 'workshop' && !empty($item['time']) )
                            &nbsp;{{ $item['time'] }}
                        @endif
                    </span>
                @endif
                @if( !empty($place) )
                    <span class="nlisting-card__place">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M6 1C4.34 1 3 2.34 3 4c0 2.25 3 7 3 7s3-4.75 3-7c0-1.66-1.34-3-3-3z" stroke="currentColor" stroke-width="1.2"/><circle cx="6" cy="4" r="1" fill="currentColor"/></svg>
                        {{ $place }}
                    </span>
                @endif
            </div>
        @endif

        <h3 class="nlisting-card__title">
            <a href="{{ $item['link'] }}">{{ $item['title'] }}</a>
        </h3>

        @if( !empty($excerpt_text) )
            <p class="nlisting-card__excerpt">{{ $excerpt_text }}</p>
        @endif

        {{-- Workshop: prowadzący --}}
        @if( $variant === 'workshop' && !empty($item['prowadzacy']) )
            <div class="nlisting-card__author">
                <span class="nlisting-card__author-name">{{ $item['prowadzacy'] }}</span>
                @if( !empty($item['stanowisko']) )
                    <span class="nlisting-card__author-role">{{ $item['stanowisko'] }}</span>
                @endif
            </div>
        @endif

        {{-- FOOTER: badge/price/CTA --}}
        @if( in_array($variant, ['event', 'workshop']) || !empty($item['koszt']) || !empty($item['cena']) )
            <div class="nlisting-card__footer">
                @if( $variant === 'workshop' && $badge_label )
                    <span class="nlisting-badge {{ $badge_class }}">{{ $badge_label }}</span>
                @endif
                @if( $variant === 'workshop' && !empty($item['cena']) )
                    <span class="nlisting-card__price">
                        {{ $item['cena'] }}{{ !empty($item['cena_rodzaj']) ? ' / ' . $item['cena_rodzaj'] : '' }}
                    </span>
                @endif
                @if( $variant === 'event' && !empty($item['koszt']) )
                    <span class="nlisting-card__price">{{ $item['koszt'] }}</span>
                @endif
                <a href="{{ $item['link'] }}" class="nlisting-card__cta">{{ $cta_label }}</a>
            </div>
        @else
            <a href="{{ $item['link'] }}" class="nlisting-card__cta">{{ $cta_label }}</a>
        @endif

    </div>
</article>
