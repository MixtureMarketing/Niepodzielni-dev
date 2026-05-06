@props([
    'title'   => 'Napisz do nas',
    'intro'   => null,
    'siteKey' => null,
])

@php
    $cfSiteKey = $siteKey
        ?? (defined('NP_CF_TURNSTILE_SITE_KEY') ? NP_CF_TURNSTILE_SITE_KEY : get_option('np_cf_turnstile_site_key', ''));
    
    $prefixes = \Niepodzielni\Forms\Helpers\PhonePrefixes::getAll();
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
                    data-mask="no-digits"
                    data-error-no-digits="Imię nie może zawierać cyfr."
                />

                <x-forms.input
                    name="nazwisko"
                    label="Nazwisko"
                    :required="true"
                    autocomplete="family-name"
                    maxlength="50"
                    placeholder="Kowalski"
                    data-mask="no-digits"
                    data-error-no-digits="Nazwisko nie może zawierać cyfr."
                />
            </div>

            <div class="form-row">
                <x-forms.input
                    name="kod_pocztowy"
                    label="Kod pocztowy"
                    :required="true"
                    placeholder="00-000"
                    data-mask="00-000"
                    pattern="^[0-9]{2}-[0-9]{3}$"
                    error-pattern="Podaj kod w formacie 00-000."
                />

                <x-forms.input
                    name="miasto"
                    label="Miasto"
                    :required="true"
                    autocomplete="address-level2"
                    maxlength="100"
                    placeholder="Poznań"
                />
            </div>

            <x-forms.input
                name="ulica"
                label="Ulica i numer"
                :required="true"
                autocomplete="address-line1"
                maxlength="150"
                placeholder="ul. Zeylanda 9/3"
            />

            <div class="form-row">
                <x-forms.phone
                    name="telefon"
                    prefix-name="telefon_prefix"
                    label="Numer telefonu"
                    :required="true"
                    :prefixes="$prefixes"
                    value-prefix="+48"
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

            <x-forms.select
                name="temat"
                label="Temat wiadomości"
                :required="true"
                :options="['ogolne' => 'Ogólne zapytania', 'wspolpraca' => 'Współpraca', 'pomoc' => 'Pomoc psychologiczna']"
            />

            <x-forms.radio
                name="preferowany_kontakt"
                label="Preferowany sposób kontaktu"
                :required="true"
                :options="['email' => 'E-mail', 'telefon' => 'Telefon']"
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
