{{--
  Consent Mode v2 — banner CMP.
  - Renderowany serwerowo, ukryty (hidden) dopóki JS nie zdecyduje czy go pokazać.
  - JS: resources/js/consent-banner.js (entry vite, ładowany w layouts/app.blade.php).
  - Crisis Hub: ten template w ogóle nie tracukje, JS sam pomija banner gdy
    `[data-np-crisis-page]` jest obecne.
--}}
<div
    class="np-consent"
    data-np-consent-banner
    role="dialog"
    aria-modal="false"
    aria-labelledby="np-consent-title"
    aria-describedby="np-consent-desc"
    aria-hidden="true"
    hidden
>
    <div class="np-consent__inner">
        <h2 id="np-consent-title" class="np-consent__title">Twoja prywatność</h2>
        <p id="np-consent-desc" class="np-consent__desc">
            Używamy ciasteczek i podobnych technologii do analityki ruchu oraz mierzenia skuteczności kampanii.
            Domyślnie wszystkie zgody są wyłączone — wybierz, na co się zgadzasz. Niezbędne pliki cookie
            (logowanie, sesja, zabezpieczenia) działają zawsze. Szczegóły w
            <a href="/polityka-prywatnosci/">polityce prywatności</a>.
            <button
                type="button"
                class="np-consent__manage-trigger"
                data-consent-show-manage
                aria-controls="np-consent-manage"
                aria-expanded="false"
            >Zarządzaj zgodami</button>
        </p>

        <div class="np-consent__actions">
            <button
                type="button"
                class="np-consent__btn np-consent__btn--primary"
                data-consent-accept-all
            >
                Akceptuję wszystkie
            </button>
            <button
                type="button"
                class="np-consent__btn np-consent__btn--secondary"
                data-consent-reject-all
            >
                Tylko niezbędne
            </button>
        </div>

        <div id="np-consent-manage" class="np-consent__manage" data-consent-manage hidden>
            <fieldset class="np-consent__group">
                <legend class="np-consent__legend">Wybierz cele</legend>

                <label class="np-consent__option">
                    <input type="checkbox" data-consent="analytics">
                    <span>
                        <strong>Analityka</strong>
                        <span class="np-consent__hint">Anonimowe statystyki ruchu (GA4) — pomagają nam ulepszać stronę.</span>
                    </span>
                </label>

                <label class="np-consent__option">
                    <input type="checkbox" data-consent="ads">
                    <span>
                        <strong>Reklamy</strong>
                        <span class="np-consent__hint">Pomiar skuteczności kampanii (Meta Pixel, Google Ads).</span>
                    </span>
                </label>

                <label class="np-consent__option">
                    <input type="checkbox" data-consent="ad_user_data">
                    <span>
                        <strong>Dane użytkownika dla reklam</strong>
                        <span class="np-consent__hint">Udostępnianie zhashowanych danych (e-mail, urządzenie) reklamodawcom.</span>
                    </span>
                </label>

                <label class="np-consent__option">
                    <input type="checkbox" data-consent="ad_personalization">
                    <span>
                        <strong>Personalizacja reklam</strong>
                        <span class="np-consent__hint">Reklamy dopasowane do Twoich zainteresowań.</span>
                    </span>
                </label>
            </fieldset>

            <div class="np-consent__manage-actions">
                <button
                    type="button"
                    class="np-consent__btn np-consent__btn--primary"
                    data-consent-save
                >
                    Zapisz wybór
                </button>
            </div>
        </div>
    </div>
</div>
