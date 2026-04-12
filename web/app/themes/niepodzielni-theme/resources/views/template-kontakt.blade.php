{{-- Template Name: Kontakt --}}

@extends('layouts.app')

@section('content')
<div class="kontakt-page">

    {{-- INFO + ILUSTRACJA --}}
    <section class="psy-section kontakt-info">
        <div class="psy-container">
            <div class="kontakt-info__grid">
                <div class="kontakt-info__card">
                    <h1 class="kontakt-info__title">Kontakt</h1>
                    <ul class="kontakt-info__list">
                        <li>
                            <svg class="kontakt-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                            <span><strong>Główna siedziba:</strong> ul. Zeylanda 9/3, 60-808 Poznań</span>
                        </li>
                        <li>
                            <svg class="kontakt-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                            <span><strong>Punkt:</strong> ul. Środkowa 30, Praga, 03-431 Warszawa</span>
                        </li>
                        <li>
                            <svg class="kontakt-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.01-.24 11.47 11.47 0 0 0 3.59.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11.47 11.47 0 0 0 .57 3.59 1 1 0 0 1-.25 1.01l-2.2 2.2z"/></svg>
                            <span><strong>Numer kontaktowy:</strong> 668 277 176</span>
                        </li>
                    </ul>
                    <p class="kontakt-info__legal">KRS: 0000973514<br>NIP: 7812036026 &nbsp; REGON: 522108288<br>Numer konta: 80 1140 2004 0000 3202 8244 8469</p>
                </div>
                <div class="kontakt-info__illustration">
                    <img src="https://niepodzielni.com/wp-content/uploads/2025/06/Group-83.png" alt="Kontakt z Fundacją Niepodzielni" loading="eager">
                </div>
            </div>
        </div>
    </section>

    {{-- EMAIL KATEGORIE --}}
    <section class="psy-section psy-section--beige kontakt-emails">
        <div class="psy-container">
            <h2 class="section-title">Skontaktuj się z nami</h2>
            <p class="kontakt-emails__intro">Aby zapewnić Ci jak najlepszą pomoc, prosimy o skorzystanie z odpowiednich adresów mailowych w zależności od Twojego zapytania.</p>
            <div class="kontakt-emails__grid">
                <div class="kontakt-email-card">
                    <h3 class="kontakt-email-card__title">Ogólne zapytania</h3>
                    <a href="mailto:kontakt@niepodzielni.com" class="kontakt-email-card__address">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        kontakt@niepodzielni.com
                    </a>
                    <p>Pod tym adresem możesz skontaktować się z nami w sprawach ogólnych, jeśli nie wiesz, do którego działu skierować swoje pytanie. Adres ten służy także do kierowania wszelkich pytań związanych z działalnością Fundacji Niepodzielni, możliwością współpracy oraz spraw wymagających przekazania do odpowiedniego działu.</p>
                </div>
                <div class="kontakt-email-card">
                    <h3 class="kontakt-email-card__title">Współprace i projekty</h3>
                    <a href="mailto:dzialamy@niepodzielni.com" class="kontakt-email-card__address">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        dzialamy@niepodzielni.com
                    </a>
                    <p>Chcesz nawiązać z nami współpracę, zaproponować projekt, dowiedzieć się więcej o naszych działaniach, lub jesteś zainteresowany wsparciem jako sponsor? Napisz do nas na ten adres.</p>
                </div>
                <div class="kontakt-email-card">
                    <h3 class="kontakt-email-card__title">Dołącz do nas</h3>
                    <a href="mailto:hr@niepodzielni.com" class="kontakt-email-card__address">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        hr@niepodzielni.com
                    </a>
                    <p>Jeśli jesteś psychologiem, psychoterapeutą, studentem, lub interesuje Cię wolontariat i inne formy zaangażowania, skontaktuj się z nami tutaj. Jesteśmy otwarci na współpracę i chętnie powitamy Cię w naszym zespole.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- FORMULARZ --}}
    <section class="psy-section kontakt-form-section">
        <div class="psy-container">
            <h2 class="section-title section-title--green">Napisz do nas</h2>
            <p class="kontakt-form-section__intro">Czekamy na Twoje wiadomości i jesteśmy tu, aby wspierać Cię w każdej sytuacji. Dziękujemy za zainteresowanie naszą fundacją!</p>

            @if(isset($_GET['kontakt']) && $_GET['kontakt'] === 'ok')
                <div class="kontakt-form-notice kontakt-form-notice--success">Dziękujemy za wiadomość! Odpiszemy najszybciej jak to możliwe.</div>
            @elseif(isset($_GET['kontakt']) && $_GET['kontakt'] === 'blad')
                <div class="kontakt-form-notice kontakt-form-notice--error">Wystąpił błąd. Sprawdź pola i spróbuj ponownie.</div>
            @endif

            <form class="kontakt-form" method="POST" action="{{ esc_url(admin_url('admin-post.php')) }}">
                <input type="hidden" name="action" value="np_contact_form">
                @php wp_nonce_field('np_contact_form', 'np_contact_nonce'); @endphp

                <div class="kontakt-form__row">
                    <div class="kontakt-form__field">
                        <input type="text" name="imie" placeholder="Imię" required>
                    </div>
                    <div class="kontakt-form__field">
                        <input type="text" name="nazwisko" placeholder="Nazwisko">
                    </div>
                </div>
                <div class="kontakt-form__row">
                    <div class="kontakt-form__field">
                        <input type="tel" name="telefon" placeholder="Numer telefonu">
                    </div>
                    <div class="kontakt-form__field">
                        <input type="email" name="email" placeholder="Adres e-mail" required>
                    </div>
                </div>
                <div class="kontakt-form__field kontakt-form__field--full">
                    <textarea name="wiadomosc" placeholder="Twoja wiadomość" rows="5" required></textarea>
                </div>
                <div class="kontakt-form__field kontakt-form__field--full kontakt-form__checkbox">
                    <label>
                        <input type="checkbox" required>
                        Akceptuję <a href="/polityka-prywatnosci/">Politykę Prywatności</a>
                    </label>
                </div>
                <button type="submit" class="psy-btn psy-btn-green">WYŚLIJ</button>
            </form>
        </div>
    </section>

</div>
@endsection
