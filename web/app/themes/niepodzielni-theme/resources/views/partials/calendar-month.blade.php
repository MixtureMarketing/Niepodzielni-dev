@php
  /** @var array<string, mixed> $cal */
  $weekdays = ['Pon', 'Wt', 'Śr', 'Czw', 'Pt', 'Sob', 'Nd'];
@endphp

<section class="np-cal" data-np-calendar>
  <header class="np-cal__header">
    <div class="np-cal__nav">
      <a class="np-cal__nav-btn" href="{{ esc_url($cal['prevMonthUrl']) }}" aria-label="Poprzedni miesiąc" rel="prev">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </a>
      <h2 class="np-cal__month">{{ $cal['monthLabel'] }}</h2>
      <a class="np-cal__nav-btn" href="{{ esc_url($cal['nextMonthUrl']) }}" aria-label="Następny miesiąc" rel="next">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </a>
    </div>

    <button
      type="button"
      class="np-cal__subscribe"
      data-np-calendar-subscribe
      data-webcal-url="{{ esc_attr($cal['webcalUrl']) }}"
      aria-label="Subskrybuj kalendarz w swojej aplikacji"
    >
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M4 11a8 8 0 0 1 8 8M4 4a16 16 0 0 1 16 16M5 19a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      Subskrybuj kalendarz
    </button>
  </header>

  {{-- Desktop: grid 7 kolumn --}}
  <div class="np-cal__grid" role="grid" aria-label="Kalendarz miesięczny">
    <div class="np-cal__weekdays" role="row">
      @foreach($weekdays as $wd)
        <div class="np-cal__weekday" role="columnheader" aria-label="{{ $wd }}">{{ $wd }}</div>
      @endforeach
    </div>

    @foreach($cal['weeks'] as $week)
      <div class="np-cal__week" role="row">
        @foreach($week as $day)
          <div
            class="np-cal__day @if(! $day['isCurrent']) np-cal__day--out @endif @if($day['isToday']) np-cal__day--today @endif @if(count($day['events']) === 0) np-cal__day--empty @endif"
            role="gridcell"
            @if($day['isToday']) aria-current="date" @endif
          >
            <span class="np-cal__day-num">{{ $day['day'] }}</span>
            @if(count($day['events']) > 0)
              <ul class="np-cal__events" role="list">
                @foreach(array_slice($day['events'], 0, 3) as $ev)
                  <li class="np-cal__event np-cal__event--{{ $ev['cpt'] }}" role="listitem">
                    <a class="np-cal__event-link" href="{{ esc_url($ev['link']) }}">
                      @if($ev['time'])
                        <span class="np-cal__event-time">{{ $ev['time'] }}</span>
                      @endif
                      <span class="np-cal__event-title">{{ $ev['title'] }}</span>
                    </a>
                  </li>
                @endforeach
                @if(count($day['events']) > 3)
                  <li class="np-cal__event np-cal__event--more">
                    +{{ count($day['events']) - 3 }} {{ count($day['events']) - 3 === 1 ? 'więcej' : 'więcej' }}
                  </li>
                @endif
              </ul>
            @endif
          </div>
        @endforeach
      </div>
    @endforeach
  </div>

  {{-- Mobile: chronologiczna lista wydarzeń bieżącego miesiąca --}}
  <div class="np-cal__mobile-list" aria-label="Lista wydarzeń w bieżącym miesiącu">
    @forelse($cal['monthEvents'] as $ev)
      <a class="np-cal__mobile-item np-cal__event--{{ $ev['post_type'] ?? 'wydarzenia' }}" href="{{ esc_url($ev['link']) }}">
        <div class="np-cal__mobile-date">
          <span class="np-cal__mobile-day">{{ (int) date('j', strtotime($ev['date'])) }}</span>
          <span class="np-cal__mobile-month">{{ date_i18n('M', strtotime($ev['date'])) }}</span>
        </div>
        <div class="np-cal__mobile-info">
          <span class="np-cal__mobile-title">{{ $ev['title'] }}</span>
          @if(! empty($ev['time_start']) || ! empty($ev['time']))
            <span class="np-cal__mobile-time">{{ $ev['time_start'] ?? $ev['time'] }}</span>
          @endif
        </div>
      </a>
    @empty
      <p class="np-cal__mobile-empty">Brak wydarzeń w tym miesiącu.</p>
    @endforelse
  </div>

  <p class="np-cal__legend" role="note">
    <span class="np-cal__legend-dot np-cal__event--wydarzenia"></span> Wydarzenia
    <span class="np-cal__legend-dot np-cal__event--warsztaty"></span> Warsztaty
    <span class="np-cal__legend-dot np-cal__event--grupy-wsparcia"></span> Grupy wsparcia
  </p>
</section>
