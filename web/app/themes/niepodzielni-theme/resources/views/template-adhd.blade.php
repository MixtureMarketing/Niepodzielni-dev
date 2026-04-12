{{-- Template Name: ADHD u dorosłych --}}

@extends('layouts.app')

@section('content')
{!! do_shortcode('[faq_adhd_schema]') !!}
<div class="adhd-page">

    {{-- HERO --}}
    <section class="adhd-hero">
        <div class="psy-container">
            <div class="adhd-hero__inner">
                <div class="adhd-hero__content">
                    <h1 class="adhd-hero__title">Diagnoza ADHD<br>u osób dorosłych</h1>
                    <p>Masz trudności z koncentracją? Zaczynasz zadania, ale trudno Ci je dokończyć? Czujesz wewnętrzny niepokój i potrzebę ciągłego ruchu?</p>
                    <p>Codzienne obowiązki Cię przerastają, choć naprawdę się starasz?</p>
                    <p>Jeśli czujesz, że to może opisywać Ciebie, dowiedz się więcej o tym, jak wygląda ADHD u dorosłych i umów się na kompleksową diagnozę.</p>
                    <a href="#slider" class="psy-btn psy-btn-green">UMÓW SIĘ NA DIAGNOZĘ ADHD</a>
                </div>
                <div class="adhd-hero__image">
                    <img src="https://niepodzielni.com/wp-content/uploads/2025/07/adhd-2.png" alt="Diagnoza ADHD u dorosłych" width="480" loading="eager">
                </div>
            </div>
        </div>
    </section>

    {{-- CZYM JEST ADHD + OBJAWY --}}
    <section class="psy-section psy-section--white adhd-about">
        <div class="psy-container">
            <div class="adhd-about__grid">
                <div class="adhd-about__left">
                    <h2>Czym jest ADHD?</h2>
                    <p>ADHD (zespół nadpobudliwości psychoruchowej z deficytem uwagi) to zaburzenie neurorozwojowe, które objawia się trudnościami z koncentracją, impulsywnością i nadmierną ruchliwością. Choć przez lata uważano je za problem dzieci, dziś wiemy, że może utrzymywać się przez całe życie i poważnie wpływać na codzienne funkcjonowanie dorosłych w różnych obszarach takich jak praca, relacje i życie osobiste. Diagnoza ADHD w dorosłym wieku to szansa na zrozumienie siebie, swoich trudności i rozpoczęcie skutecznego leczenia.</p>
                </div>
                <div class="adhd-about__right">
                    <h2>Najczęstsze objawy ADHD u dorosłych</h2>
                    <ul class="checklist">
                        <li>Trudności z koncentracją i utrzymaniem uwagi</li>
                        <li>Impulsywność (np. zbyt szybka jazda samochodem)</li>
                        <li>Problemy z wykonywaniem czynności we właściwym porządku</li>
                        <li>Trudności z organizacją zadań (częste spóźnianie się)</li>
                        <li>Łatwe rozpraszanie się</li>
                        <li>Niedotrzymywanie obietnic i zobowiązań</li>
                        <li>Trudność ze spokojnym wykonywaniem zadań, przerywaniem czynności</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- DLACZEGO WARTO + PROCES DIAGNOZY --}}
    <section class="psy-section psy-section--white adhd-why">
        <div class="psy-container">
            <h2 class="section-title">Dlaczego warto się zdiagnozować?</h2>
            <p class="adhd-why__intro">Badania pokazują, że u większości osób zdiagnozowanych w dzieciństwie ADHD utrzymuje się przez całe życie. Jednak wielu dorosłych nie wie, czy to zaburzenie jest przyczyną ich problemów. Objawy u dorosłych mogą być inne niż u dzieci i mniej widoczne. Niektóre cechy ADHD występują u każdego, np. impulsywność, ale nie zawsze oznacza to chorobę. Gdy jednak zaburzenie jest obecne, utrudnia codzienne funkcjonowanie. Dlatego ważna jest diagnoza u specjalisty, który zna odpowiednie kryteria choroby.</p>
            <p class="adhd-why__intro">Każda opłacona konsultacja to nie tylko krok ku własnemu dobrostanowi – to również realne wsparcie dla kogoś, kto w danym momencie nie może sobie na to pozwolić. Wspólnie tworzymy krąg pomocy.</p>

            <h2 class="section-title" style="margin-top: 60px;">Jak wygląda proces diagnozy ADHD w Fundacji Niepodzielni?</h2>
            <div class="adhd-process__layout">
                <div class="adhd-process__steps">
                    <div class="adhd-step">
                        <h3>Pierwsze spotkanie</h3>
                        <p>Wstępny wywiad z psycholożką, który pozwala przyjrzeć się Twoim objawom. Wypełnisz też przesiewowy kwestionariusz ASRS v1.1.</p>
                    </div>
                    <div class="adhd-step">
                        <h3>Drugie i trzecie spotkanie</h3>
                        <p>Szczegółowy wywiad kliniczny oparty na narzędziu DIVA-5, który jest międzynarodowym standardem w diagnozie ADHD u dorosłych. W rozmowie omawiane są różne aspekty Twojego funkcjonowania, co pozwala na dokładne zrozumienie Twoich trudności.</p>
                    </div>
                    <div class="adhd-step">
                        <h3>Czwarte spotkanie</h3>
                        <p>Rozmowa z bliską osobą (np. partnerem, rodzicem, przyjacielem), która może dostarczyć dodatkowej perspektywy.</p>
                    </div>
                </div>
                <div class="adhd-price-card">
                    <p class="adhd-price-card__label">Cena kompleksowej diagnozy</p>
                    <div class="adhd-price-card__amount">750 <span>zł</span></div>
                    <div class="adhd-price-card__klarna">
                        Możliwość płatności ratalnej za pomocą <strong>Klarna</strong>
                    </div>
                    <p class="adhd-price-card__info">Każdy trwa <strong>50 min</strong> — spotkania odbywają się <strong>online</strong></p>
                </div>
            </div>
        </div>
    </section>

    {{-- SEKCJA CIEMNA: DIAGNOSTYKA + OPINIA --}}
    <section class="adhd-dark">
        <div class="psy-container">
            <div class="adhd-dark__grid">
                <div>
                    <h2 class="adhd-dark__h2">Diagnostyka zaburzeń osobowości</h2>
                    <p>ADHD często współistnieje z innymi zaburzeniami. Dlatego psycholog może podjąć decyzję o dodatkowych spotkaniach i testach. Są one konieczne do postawienia pełnej diagnozy i powodzenia przyszłej terapii. Kolejne spotkania mogą wpłynąć na całkowity koszt diagnozy.</p>
                </div>
                <div>
                    <h2 class="adhd-dark__h2">Opinia końcowa</h2>
                    <p>Po zakończeniu diagnostyki otrzymasz szczegółową opinię wraz z wynikami, które pomogą Ci zrozumieć siebie i otworzyć drogę do wsparcia oraz terapii.</p>
                </div>
            </div>
        </div>
        <div class="adhd-dark__sub">
            <div class="psy-container">
                <div class="adhd-dark__sub-inner">
                    <div class="adhd-dark__sub-content">
                        <h2>Diagnoza ADHD – decyzja, która odmienia życie</h2>
                        <p>Diagnoza to pierwszy krok do zrozumienia swoich trudności i znalezienia wsparcia. Terapia może przynieść ulgę i realnie poprawić jakość Twojego życia.</p>
                    </div>
                    <div class="adhd-dark__sub-image">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/07/Warstwa_1.png" alt="Diagnoza ADHD" loading="lazy">
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- SPECJALIŚCI --}}
    <section class="psy-section psy-section--white adhd-specialists" id="slider">
        <div class="psy-container">
            <h2 class="section-title section-title--center">Umów się do naszych specjalistów ADHD</h2>
            {!! do_shortcode('[specjalisci_slider specjalizacja="diagnoza-adhd,adhd,diagnoza-adhd-u-doroslych" limit="8"]') !!}
        </div>
    </section>

    {{-- FAQ --}}
    <section class="psy-section psy-section--white adhd-faq">
        <div class="psy-container">
            <h2 class="section-title section-title--center">Najczęściej zadawane pytania o ADHD</h2>
            <div class="faq faq--centered faq--on-beige">
                @php
                    $faqs = [
                        ['q' => 'Jakie są objawy ADHD u dorosłych?', 'a' => 'Objawy ADHD u dorosłych mogą obejmować: trudności z koncentracją i uwagą – łatwe rozpraszanie się, zapominanie o obowiązkach; impulsywność – pochopne podejmowanie decyzji, przerywanie rozmów; problemy z organizacją czasu i planowaniem; nadmierną aktywność lub uczucie wewnętrznego niepokoju. Jeśli zauważasz u siebie powyższe symptomy, warto rozważyć diagnozę ADHD u dorosłych.'],
                        ['q' => 'Jak zdiagnozować ADHD u dorosłych?', 'a' => 'Diagnoza ADHD u dorosłych wymaga wizyty u psychiatry lub psychologa specjalizującego się w ADHD. Proces obejmuje: szczegółowy wywiad kliniczny i ocenę objawów, analizę historii życia i funkcjonowania w dzieciństwie, testy psychologiczne oceniające koncentrację, impulsywność i pamięć oraz w razie potrzeby konsultacje dodatkowe.'],
                        ['q' => 'Ile kosztuje diagnoza ADHD u dorosłych?', 'a' => 'W Fundacji Niepodzielni kompleksowa diagnoza ADHD kosztuje 750 zł i obejmuje 4 spotkania po 50 minut. Dostępna jest możliwość płatności ratalnej za pomocą Klarna.'],
                        ['q' => 'Jakie leki na ADHD są stosowane u dorosłych?', 'a' => 'Leki stosowane w leczeniu ADHD u dorosłych to: stymulanty (np. metylofenidat, amfetaminy) – poprawiają koncentrację i redukują impulsywność; niestymulujące leki (np. atomoksetyna) – pomagają w stabilizacji objawów. Leczenie farmakologiczne powinno być prowadzone pod nadzorem specjalisty.'],
                        ['q' => 'Czy ADHD u dorosłych może wpływać na zdrowie psychiczne?', 'a' => 'Tak, ADHD u dorosłych często współwystępuje z innymi zaburzeniami, takimi jak depresja, zaburzenia nastroju, zaburzenia lękowe oraz zaburzenia snu. Dlatego diagnoza ADHD powinna uwzględniać całościową ocenę stanu psychicznego.'],
                        ['q' => 'Jak sprawdzić, czy ma się ADHD?', 'a' => 'Aby sprawdzić, czy masz ADHD: zaobserwuj, czy występują u Ciebie typowe objawy; skonsultuj się z lekarzem psychiatrą lub psychologiem; poddaj się profesjonalnej diagnozie. Jeśli objawy wpływają na Twoje codzienne funkcjonowanie, warto skonsultować się ze specjalistą.'],
                        ['q' => 'Czy ADHD może wpływać na pracę zawodową?', 'a' => 'Tak, ADHD u dorosłych może powodować trudności w miejscu pracy: problemy z organizacją i zarządzaniem czasem, trudności w wykonywaniu zadań wymagających długotrwałej koncentracji oraz impulsywne podejmowanie decyzji. Odpowiednia diagnoza i terapia mogą pomóc poprawić funkcjonowanie zawodowe.'],
                        ['q' => 'Czy ADHD można leczyć terapią bez leków?', 'a' => 'Tak, skuteczne metody leczenia ADHD u dorosłych obejmują: terapię poznawczo-behawioralną (CBT), coaching ADHD – techniki organizacyjne i strategie radzenia sobie, regularną aktywność fizyczną i zdrową dietę oraz techniki relaksacyjne i medytację. Terapia może być skuteczną alternatywą lub uzupełnieniem farmakoterapii.'],
                    ];
                @endphp
                @foreach($faqs as $faq)
                    <div class="faq__item">
                        <div class="faq__question m_acordeon_head">{{ $faq['q'] }}</div>
                        <div class="faq__answer linkmenu">
                            <p>{{ $faq['a'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

</div>
@endsection
