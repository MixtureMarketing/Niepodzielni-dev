@props([
    'title'   => 'Napisz do nas',
    'intro'   => null,
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

    @if($intro)
        <p class="contact-form__intro">{{ $intro }}</p>
    @endif

    <form
        data-niepodzielni-form="contact"
        class="niepodzielni-form"
        novalidate
    >
        <div class="form-fields">
            <div class="form-row">
                <x-forms.input
                    name="imie"
                    label="Imię"
                    :required="true"
                    autocomplete="given-name"
                    maxlength="50"
                    placeholder="Jan"
                />

                <x-forms.input
                    name="nazwisko"
                    label="Nazwisko"
                    :required="false"
                    autocomplete="family-name"
                    maxlength="50"
                    placeholder="Kowalski"
                />
            </div>

            <div class="form-row">
                <x-forms.input
                    name="telefon"
                    label="Numer telefonu"
                    type="tel"
                    :required="false"
                    autocomplete="tel"
                    maxlength="20"
                    placeholder="+48 123 456 789"
                />

                <x-forms.input
                    name="email"
                    label="Adres e-mail"
                    type="email"
                    :required="true"
                    autocomplete="email"
                    placeholder="jan@przykład.pl"
                />
            </div>

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
