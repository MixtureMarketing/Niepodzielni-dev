import Swiper from 'swiper';
import { Navigation, Pagination, Autoplay } from 'swiper/modules';

document.addEventListener('DOMContentLoaded', function () {

    // Slider Specjalistów — lazy init przez IntersectionObserver
    const sliderContainer = document.querySelector('.specialists-slider-container');
    if (sliderContainer) {
        const lazyLoadSlider = (entries, observer) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const swiperEl  = sliderContainer.querySelector('.specialists-slider');
                const pagination = sliderContainer.querySelector('.swiper-pagination');
                const nextBtn    = sliderContainer.querySelector('.swiper-button-next');
                const prevBtn    = sliderContainer.querySelector('.swiper-button-prev');

                new Swiper(swiperEl, {
                    modules: [Navigation, Pagination],
                    loop: false,
                    slidesPerView: 1,
                    spaceBetween: 16,
                    pagination: { el: pagination, clickable: true },
                    navigation: { nextEl: nextBtn, prevEl: prevBtn },
                    breakpoints: {
                        640:  { slidesPerView: 2.2, spaceBetween: 24 },
                        1024: { slidesPerView: 3.3, spaceBetween: 24 },
                    },
                });
                observer.unobserve(sliderContainer);
            });
        };
        new IntersectionObserver(lazyLoadSlider, { rootMargin: '0px 0px 200px 0px' })
            .observe(sliderContainer);
    }

    // Slider Partnerów — lazy init przez IntersectionObserver (jest na dole strony).
    // Bezpośredni init na DOMContentLoaded powodował forced reflow (~150ms TBT).
    const partnersSlider = document.querySelector('.partners-slider');
    if (partnersSlider) {
        new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                new Swiper(partnersSlider, {
                    modules: [Autoplay],
                    loop: true,
                    slidesPerView: 2,
                    spaceBetween: 20,
                    autoplay: { delay: 3000, disableOnInteraction: false },
                    breakpoints: {
                        640:  { slidesPerView: 3, spaceBetween: 30 },
                        768:  { slidesPerView: 4, spaceBetween: 40 },
                        1024: { slidesPerView: 6, spaceBetween: 50 },
                    },
                });
                obs.unobserve(partnersSlider);
            });
        }, { rootMargin: '0px 0px 300px 0px' }).observe(partnersSlider);
    }
});
