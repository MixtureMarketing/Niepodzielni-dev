{{-- Template Name: Wesprzyj nas --}}

@extends('layouts.app')

@section('content')
<div class="wesprzyj-page">

    {{-- HERO --}}
    <section class="wesprzyj-hero">
        <div class="wesprzyj-hero__overlay">
            <div class="psy-container">
                <nav class="page-nav page-nav--on-dark">
                    <a href="#sponsorzy" class="page-nav__btn">SPONSORZY</a>
                    <a href="#darczyncy" class="page-nav__btn">DARCZYŃCY</a>
                    <a href="https://buycoffee.to/fundacjaniepodzielni" class="page-nav__btn" target="_blank" rel="noopener">WESPRZYJ NAS</a>
                </nav>
                <div class="wesprzyj-hero__content">
                    <h1 class="wesprzyj-hero__quote">&bdquo;Dobro to jedyna rzecz, która się mnoży,<br>gdy się nią dzielisz.&rdquo;<br><span class="wesprzyj-hero__author">– Albert Schweitzer</span></h1>
                    <p class="wesprzyj-hero__sub">Razem możemy więcej – dołącz do grona naszych Sponsorów i Darczyńców!</p>
                    <a href="https://buycoffee.to/fundacjaniepodzielni" class="psy-btn psy-btn-green" target="_blank" rel="noopener">WESPRZYJ NAS</a>
                </div>
            </div>
        </div>
    </section>

    {{-- DLACZEGO WARTO --}}
    <section class="psy-section wesprzyj-why">
        <div class="psy-container">
            <h2 class="section-title">Dlaczego warto nas wspierać?</h2>
            <p class="wesprzyj-why__sub">Twoje wsparcie ma realny wpływ – dołącz do nas!</p>
            <div class="wesprzyj-cards">
                <div class="wesprzyj-card">
                    <h3>Dostępność pomocy</h3>
                    <p>Oferujemy niskopłatne konsultacje, aby każdy mógł skorzystać z profesjonalnego wsparcia.</p>
                </div>
                <div class="wesprzyj-card">
                    <h3>Wsparcie w kryzysie</h3>
                    <p>Prowadzimy grupy wsparcia, zapewniając bezpieczną przestrzeń do rozmowy.</p>
                </div>
                <div class="wesprzyj-card">
                    <h3>Edukacja i świadomość</h3>
                    <p>Zmniejszamy stygmatyzację problemów psychicznych poprzez psychoedukację.</p>
                </div>
                <div class="wesprzyj-card">
                    <h3>Rozwój inicjatyw</h3>
                    <p>Tworzymy nowe projekty odpowiadające na potrzeby społeczności.</p>
                </div>
                <div class="wesprzyj-card">
                    <h3>Silniejsza społeczność</h3>
                    <p>Wspólnie budujemy bardziej empatyczne i otwarte społeczeństwo.</p>
                </div>
                <div class="wesprzyj-card">
                    <h3>Przełamywanie barier</h3>
                    <p>Działamy na rzecz równego dostępu do usług psychologicznych dla wszystkich.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- BUYCOFFEE --}}
    <section class="psy-section psy-section--white wesprzyj-coffee">
        <div class="psy-container">
            <div class="wesprzyj-coffee__inner">
                <div class="wesprzyj-coffee__box">
                    <h2 class="wesprzyj-coffee__title">Wspieraj nas!</h2>
                    <p>Miło czasem umówić się na kawę z przyjacielem/przyjaciółką. Pogadać o życiu, a może się zwierzyć?</p>
                    <p>Mała czarna, espresso a może latte? 5, 10, 15 zł</p>
                    <p>Zaproś nas czasem – z tych drobnych uzbiera się wiele dobrego dla innych!</p>
                    <a href="https://buycoffee.to/fundacjaniepodzielni" class="psy-btn psy-btn-green" target="_blank" rel="noopener">BUYCOFFEE</a>
                </div>
                <div class="wesprzyj-coffee__image" aria-hidden="true">
                    <img src="https://niepodzielni.com/wp-content/uploads/2025/08/Warstwa_1.png" alt="" loading="lazy">
                </div>
            </div>
        </div>
    </section>

    {{-- PARTNERZY --}}
    <section class="psy-section wesprzyj-partners" id="sponsorzy">
        <div class="psy-container">
            <h2 class="section-title section-title--center">Nasi partnerzy</h2>
            <div class="wesprzyj-partners__list">

                <div class="wesprzyj-partner">
                    <a href="https://thaibalispa.pl/" target="_blank" rel="noopener" class="wesprzyj-partner__logo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/04/ThaiBaliSpa_Logo100px.svg" alt="Thai Bali Spa" loading="lazy">
                    </a>
                    <p>Thai Bali Spa to coś więcej niż masaż orientalny – to bezpieczna przystań dla ciała i umysłu – fundator darmowych voucherów dla osób które wzięły udział w naszym konkursie.</p>
                </div>

                <div class="wesprzyj-partner">
                    <a href="https://sensus.pl/BAgMEAE" target="_blank" rel="noopener" class="wesprzyj-partner__logo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/04/sensus-logo-ikona.svg" alt="Wydawnictwo Sensus" loading="lazy">
                    </a>
                    <p>Wydawnictwo Sensus oferuje poradniki popularno-naukowe poruszające kwestie sfery emocjonalnej, rozwoju osobowości, samoświadomości i motywacji – fundator nagród w naszym konkursie.</p>
                </div>

                <div class="wesprzyj-partner">
                    <a href="https://signius.pl/" target="_blank" rel="noopener" class="wesprzyj-partner__logo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/04/Signius_logo_-_primary.svg" alt="Signius" loading="lazy">
                    </a>
                    <p>Signius – naszą misją jest tworzenie tożsamości cyfrowej. Dzięki ich wsparciu możemy zdalnie współpracować z psychologami z całej Polski!</p>
                </div>

                <div class="wesprzyj-partner">
                    <a href="https://psychostart.pl/" target="_blank" rel="noopener" class="wesprzyj-partner__logo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/04/logo-psychostart-e1731405217594-1.svg" alt="PsychStart" loading="lazy">
                    </a>
                    <p>PsychStart – Przestrzeń dla młodych psychologów, oferująca praktyczne szkolenia i wsparcie merytoryczne na początku kariery – dziękujemy za wsparcie nas!</p>
                </div>

                <div class="wesprzyj-partner">
                    <a href="https://charaktery.eu/" target="_blank" rel="noopener" class="wesprzyj-partner__logo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/04/charaktery.svg" alt="Charaktery" loading="lazy">
                    </a>
                    <p>Charaktery – Wiodący magazyn psychologiczny w Polsce, dostarczający rzetelną wiedzę i inspiracje dla specjalistów oraz osób zainteresowanych psychologią – dziękujemy za wsparcie nas!</p>
                </div>

                <div class="wesprzyj-partner">
                    <a href="https://kanalstudencki.pl/" target="_blank" rel="noopener" class="wesprzyj-partner__logo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/04/Kanal-studencki-logo-kolor-e1726870408355.svg" alt="Kanał Studencki" loading="lazy">
                    </a>
                    <p>Kanał Studencki – Twórcy wartościowych treści edukacyjnych, wspierający rozwój i świadomość młodych profesjonalistów – dziękujemy za wsparcie nas!</p>
                </div>

                <div class="wesprzyj-partner">
                    <a href="https://wydawnictwofeeria.pl/" target="_blank" rel="noopener" class="wesprzyj-partner__logo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/04/images.svg" alt="Wydawnictwo Feeria" loading="lazy">
                    </a>
                    <p>Wydawnictwo Feeria – Publikacje pełne psychologicznej wiedzy i poradników, które pomagają lepiej zrozumieć siebie i innych – dziękujemy za wsparcie nas!</p>
                </div>

                <div class="wesprzyj-partner">
                    <a href="https://lamiasofa.pl/" target="_blank" rel="noopener" class="wesprzyj-partner__logo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/10/567049435_811858624886289_1734200927654930908_n.jpg" alt="Lamia Sofa" loading="lazy">
                    </a>
                    <p>Lamia Sofa – Producent stylowych i wygodnych mebli. Dzięki Wam nasza fundacyjna przestrzeń stała się jeszcze bardziej przytulna.</p>
                </div>

            </div>
        </div>
    </section>

    {{-- DARCZYŃCY --}}
    <section class="psy-section psy-section--green wesprzyj-donors" id="darczyncy">
        <div class="psy-container">
            <h2 class="section-title section-title--white">Dziękujemy za wsparcie</h2>
            <p class="wesprzyj-donors__sub">Jesteśmy wdzięczni wszystkim Darczyńcom, którzy wspierają nasze działania. Dzięki Wam możemy konsekwentnie realizować projekty Fundacji Niepodzielni.</p>
            <p class="wesprzyj-donors__new">Z radością informujemy, że do grona wspierających dołącza kolejny Darczyńca:</p>
            <div class="wesprzyj-donors__cards">
                <div class="wesprzyj-donor-card">
                    <div class="wesprzyj-donor-card__logo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/04/Ellipse-1.png" alt="I-Kancelaria" loading="lazy">
                    </div>
                    <div class="wesprzyj-donor-card__body">
                        <h3>I-Kancelaria</h3>
                        <a href="https://www.i-kancelaria.pl/" target="_blank" rel="noopener" class="wesprzyj-donor-card__link">
                            Dowiedz się więcej
                            <img src="https://niepodzielni.com/wp-content/uploads/2025/04/Frame-35.svg" alt="" aria-hidden="true">
                        </a>
                    </div>
                </div>
                <div class="wesprzyj-donor-card wesprzyj-donor-card--cta">
                    <div class="wesprzyj-donor-card__body">
                        <h3>Wesprzyj nas</h3>
                        <a href="https://buycoffee.to/fundacjaniepodzielni" target="_blank" rel="noopener" class="wesprzyj-donor-card__link">
                            Zostań darczyńcą
                            <img src="https://niepodzielni.com/wp-content/uploads/2025/04/Frame-35.svg" alt="" aria-hidden="true">
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- PODZIĘKOWANIE --}}
    <section class="psy-section psy-section--white wesprzyj-thanks">
        <div class="psy-container">
            <div class="wesprzyj-thanks__box">
                <p>Dziękujemy za wsparcie, które pozwala nam kontynuować naszą misję i pomagać tym, którzy tego najbardziej potrzebują!</p>
            </div>
        </div>
    </section>

</div>
@endsection
