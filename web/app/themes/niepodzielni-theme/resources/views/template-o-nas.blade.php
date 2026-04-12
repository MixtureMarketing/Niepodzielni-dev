{{-- Template Name: O nas --}}

@extends('layouts.app')

@section('content')
<div class="onas-page">

    {{-- NAWIGACJA --}}
    <section class="onas-nav-section">
        <div class="psy-container">
            <nav class="page-nav">
                <a href="#o-nas" class="page-nav__btn page-nav__btn--active">O NAS</a>
                <a href="#historia" class="page-nav__btn">HISTORIA FUNDACJI</a>
                <a href="#kim-jestesmy" class="page-nav__btn">KIM SĄ NIEPODZIELNI</a>
                <a href="{{ site_url('/psychoedukacja/') }}" class="page-nav__btn">PSYCHOEDUKACJA</a>
            </nav>
        </div>
    </section>

    {{-- HERO Z ILUSTRACJĄ --}}
    <section class="onas-hero" id="o-nas">
        <div class="psy-container">
            <div class="onas-hero__inner">
                <div class="onas-hero__image">
                    <img src="https://niepodzielni.com/wp-content/uploads/2025/08/grupa-ludzi-2.svg" alt="Fundacja Niepodzielni – zespół" loading="eager">
                </div>
            </div>
        </div>
    </section>

    {{-- HISTORIA I MISJA --}}
    <section class="psy-section onas-story" id="historia">
        <div class="psy-container">
            <div class="onas-story__content">
                <p>Historia Fundacji Niepodzielni zaczęła się na długo przed jej formalnym powstaniem w 2022 roku. Kiedy mówimy, że „znamy ten problem" albo „wiemy, jak się czujesz" – naprawdę to mamy na myśli. Bo my też tam byłyśmy – w miejscu, gdzie świat traci kolory, a codzienność staje się wyzwaniem.</p>
                <p>Z potrzeby zaopiekowania się sobą i braku dostępnego wsparcia, zrodziła się nasza misja. Przez długi czas miałyśmy tylko dwie opcje – walczyć albo uciekać. Wybrałyśmy walkę – i właśnie z tego powstała Fundacja.</p>
                <p>Dziś działamy na rzecz powszechnego dostępu do wsparcia psychicznego. Wiemy, że nie każdy może sobie pozwolić na wizytę u specjalisty – dlatego stworzyłyśmy przestrzeń, w której każdy może uzyskać pomoc. Oferujemy niskopłatne konsultacje psychologiczne oraz pełnopłatne spotkania – obie formy wsparcia są częścią naszego solidarnościowego systemu. Dzięki nim możemy finansować pomoc dla osób znajdujących się w trudniejszej sytuacji życiowej.</p>
                <p>Każda opłacona konsultacja to nie tylko krok ku własnemu dobrostanowi – to również realne wsparcie dla kogoś, kto w danym momencie nie może sobie na to pozwolić. Wspólnie tworzymy krąg pomocy. Wspierając nas, wspierasz innych. Dołączasz do społeczności, która wierzy w dostępność, empatię i realną zmianę.</p>
                <p>Działamy w wielu obszarach, bo wiemy, że pomoc psychologiczna to nie tylko rozmowa. To też edukacja, obecność, wspólne działania i budowanie świadomości. <strong>Zależy nam na Tobie</strong> – jako na indywidualnej osobie i jako ważnej części społeczności, w której funkcjonujesz.</p>
                <a href="{{ site_url('/psychoedukacja/') }}" class="psy-btn psy-btn-green">PSYCHOEDUKACJA</a>
            </div>
        </div>
    </section>

    {{-- ZESPÓŁ --}}
    <section class="onas-team" id="kim-jestesmy">
        <div class="psy-container">
            <div class="onas-team__intro">
                <p>Jesteśmy zespołem bardzo różnych osób – z różnym wykształceniem, doświadczeniem, spojrzeniem na świat. Każda z nas wnosi coś unikalnego. Osobno jesteśmy ciekawymi fragmentami, ale razem – tworzymy wzór nie do podrobienia. Łączy nas jedno: troska o drugiego człowieka.</p>
            </div>
            <div class="onas-team__grid">

                <div class="onas-member">
                    <div class="onas-member__photo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/06/481945003_122099545172792072_278549323647109703_nf.jpg" alt="Prezeska Fundacji Niepodzielni" loading="lazy">
                    </div>
                    <p class="onas-member__role">Prezeska</p>
                </div>

                <div class="onas-member">
                    <div class="onas-member__photo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/06/sssa.jpg" alt="Wiceprezeska Fundacji Niepodzielni" loading="lazy">
                    </div>
                    <p class="onas-member__role">Wiceprezeska</p>
                </div>

                <div class="onas-member">
                    <div class="onas-member__photo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/06/481945003_122099545172792072_278549323647109703_n.jpg" alt="Członkini Zarządu Fundacji Niepodzielni" loading="lazy">
                    </div>
                    <p class="onas-member__role">Członkini Zarządu</p>
                </div>

                <div class="onas-member">
                    <div class="onas-member__photo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/06/41217302-c3e6-4a24-bd8e-5da0ce4e9ce2.jpg" alt="Graficzka Fundacji Niepodzielni" loading="lazy">
                    </div>
                    <p class="onas-member__role">Graficzka</p>
                </div>

                <div class="onas-member">
                    <div class="onas-member__photo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/06/Laura-Grzeskowiak.jpg" alt="Specjalistka ds. HR" loading="lazy">
                    </div>
                    <p class="onas-member__role">Specjalistka ds. HR</p>
                </div>

                <div class="onas-member">
                    <div class="onas-member__photo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/09/image0-4-scaled.jpeg" alt="Managerka HR" loading="lazy">
                    </div>
                    <p class="onas-member__role">Managerka HR</p>
                </div>

                <div class="onas-member">
                    <div class="onas-member__photo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/06/344751588_783602909796676_2521869941460176023_n.jpg" alt="Social Media Managerka" loading="lazy">
                    </div>
                    <p class="onas-member__role">Social Media Managerka</p>
                </div>

                <div class="onas-member">
                    <div class="onas-member__photo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/08/KAROLINA-ZAWADZKA_Specjalistka-ds.-koordynacji-eventow-scaled-e1756109077357.jpg" alt="Specjalistka ds. koordynacji grup wsparcia i wolontariatu" loading="lazy">
                    </div>
                    <p class="onas-member__role">Specjalistka ds. koordynacji grup wsparcia i wolontariatu</p>
                </div>

                <div class="onas-member">
                    <div class="onas-member__photo">
                        <img src="https://niepodzielni.com/wp-content/uploads/2025/06/ssc-xs.jpg" alt="Specjalistka ds. obsługi klientów i eventów" loading="lazy">
                    </div>
                    <p class="onas-member__role">Specjalistka ds. obsługi klientów i eventów</p>
                </div>

            </div>
        </div>
    </section>

</div>
@endsection
