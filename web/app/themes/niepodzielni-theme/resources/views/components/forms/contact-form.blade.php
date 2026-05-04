@props([
    'title'   => 'Napisz do nas',
    'siteKey' => null,
])

@php
    $cfSiteKey = $siteKey
        ?? (defined('NP_CF_TURNSTILE_SITE_KEY') ? NP_CF_TURNSTILE_SITE_KEY : get_option('np_cf_turnstile_site_key', ''));
@endphp

<div class="contact-form-wrapper">
    @if($title)
        <h2 class="contact-form__title">{{ $title }}</h2>
    @endif

    <form
        data-niepodzielni-form="contact"
        class="niepodzielni-form"
        novalidate
    >
        <div class="form-fields">
            <x-forms.input
                name="imie"
                label="Imię i nazwisko"
                :required="true"
                autocomplete="name"
                maxlength="100"
                placeholder="Jan Kowalski"
            />

            <x-forms.input
                name="email"
                label="Adres e-mail"
                type="email"
                :required="true"
                autocomplete="email"
                placeholder="jan@przykład.pl"
            />

            <x-forms.textarea
                name="wiadomosc"
                label="Wiadomość"
                :required="true"
                maxlength="2000"
                :rows="6"
                placeholder="Twoja wiadomość…"
            />

            <x-forms.checkbox
                name="zgoda"
                :required="true"
                label='Wyrażam zgodę na przetwarzanie moich danych osobowych zgodnie z <a href="/polityka-prywatnosci" target="_blank" rel="noopener">Polityką prywatności</a>.'
            />

            @if($cfSiteKey)
                <div class="form-field">
                    <div class="cf-turnstile" data-sitekey="{{ $cfSiteKey }}"></div>
                </div>
            @endif
        </div>

        <div class="form-general-error" hidden></div>

        <button type="submit" class="btn btn--primary">
            Wyślij wiadomość
        </button>
    </form>
</div>

@if($cfSiteKey)
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
@endif
