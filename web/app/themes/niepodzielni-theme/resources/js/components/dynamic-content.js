/**
 * dynamic-content.js
 * Obsługuje interaktywne komponenty które mogą pojawiać się dynamicznie w DOM:
 * - Rozwijanie "Obszarów Pomocy"
 * - Rozwijane opisy produktów (.sdesc)
 * - Toggle Switch dla filtrów
 * - MutationObserver (reinit po zmianach DOM)
 * - Reinit po kliknięciu zakładek Elementor
 */

function initDynamicContent() {

    // --- Obszary Pomocy ---
    document.querySelectorAll('[data-help-areas-container]:not([data-init])').forEach(container => {
        container.setAttribute('data-init', 'true');
        const showMoreButton = container.querySelector('[data-show-more-tags]');
        if (!showMoreButton) return;

        const originalButtonText = showMoreButton.textContent;

        requestAnimationFrame(() => {
            container.classList.remove('is-expanded');
            const collapsedHeight = container.scrollHeight;
            container.style.maxHeight = collapsedHeight + 'px';
            container.dataset.collapsedHeight = collapsedHeight;

            container.classList.add('is-expanded');
            container.dataset.expandedHeight = container.scrollHeight;

            container.classList.remove('is-expanded');
            container.style.maxHeight = collapsedHeight + 'px';
        });

        showMoreButton.addEventListener('click', function () {
            const isExpanded = container.classList.contains('is-expanded');
            if (isExpanded) {
                this.textContent = originalButtonText;
                container.style.maxHeight = container.dataset.collapsedHeight + 'px';
                container.addEventListener('transitionend', function handler() {
                    container.classList.remove('is-expanded');
                    container.removeEventListener('transitionend', handler);
                });
            } else {
                this.textContent = 'Zwiń';
                container.classList.add('is-expanded');
                container.style.maxHeight = container.dataset.expandedHeight + 'px';
            }
        });
    });

    // --- Opisy produktów (.sdesc) ---
    document.querySelectorAll('[data-sdesc-container]:not([data-init])').forEach(container => {
        container.setAttribute('data-init', 'true');
        container.querySelector('.sdesc-short')?.classList.add('is-visible');
        container.querySelector('.sdesc-full')?.classList.remove('is-visible');
    });
}

document.addEventListener('DOMContentLoaded', function () {
    initDynamicContent();

    // Delegacja zdarzeń dla .sdesc
    document.addEventListener('click', function (e) {
        const showMoreBtn = e.target.closest('.sdesc-show-more');
        if (showMoreBtn) {
            const c = showMoreBtn.closest('[data-sdesc-container]');
            c.querySelector('.sdesc-short')?.classList.remove('is-visible');
            c.querySelector('.sdesc-full')?.classList.add('is-visible');
            return;
        }
        const collapseBtn = e.target.closest('.sdesc-collapse');
        if (collapseBtn) {
            const c = collapseBtn.closest('[data-sdesc-container]');
            c.querySelector('.sdesc-full')?.classList.remove('is-visible');
            c.querySelector('.sdesc-short')?.classList.add('is-visible');
        }
    });

    // Toggle Switch dla filtrów JetEngine
    const filterSwitch = document.querySelector('.filter_switch');
    if (filterSwitch) {
        const fieldset = filterSwitch.querySelector('fieldset');
        const updateSwitch = () => {
            const checked = filterSwitch.querySelector('.jet-radio-list__input:checked');
            fieldset.classList.toggle('switch-right', !!checked && checked.value === '99');
        };
        updateSwitch();
        filterSwitch.addEventListener('change', updateSwitch);
    }

    // MutationObserver — reinit po zmianach DOM
    let mutationTimeout;
    const domObserver = new MutationObserver(() => {
        clearTimeout(mutationTimeout);
        mutationTimeout = setTimeout(initDynamicContent, 150);
    });
    domObserver.observe(document.body, { childList: true, subtree: true });

    // Reinit po kliknięciu zakładki Elementor
    let reinitTimeout;
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.karty-css .e-n-tabs-heading button')) return;
        clearTimeout(reinitTimeout);
        reinitTimeout = setTimeout(() => {
            initDynamicContent();
            document.querySelectorAll('[data-help-areas-container]').forEach(container => {
                const wasExpanded = container.classList.contains('is-expanded');
                container.style.maxHeight = 'none';
                container.classList.remove('is-expanded');
                const h = container.scrollHeight;
                container.dataset.collapsedHeight = h;
                container.dataset.expandedHeight  = h;
                if (wasExpanded) {
                    container.classList.add('is-expanded');
                    container.style.maxHeight = h + 'px';
                } else {
                    container.style.maxHeight = h + 'px';
                }
            });
        }, 150);
    });
});
