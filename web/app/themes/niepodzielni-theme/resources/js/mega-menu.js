document.addEventListener('DOMContentLoaded', function() {
    
    // --- MEGA MENU LOGIC ---
    const siteHeader = document.getElementById('site-header');
    const megaMenu = document.querySelector('.mega_menu');
    const burgerCheckbox = document.querySelector('#burger');

    // Slider variables
    const sliderTrack = document.querySelector('#megaMenuSlider .slider-track');
    const slides = document.querySelectorAll('#megaMenuSlider .slider-slide');
    const dotsContainer = document.getElementById('megaMenuSliderDots');
    let currentSlide = 0;
    let sliderInterval = null;

    if (siteHeader && megaMenu && burgerCheckbox) {
        
        function positionMegaMenu() {
            const headerHeight = siteHeader.offsetHeight;
            megaMenu.style.top = headerHeight + 'px';
        }

        positionMegaMenu();
        window.addEventListener('resize', positionMegaMenu);
        
        burgerCheckbox.addEventListener('change', function() {
            if (this.checked) {
                megaMenu.classList.add('is-active');
                megaMenu.style.maxHeight = '2000px'; 
                startSlider(); // Startuj slider tylko gdy menu jest otwarte
            } else {
                megaMenu.classList.remove('is-active');
                megaMenu.style.maxHeight = '0';
                stopSlider(); // Zatrzymaj slider gdy menu jest zamknięte
            }
        });
    }

    // --- SLIDER FUNCTIONS ---
    function initSlider() {
        if (!sliderTrack || slides.length <= 1) return;
        
        // Generuj kropki
        dotsContainer.innerHTML = '';
        slides.forEach((_, i) => {
            const dot = document.createElement('div');
            dot.classList.add('dot');
            if (i === 0) dot.classList.add('is-active');
            dot.addEventListener('click', () => goToSlide(i));
            dotsContainer.appendChild(dot);
        });
    }

    function goToSlide(index) {
        currentSlide = index;
        const offset = -currentSlide * 100;
        sliderTrack.style.transform = `translateX(${offset}%)`;
        
        // Aktualizuj kropki
        const dots = dotsContainer.querySelectorAll('.dot');
        dots.forEach((dot, i) => {
            dot.classList.toggle('is-active', i === currentSlide);
        });
    }

    function startSlider() {
        if (slides.length <= 1) return;
        stopSlider();
        sliderInterval = setInterval(() => {
            currentSlide = (currentSlide + 1) % slides.length;
            goToSlide(currentSlide);
        }, 4000); // Zmiana co 4 sekundy
    }

    function stopSlider() {
        if (sliderInterval) clearInterval(sliderInterval);
    }

    initSlider();

    // Appointment widget obsługiwany w components/appointment-widget.js

    // --- Inne elementy UI ---
    const divToWrap = document.querySelector('.confij');
    if (divToWrap) {
        const urlParams = new URLSearchParams(window.location.search);
        const kParam = urlParams.get('konsultacje');
        let targetUrl = (kParam === 'nisko') ? '/konsultacje-niskoplatne/' : '/konsultacje-psychologiczne-pelnoplatne/';
        const a = document.createElement('a');
        a.href = targetUrl;
        divToWrap.parentNode.insertBefore(a, divToWrap);
        a.appendChild(divToWrap);
        if (divToWrap.hasAttribute('href')) divToWrap.removeAttribute('href');
    }
});
