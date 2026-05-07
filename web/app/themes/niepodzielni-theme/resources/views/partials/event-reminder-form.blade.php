@php
  /** @var int $eventId */
  /** @var string $eventTitle */
  $turnstileSitekey = function_exists('np_get_turnstile_sitekey')
      ? np_get_turnstile_sitekey()
      : (defined('NP_CF_TURNSTILE_SITEKEY') ? NP_CF_TURNSTILE_SITEKEY : (string) get_option('np_cf_turnstile_sitekey', ''));
@endphp

<aside class="np-event-actions" aria-labelledby="np-event-actions-title-{{ $eventId }}">
  <h3 id="np-event-actions-title-{{ $eventId }}" class="np-event-actions__title">Nie zapomnij o wydarzeniu</h3>

  <div class="np-event-actions__buttons">
    <a class="np-event-actions__btn np-event-actions__btn--ical"
       href="{{ esc_url(rest_url('niepodzielni/v1/calendar/event/' . $eventId . '.ics')) }}"
       download>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="2"/>
        <path d="M3 10h18M9 3v4M15 3v4M12 13v5m-2.5-2.5L12 18l2.5-2.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      Dodaj do mojego kalendarza
    </a>
  </div>

  <form
    class="np-event-reminder"
    data-np-event-reminder
    data-event-id="{{ $eventId }}"
  >
    <p class="np-event-reminder__lead">
      Albo zapisz się na email — przypomnimy Ci dzień przed wydarzeniem.
    </p>
    <div class="np-event-reminder__row">
      <label for="np-reminder-email-{{ $eventId }}" class="screen-reader-text">Adres email</label>
      <input
        type="email"
        id="np-reminder-email-{{ $eventId }}"
        name="email"
        class="np-event-reminder__input"
        placeholder="twoj@email.pl"
        required
        autocomplete="email"
      >
      <button type="submit" class="np-event-reminder__submit">
        Przypomnij mi
      </button>
    </div>

    @if($turnstileSitekey)
      <div class="cf-turnstile" data-sitekey="{{ esc_attr($turnstileSitekey) }}" data-size="flexible"></div>
    @endif

    <p class="np-event-reminder__status" data-np-event-reminder-status role="status" aria-live="polite"></p>
  </form>
</aside>
