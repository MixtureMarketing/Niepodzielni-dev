{{-- Template Name: Asystenci Zdrowienia --}}

@extends('layouts.app')

@section('content')
<div class="asystent-page">

    {{-- HERO --}}
    <section class="asystent-hero">
        <div class="psy-container">
            <div class="asystent-hero__inner">
                <div class="asystent-hero__content">
                    <h1 class="asystent-hero__title">ASYSTENT<br>ZDROWIENIA</h1>
                    <p><strong>Wsparcie od tych, którzy wiedzą, jak wyjść z kryzysu.</strong></p>
                    <p>Wyobraź sobie, że w trudnym momencie życia towarzyszy Ci ktoś, kto naprawdę rozumie Twoje emocje i wyzwania. Nie dlatego, że czytał o nich w książkach, ale dlatego, że sam przez nie przeszedł. Asystenci Zdrowienia to osoby, które kiedyś same zmagały się z problemami psychicznymi lub kryzysem, ale przeszły przez proces zdrowienia i wyszły na prostą. Teraz dzielą się swoją historią oraz doświadczeniami, pokazując, że każdy ma szansę na lepsze jutro. Nie oceniają, nie pouczają – są jak starszy kumpel, który przeszedł przez ten sam trudny etap życia i wie, jak Cię wesprzeć.</p>
                    <a href="#slider" class="psy-btn psy-btn-green">ZNAJDŹ ASYSTENTA</a>
                </div>
                <div class="asystent-hero__image">
                    <img src="https://niepodzielni.com/wp-content/uploads/2025/07/postacie-ze-sloncem-1.png" alt="Asystenci Zdrowienia" width="460" loading="eager">
                </div>
            </div>
        </div>
    </section>

    {{-- WARTOŚĆ + CENA + KIEDY WARTO --}}
    <section class="psy-section asystent-value">
        <div class="psy-container">
            <div class="asystent-value__grid">
                <div class="asystent-value__left">
                    <p class="asystent-value__quote">Asystenci Zdrowienia towarzyszą Ci w drodze do zdrowia, inspirując i motywując, byś mógł odnaleźć siłę do działania.</p>
                    <div class="asystent-price-box">
                        <div class="asystent-price-box__amount">37<span>zł</span></div>
                        <div class="asystent-price-box__info">
                            <p>Cena konsultacji z asystentem</p>
                            <p>Każdy trwa <strong>50 min</strong> — spotkania odbywają się <strong>online</strong></p>
                        </div>
                    </div>
                </div>
                <div class="asystent-value__right">
                    <h2 class="section-title">Kiedy warto skorzystać ze wsparcia asystenta zdrowienia?</h2>
                    <ul class="checklist">
                        <li>Masz kryzys i nie wiesz od czego zacząć, żeby zrobić pierwszy krok w kierunku leczenia</li>
                        <li>Wyjście z domu lub proste codzienne czynności są dla Ciebie wyzwaniem</li>
                        <li>Napady lęku i paniki utrudniają Ci normalne funkcjonowanie</li>
                        <li>Jesteś w terapii, leczeniu farmakologicznym ale nie jesteś do tego przekonany, masz chwile zwątpienia</li>
                        <li>Wydaje Ci się, że zdrowienie jest niemożliwe, a kryzys będzie trwał wiecznie</li>
                        <li>Obawiasz się nawrotu choroby lub uzależnienia</li>
                        <li>Czujesz, że życie nie ma sensu, brak Ci motywacji</li>
                        <li>Właśnie wyszedłeś/aś ze szpitala lub zakończyłeś/aś leczenie i nie wiesz, jak wrócić do codzienności</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- SPECJALIŚCI --}}
    <section class="psy-section psy-section--white asystent-specialists" id="slider">
        <div class="psy-container">
            <h2 class="section-title section-title--center">Umów się do naszych asystentów zdrowienia</h2>
            {!! do_shortcode('[specjalisci_slider specjalizacja="asystent-zdrowienia,asystenci-zdrowienia" limit="8"]') !!}
        </div>
    </section>

    {{-- FAQ --}}
    <section class="psy-section asystent-faq">
        <div class="psy-container">
            <h2 class="section-title">FAQ: Asystenci Zdrowienia</h2>
            <div class="faq">

                <div class="faq__item">
                    <div class="faq__question m_acordeon_head">Czym zajmuje się asystent zdrowienia?</div>
                    <div class="faq__answer linkmenu">
                        <p>Asystent zdrowienia pełni rolę pomostu między pacjentem a personelem medycznym, ukazuje depresję lub inne zaburzenie psychiczne z perspektywy pacjenta. Do jego głównych zadań należą:</p>
                        <ul>
                            <li>Uczestnictwo w spotkaniach z lekarzami, psychoterapeutami i psychologami oraz realizacja zadań zleconych przez personel medyczno-terapeutyczny.</li>
                            <li>Współprowadzenie zajęć warsztatowych i edukacyjnych.</li>
                            <li>Towarzyszenie pacjentom w wyjściach indywidualnych lub grupowych.</li>
                            <li>Prowadzenie indywidualnych rozmów wspierających z pacjentami.</li>
                            <li>Motywowanie pacjentów do aktywności i zaangażowania w proces leczenia.</li>
                            <li>Dzielenie się spostrzeżeniami na temat postępów pacjentów z zespołem terapeutycznym.</li>
                            <li>Wspieranie rodziny osoby w kryzysie psychicznym.</li>
                        </ul>
                    </div>
                </div>

                <div class="faq__item">
                    <div class="faq__question m_acordeon_head">Kim jest asystent zdrowienia?</div>
                    <div class="faq__answer linkmenu">
                        <p>Asystent zdrowienia to osoba, która sama doświadczyła kryzysu psychicznego, przeszła proces terapii i zdrowienia, a następnie ukończyła specjalistyczne szkolenie. Dzięki własnym doświadczeniom wspiera innych w ich drodze do zdrowia psychicznego, inspiruje i dzieli się wiedzą, oferując wsparcie emocjonalne.</p>
                    </div>
                </div>

                <div class="faq__item">
                    <div class="faq__question m_acordeon_head">Jakie są wymagania, aby zostać asystentem zdrowienia?</div>
                    <div class="faq__answer linkmenu">
                        <p>Aby zostać asystentem zdrowienia, należy spełnić następujące kryteria:</p>
                        <ul>
                            <li>Doświadczenie własnego kryzysu psychicznego i przepracowanie go podczas terapii.</li>
                            <li>Ukończenie półrocznego (rocznego) specjalistycznego kursu dla asystentów zdrowienia.</li>
                            <li>Odbycie kilkumiesięcznego stażu w placówkach zajmujących się zdrowiem psychicznym, takich jak szpitale psychiatryczne, centra zdrowia psychicznego czy fundacje.</li>
                            <li>Umiejętność mówienia o swoich doświadczeniach związanych z chorobą i procesem zdrowienia w sposób wspierający dla innych.</li>
                        </ul>
                    </div>
                </div>

                <div class="faq__item">
                    <div class="faq__question m_acordeon_head">Jakie są korzyści z pracy z asystentem zdrowienia?</div>
                    <div class="faq__answer linkmenu">
                        <ul>
                            <li><strong>Lepsze zrozumienie procesu zdrowienia</strong> – asystent może wyjaśnić pacjentowi różne aspekty leczenia, dzieląc się własnymi doświadczeniami.</li>
                            <li><strong>Zwiększenie poczucia bezpieczeństwa</strong> – pacjenci często czują się bardziej komfortowo, rozmawiając z osobą, która przeszła podobne trudności.</li>
                            <li><strong>Wzmacnianie samodzielności</strong> – asystent pomaga budować pewność siebie i wspiera w stopniowym powrocie do aktywnego życia.</li>
                            <li><strong>Łagodzenie stresu i lęku</strong> – rozmowy z asystentem mogą pomóc pacjentom lepiej radzić sobie z trudnymi emocjami.</li>
                            <li><strong>Wsparcie w kontaktach ze specjalistami</strong> – asystenci zdrowienia mogą towarzyszyć pacjentom podczas wizyt u lekarzy czy terapeutów.</li>
                        </ul>
                    </div>
                </div>

                <div class="faq__item">
                    <div class="faq__question m_acordeon_head">Rola Asystenta Zdrowienia</div>
                    <div class="faq__answer linkmenu">
                        <ul>
                            <li><strong>Poznać</strong> – przedstawić i omówić z pozostałym personelem perspektywę osoby chorującej. Zauważyć człowieka, a nie tylko jednostkę chorobową.</li>
                            <li><strong>Zrozumieć</strong> – aby skutecznie pomagać (nie wyręczać). Stworzyć warunki do zdrowienia.</li>
                            <li><strong>Wesprzeć</strong> – wykorzystać potencjał i wzmocnić go, również w sprawach praw, podejmowania decyzji, samostanowieniu. Pomoc w samodzielności, pełnieniu ról społecznych i zawodowych.</li>
                            <li><strong>Dać siłę i nadzieję</strong> – własnym przykładem pokazać, że „można".</li>
                        </ul>
                    </div>
                </div>

            </div>
        </div>
    </section>

</div>
@endsection
