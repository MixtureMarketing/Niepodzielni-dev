/**
 * Wall of impact — countup animation.
 *
 * Animuje liczby z 0 do data-countup po wjeździe w viewport.
 * Respektuje prefers-reduced-motion (ustawia od razu finałową wartość).
 *
 * Format:
 *   <span data-countup="42" data-countup-decimal="0">0</span>
 *   <span data-countup="4.7" data-countup-decimal="1">0,0</span>
 */

const DURATION = 1500;

function format(value, isDecimal) {
    const fixed = isDecimal ? value.toFixed(1) : Math.round(value).toString();
    // Polski separator dziesiętny + spacja jako separator tysięcy
    return fixed.replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

function animate(el) {
    const target = parseFloat(el.dataset.countup);
    if (!Number.isFinite(target)) return;

    const isDecimal = el.dataset.countupDecimal === '1';

    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        el.textContent = format(target, isDecimal);
        return;
    }

    const start = performance.now();
    const startVal = 0;

    function tick(now) {
        const t = Math.min((now - start) / DURATION, 1);
        // easing-out cubic
        const eased = 1 - Math.pow(1 - t, 3);
        const current = startVal + (target - startVal) * eased;
        el.textContent = format(current, isDecimal);
        if (t < 1) requestAnimationFrame(tick);
    }

    requestAnimationFrame(tick);
}

function init() {
    const elements = document.querySelectorAll('[data-countup]');
    if (elements.length === 0) return;

    if (typeof IntersectionObserver !== 'function') {
        // fallback: animuj wszystko od razu
        elements.forEach(animate);
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            animate(entry.target);
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.4 });

    elements.forEach((el) => observer.observe(el));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
