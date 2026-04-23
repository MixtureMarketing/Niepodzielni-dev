<footer class="site-footer">

    {{-- SEKCJA 1: MAIN WIDGETS --}}
    <div class="footer-main-widgets">
        <div class="psy-container psy-grid psy-grid-4">

            {{-- KOLUMNA 1: Info o firmie --}}
            <div class="footer-col">
                <ul class="footer-contact-list">
                    <li>
                        <span class="footer-icon">{!! get_niepodzielni_svg_icon('online') !!}</span>
                        <span><b>Główna siedziba:</b> Poznań ul. Zeylanda 9/3, 60-808</span>
                    </li>
                    <li>
                        <span class="footer-icon">{!! get_niepodzielni_svg_icon('online') !!}</span>
                        <span><b>Punkt:</b> Warszawa ul. Środkowa 30, Praga, 03-431</span>
                    </li>
                    <li>
                        <span class="footer-icon">{!! get_niepodzielni_svg_icon('online') !!}</span>
                        <span>REGON 522108288</span>
                    </li>
                    <li>
                        <span class="footer-icon">{!! get_niepodzielni_svg_icon('online') !!}</span>
                        <span>NIP 7812036026</span>
                    </li>
                    <li>
                        <span class="footer-icon">{!! get_niepodzielni_svg_icon('online') !!}</span>
                        <span>KRS 0000973514</span>
                    </li>
                </ul>
            </div>

            {{-- KOLUMNA 2: Obserwuj Nas --}}
            <div class="footer-col">
                <h3 class="footer-heading">OBSERWUJ NAS</h3>
                @include('partials.social-icons')
            </div>

            {{-- KOLUMNA 3: Kontakt --}}
            <div class="footer-col">
                <h3 class="footer-heading">KONTAKT</h3>
                <ul class="footer-contact-list">
                    <li>
                        <a href="mailto:kontakt@niepodzielni.com">
                            <span class="footer-icon">{!! get_niepodzielni_svg_icon('online') !!}</span>
                            <span>kontakt@niepodzielni.com</span>
                        </a>
                    </li>
                    <li>
                        <a href="tel:+48668277176">
                            <span class="footer-icon">{!! get_niepodzielni_svg_icon('online') !!}</span>
                            <span>(+48) 668 277 176</span>
                        </a>
                    </li>
                    <li>
                        <span class="footer-icon">{!! get_niepodzielni_svg_icon('online') !!}</span>
                        <span>9:00 - 15:00 Obsługa klienta</span>
                    </li>
                </ul>
            </div>

            {{-- KOLUMNA 4: Telefony zaufania --}}
            <div class="footer-col emergency-col">
                <div class="emergency-item">
                    <div class="emergency-number-col"><h4>112</h4></div>
                    <div class="emergency-text-col"><p>Numer alarmowy w sytuacji<br>zagrożenia życia lub zdrowia</p></div>
                </div>
                <div class="emergency-item">
                    <div class="emergency-number-col"><h4>116 111</h4></div>
                    <div class="emergency-text-col"><p>Telefon Zaufania dla Dzieci i Młodzieży</p></div>
                </div>
                <div class="emergency-item">
                    <div class="emergency-number-col"><h4>800 70 22 22</h4></div>
                    <div class="emergency-text-col"><p>Centrum Wsparcia dla Osób Dorosłych w Kryzysie Psychicznym</p></div>
                </div>
                <div class="emergency-item">
                    <div class="emergency-number-col"><h4>800 12 12 12</h4></div>
                    <div class="emergency-text-col"><p>Wsparcie psychologiczne w sytuacji kryzysowej – infolinia dla dzieci, młodzieży i opiekunów</p></div>
                </div>
            </div>

        </div>
    </div>

    {{-- SEKCJA 2: PARTNERS SLIDER --}}
    @php
        $partners = [
            '/wp-content/uploads/2025/04/ThaiBaliSpa_Logo100px.svg',
            '/wp-content/uploads/2025/04/sensus-logo-ikona.svg',
            '/wp-content/uploads/2025/04/logo-psychostart-e1731405217594-1.svg',
            '/wp-content/uploads/2025/04/Signius_logo_-_primary-1.svg',
            '/wp-content/uploads/2025/04/charaktery.svg',
            '/wp-content/uploads/2025/04/Kanal-studencki-logo-kolor-e1726870408355.svg',
            '/wp-content/uploads/2025/04/images.svg',
            '/wp-content/uploads/elementor/thumbs/567039603_1958579361386639_7751121447112997142_n-rkgcts4z6pae1q4v1j2vs1kt01ap5hirggngn6kk4o.jpg',
        ];
    @endphp
    <section class="home-partners-section">
        <div class="psy-container">
            <div class="partners-slider swiper">
                <div class="swiper-wrapper">
                    @foreach($partners as $partner_url)
                        <div class="swiper-slide">
                            <div class="partner-logo-wrapper">
                                <img src="{{ $partner_url }}" alt="Partner logo" loading="lazy">
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- SEKCJA 3: BOTTOM BAR --}}
    <div class="footer-bottom-bar">
        <div class="psy-container">
            <p>
                &copy; {{ date('Y') }} Fundacja Niepodzielni |
                <a href="/polityka-prywatnosci/">Polityka Prywatności</a> |
                <a href="/regulamin-rezerwacji-wizyt-stacjonarnie-i-wizyt-online/">Regulamin konsultacji</a> |
                <a href="/statut-fundacji/">Statut fundacji</a> |
                <a href="/standardy-ochrony-maloletnich-fundacji-niepodzielni/">Standardy Ochrony Małoletnich</a> |
                <a href="/1_5_procent/">Przekaż 1,5%</a>
            </p>
        </div>
    </div>

</footer>

<div id="np-ai-chat"></div>
