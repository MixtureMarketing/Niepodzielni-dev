document.addEventListener('DOMContentLoaded', function () {

    // --- MEGA MENU LOGIC ---
    const siteHeader  = document.getElementById('site-header');
    const megaMenu    = document.getElementById('mega-menu');
    const burgerBtn   = document.querySelector('.burger');

    // Slider variables
    const sliderTrack   = document.querySelector('#megaMenuSlider .slider-track');
    const slides        = document.querySelectorAll('#megaMenuSlider .slider-slide');
    const dotsContainer = document.getElementById('megaMenuSliderDots');
    let currentSlide  = 0;
    let sliderInterval = null;

    function openMenu() {
        megaMenu.classList.add('is-active');
        megaMenu.style.maxHeight = '2000px';
        megaMenu.setAttribute('aria-hidden', 'false');
        burgerBtn.setAttribute('aria-expanded', 'true');
        burgerBtn.setAttribute('aria-label', 'Zamknij menu');
        startSlider();

        const firstLink = megaMenu.querySelector('a, button');
        if (firstLink) firstLink.focus();
    }

    function closeMenu() {
        megaMenu.classList.remove('is-active');
        megaMenu.style.maxHeight = '0';
        megaMenu.setAttribute('aria-hidden', 'true');
        burgerBtn.setAttribute('aria-expanded', 'false');
        burgerBtn.setAttribute('aria-label', 'Otwórz menu');
        stopSlider();
    }

    if (siteHeader && megaMenu && burgerBtn) {

        function positionMegaMenu() {
            const headerHeight = siteHeader.offsetHeight;
            megaMenu.style.top = headerHeight + 'px';
        }

        positionMegaMenu();
        window.addEventListener('resize', positionMegaMenu);

        burgerBtn.addEventListener('click', function () {
            const isOpen = this.getAttribute('aria-expanded') === 'true';
            if (isOpen) {
                closeMenu();
            } else {
                openMenu();
            }
        });

        // Escape closes menu and returns focus to burger button
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && burgerBtn.getAttribute('aria-expanded') === 'true') {
                closeMenu();
                burgerBtn.focus();
            }
        });
    }

    // --- SLIDER FUNCTIONS ---
    function initSlider() {
        if (!sliderTrack || slides.length <= 1) return;

        dotsContainer.innerHTML = '';
        slides.forEach((_, i) => {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.classList.add('dot');
            dot.setAttribute('aria-label', `Slajd ${i + 1}`);
            dot.setAttribute('aria-current', i === 0 ? 'true' : 'false');
            if (i === 0) dot.classList.add('is-active');
            dot.addEventListener('click', () => goToSlide(i));
            dotsContainer.appendChild(dot);
        });
    }

    function goToSlide(index) {
        currentSlide = index;
        const offset = -currentSlide * 100;
        sliderTrack.style.transform = `translateX(${offset}%)`;

        const dots = dotsContainer.querySelectorAll('.dot');
        dots.forEach((dot, i) => {
            const active = i === currentSlide;
            dot.classList.toggle('is-active', active);
            dot.setAttribute('aria-current', active ? 'true' : 'false');
        });
    }

    function startSlider() {
        if (slides.length <= 1) return;
        stopSlider();
        sliderInterval = setInterval(() => {
            currentSlide = (currentSlide + 1) % slides.length;
            goToSlide(currentSlide);
        }, 4000);
    }

    function stopSlider() {
        if (sliderInterval) clearInterval(sliderInterval);
    }

    initSlider();

    // --- Inne elementy UI ---
    const divToWrap = document.querySelector('.confij');
    if (divToWrap) {
        const urlParams = new URLSearchParams(window.location.search);
        const kParam    = urlParams.get('konsultacje');
        const targetUrl = (kParam === 'nisko') ? '/konsultacje-niskoplatne/' : '/konsultacje-psychologiczne-pelnoplatne/';
        const a = document.createElement('a');
        a.href = targetUrl;
        divToWrap.parentNode.insertBefore(a, divToWrap);
        a.appendChild(divToWrap);
        if (divToWrap.hasAttribute('href')) divToWrap.removeAttribute('href');
    }
});
