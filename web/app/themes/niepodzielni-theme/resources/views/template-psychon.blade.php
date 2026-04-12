{{-- Template Name: PsychON --}}

@extends('layouts.app')

@section('content')
<div class="psychon-page">

    {{-- HERO --}}
    <section class="psychon-hero">
        <div class="psy-container">
            <div class="psychon-hero__inner">
                <div class="psychon-hero__content">
                    <h1 class="psychon-hero__title">PSYCH<span class="psychon-hero__on">ON</span></h1>
                    <p>Praktyczne szkolenie online dla psychologów i psychoterapeutów, którzy chcą rozwinąć swój warsztat i zdobyć konkretne narzędzia do pracy z klientem.</p>
                    <p>Dołącz do 6. edycji PsychON — <strong>zapisz się już dziś!</strong></p>
                    <a href="mailto:kontakt@niepodzielni.com" class="psy-btn psy-btn-green">ZAPISZ SIĘ NA PROGRAM</a>
                </div>
                <div class="psychon-hero__image">
                    <img src="https://niepodzielni.com/wp-content/uploads/2025/08/psychon-hero.png" alt="PsychON" width="420" loading="eager">
                </div>
            </div>
        </div>
    </section>

    {{-- CO ZAWIERA + DLA KOGO --}}
    <section class="psy-section psy-section--beige">
        <div class="psy-container">
            <div class="psychon-overview__grid">
                <div>
                    <h2 class="section-title">Co zawiera 6. edycja?</h2>
                    <ul class="checklist">
                        <li>Superwizje grupowe online prowadzone przez ekspertów</li>
                        <li>5 × 70-minutowych tematycznych spotkań online</li>
                        <li>Wdrożenie wiedzy z opieką mentora</li>
                        <li>Certyfikat ukończenia programu PsychON</li>
                        <li>Materiały szkoleniowe i narzędzia do pracy z klientem</li>
                        <li>Webinary gościnne z zaproszonych specjalistów</li>
                        <li>Dostęp do zamkniętej społeczności PsychON</li>
                    </ul>
                </div>
                <div>
                    <h2 class="section-title">Dla kogo jest PsychON?</h2>
                    <ul class="checklist">
                        <li>Psychologów chcących poszerzyć warsztat pracy</li>
                        <li>Psychoterapeutów szukających superwizji i wsparcia grupy</li>
                        <li>Absolwentów psychologii stawiających pierwsze kroki w zawodzie</li>
                        <li>Specjalistów chcących pracować z nową grupą klientów</li>
                        <li>Osób pracujących w obszarze zdrowia psychicznego</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- 3 FILARY PROGRAMU --}}
    <section class="psy-section psy-section--white">
        <div class="psy-container">
            <div class="psychon-pillars__grid">
                <div class="psychon-pillar">
                    <h3>SUPERWIZJE — GRUPY ONLINE</h3>
                    <p>Regularne spotkania superwizyjne w małych grupach, prowadzone przez doświadczonych mentorów. Przestrzeń do omówienia trudnych przypadków i refleksji nad własną pracą kliniczną.</p>
                </div>
                <div class="psychon-pillar">
                    <h3>5 × 70-MINUTOWYCH SPOTKAŃ</h3>
                    <p>Intensywne moduły szkoleniowe online łączące teorię z praktyką. Po każdym spotkaniu wychodzisz z gotowymi narzędziami, które możesz zastosować natychmiast.</p>
                </div>
                <div class="psychon-pillar">
                    <h3>WDROŻENIE Z OPIEKĄ I CERTYFIKAT</h3>
                    <p>Indywidualne wsparcie przy wdrażaniu wiedzy w praktyce. Po ukończeniu programu otrzymujesz certyfikat PsychON potwierdzający zdobyte kompetencje.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- CO ZYSKASZ --}}
    <section class="psy-section psy-section--beige">
        <div class="psy-container">
            <h2 class="section-title section-title--center">CO ZYSKASZ?</h2>
            <div class="psychon-gains__grid">
                <div class="psychon-gain"><span class="psychon-gain__dot"></span>Gotowe narzędzia i metody pracy z klientem</div>
                <div class="psychon-gain"><span class="psychon-gain__dot"></span>Zestaw materiałów szkoleniowych do pobrania</div>
                <div class="psychon-gain"><span class="psychon-gain__dot"></span>Webinary gościnne i konferencje online</div>
                <div class="psychon-gain"><span class="psychon-gain__dot"></span>Superwizyjne wsparcie grupy i mentora</div>
                <div class="psychon-gain"><span class="psychon-gain__dot"></span>Dostęp do platformy i nagrań ze spotkań</div>
                <div class="psychon-gain"><span class="psychon-gain__dot"></span>Społeczność praktyków — sieć kontaktów</div>
                <div class="psychon-gain"><span class="psychon-gain__dot"></span>Certyfikat ukończenia szkolenia PsychON</div>
                <div class="psychon-gain"><span class="psychon-gain__dot"></span>Praktyczna wiedza poparta case studies</div>
            </div>
        </div>
    </section>

    {{-- KOSZT + JAK TO DZIAŁA --}}
    <section class="psy-section psy-section--white">
        <div class="psy-container">
            <div class="psychon-cost__layout">
                <div class="psychon-cost__card">
                    <p class="psychon-cost__label">KOSZT UDZIAŁU</p>
                    <div class="psychon-cost__amount">3500 <span>zł</span></div>
                    <p>Cena obejmuje pełny dostęp do programu, materiałów i certyfikatu.<br>Możliwość płatności w ratach.</p>
                    <a href="mailto:kontakt@niepodzielni.com" class="psy-btn psy-btn-green">ZAPISZ SIĘ</a>
                </div>
                <div class="psychon-cost__steps">
                    <h2 class="section-title">Jak to działa?</h2>
                    <ol class="psychon-steps">
                        <li><div class="psychon-steps__num">1</div><div><strong>Wypełnij zgłoszenie</strong><p>Napisz do nas na kontakt@niepodzielni.com — powiedz kilka słów o sobie i swoich oczekiwaniach.</p></div></li>
                        <li><div class="psychon-steps__num">2</div><div><strong>Rozmowa kwalifikacyjna</strong><p>Skontaktujemy się z Tobą, aby omówić Twoje cele i upewnić się, że program odpowiada Twoim potrzebom.</p></div></li>
                        <li><div class="psychon-steps__num">3</div><div><strong>Opłata i potwierdzenie</strong><p>Po akceptacji zgłoszenia dokonujesz opłaty i otrzymujesz dostęp do platformy.</p></div></li>
                        <li><div class="psychon-steps__num">4</div><div><strong>Dołączasz do programu</strong><p>Startujemy! Pierwsze spotkanie online i dostęp do społeczności PsychON od pierwszego dnia.</p></div></li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    {{-- LEAD MENTOR --}}
    <section class="psy-section psy-section--midnight">
        <div class="psy-container">
            <div class="psychon-lead__inner">
                <div class="psychon-lead__text">
                    <p class="psychon-lead__quote">Wybieramy najlepszych, aby zapewnić Ci najwyższą jakość praktycznej wiedzy — specjalistów, którzy sami przez lata pracowali w gabinecie i wiedzą, z jakimi wyzwaniami mierzą się terapeuci na co dzień.</p>
                    <p class="psychon-lead__name">Natalia Prajewska</p>
                    <p class="psychon-lead__role">Kierownik merytoryczny PsychON</p>
                    <a href="#rekrutacja" class="psy-btn psy-btn-outline">DOŁĄCZ DO PROGRAMU</a>
                </div>
                <div class="psychon-lead__photo">
                    <img src="https://niepodzielni.com/wp-content/uploads/2025/08/natalia-prajewska.jpg" alt="Natalia Prajewska" loading="lazy">
                </div>
            </div>
        </div>
    </section>

    {{-- MENTORZY --}}
    <section class="psy-section psy-section--beige psychon-mentors">
        <div class="psy-container">
            <h2 class="section-title section-title--center">Poznaj naszych Mentorów</h2>
            <div class="psychon-mentors__grid">
                <div class="psychon-mentor">
                    <div class="psychon-mentor__photo"><img src="https://niepodzielni.com/wp-content/uploads/2025/08/mentor-anna.jpg" alt="Anna Kandyba" loading="lazy"></div>
                    <h4>Anna Kandyba</h4>
                    <p>Psycholog, superwizor</p>
                </div>
                <div class="psychon-mentor">
                    <div class="psychon-mentor__photo"><img src="https://niepodzielni.com/wp-content/uploads/2025/08/mentor-aleksandra.jpg" alt="Aleksandra Słabe" loading="lazy"></div>
                    <h4>Aleksandra Słabe</h4>
                    <p>Psychoterapeuta</p>
                </div>
                <div class="psychon-mentor">
                    <div class="psychon-mentor__photo"><img src="https://niepodzielni.com/wp-content/uploads/2025/08/mentor-malgorzata.jpg" alt="Małgorzata Milewska" loading="lazy"></div>
                    <h4>Małgorzata Milewska</h4>
                    <p>Psycholog kliniczny</p>
                </div>
                <div class="psychon-mentor">
                    <div class="psychon-mentor__photo"><img src="https://niepodzielni.com/wp-content/uploads/2025/08/mentor-bartlomiej.jpg" alt="Bartłomiej Soda" loading="lazy"></div>
                    <h4>Bartłomiej Soda</h4>
                    <p>Psychiatra</p>
                </div>
            </div>
        </div>
    </section>

    {{-- OPINIE --}}
    <section class="psy-section psy-section--white psychon-testimonials">
        <div class="psy-container">
            <h2 class="section-title section-title--center">Co mówią uczestnicy pierwszej edycji PsychON?</h2>
            <div class="psychon-testimonials__grid">
                <div class="psychon-testimonial">
                    <p class="psychon-testimonial__quote">Program zmienił sposób, w jaki pracuję z klientami. Narzędzia są naprawdę praktyczne i od razu mogłam je wdrożyć w gabinecie.</p>
                    <strong>Uczestniczka I edycji</strong>
                    <span>Psycholog</span>
                </div>
                <div class="psychon-testimonial">
                    <p class="psychon-testimonial__quote">Superwizje grupowe to była strzała w dziesiątkę. Możliwość omówienia trudnych przypadków w bezpiecznej grupie — bezcenna.</p>
                    <strong>Uczestnik I edycji</strong>
                    <span>Psychoterapeuta</span>
                </div>
                <div class="psychon-testimonial">
                    <p class="psychon-testimonial__quote">PsychON dał mi konkretne strategie i wsparcie mentora na każdym kroku. Polecam każdemu psychologowi na początku drogi zawodowej.</p>
                    <strong>Uczestniczka I edycji</strong>
                    <span>Psycholog, absolwentka</span>
                </div>
            </div>
        </div>
    </section>

    {{-- REKRUTACJA --}}
    <section class="psy-section psy-section--midnight" id="rekrutacja">
        <div class="psy-container">
            <div class="psychon-recruitment__inner">
                <div>
                    <h2 class="section-title section-title--white">Rekrutacja — jak dołączyć?</h2>
                    <ul class="psychon-recruitment__steps">
                        <li>Wyślij zgłoszenie na kontakt@niepodzielni.com</li>
                        <li>Rozmowa kwalifikacyjna online (20–30 min)</li>
                        <li>Potwierdzenie uczestnictwa i opłata</li>
                        <li>Dostęp do platformy i start programu</li>
                    </ul>
                </div>
                <div class="psychon-recruitment__cta">
                    <p>Nie czekaj — <strong>liczba miejsc jest ograniczona!</strong></p>
                    <a href="mailto:kontakt@niepodzielni.com" class="psy-btn psy-btn-green">ZAPISZ SIĘ DO 6. EDYCJI</a>
                </div>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="psy-section psy-section--beige">
        <div class="psy-container">
            <h2 class="section-title section-title--center">FAQ — Najczęściej zadawane pytania</h2>
            <div class="faq faq--centered">
                <div class="faq__item">
                    <div class="faq__question m_acordeon_head">Kiedy startuje 6. edycja PsychON?</div>
                    <div class="faq__answer linkmenu"><p>Termin startu 6. edycji zostanie ogłoszony wkrótce. Zapisz się do listy oczekujących, aby jako pierwsza/y otrzymać informację o rekrutacji.</p></div>
                </div>
                <div class="faq__item">
                    <div class="faq__question m_acordeon_head">Czy program odbywa się w całości online?</div>
                    <div class="faq__answer linkmenu"><p>Tak, cały program PsychON odbywa się online. Spotkania grupowe, superwizje i dostęp do materiałów — wszystko na dedykowanej platformie.</p></div>
                </div>
                <div class="faq__item">
                    <div class="faq__question m_acordeon_head">Czy mogę zapłacić w ratach?</div>
                    <div class="faq__answer linkmenu"><p>Tak, oferujemy możliwość rozłożenia płatności na raty. Szczegóły omawiamy indywidualnie podczas rozmowy kwalifikacyjnej.</p></div>
                </div>
                <div class="faq__item">
                    <div class="faq__question m_acordeon_head">Co się stanie, jeśli opuszczę jedno ze spotkań?</div>
                    <div class="faq__answer linkmenu"><p>Wszystkie spotkania są nagrywane. Nagranie będzie dostępne na platformie w ciągu 48 godzin.</p></div>
                </div>
                <div class="faq__item">
                    <div class="faq__question m_acordeon_head">Czy otrzymam certyfikat po ukończeniu programu?</div>
                    <div class="faq__answer linkmenu"><p>Tak, uczestnicy, którzy ukończą program, otrzymują certyfikat PsychON potwierdzający udział i zdobyte kompetencje.</p></div>
                </div>
            </div>
        </div>
    </section>

</div>
@endsection
