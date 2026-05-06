/**
 * Crisis Help Hub — szybkie wyjście.
 *
 * Reaguje na klawisz Esc oraz przycisk z atrybutem [data-np-crisis-hide].
 * Czyści historię i wykonuje pełny redirect na google.com — żeby ktoś,
 * kto cofa stronę, nie wracał na Crisis Hub.
 */

const HIDE_URL = 'https://www.google.com/';
const TYPING_TAGS = new Set(['INPUT', 'TEXTAREA', 'SELECT']);

function isTyping(target) {
    if (!(target instanceof HTMLElement)) return false;
    if (TYPING_TAGS.has(target.tagName)) return true;
    if (target.isContentEditable) return true;
    return false;
}

function hideCrisisPage() {
    try {
        history.replaceState({}, '', '/');
    } catch {
        /* niektóre przeglądarki w trybie prywatnym blokują replaceState */
    }
    window.location.replace(HIDE_URL);
}

function init() {
    if (!document.querySelector('[data-np-crisis-page]')) return;

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (isTyping(event.target)) return;
        event.preventDefault();
        hideCrisisPage();
    });

    document.querySelectorAll('[data-np-crisis-hide]').forEach((btn) => {
        btn.addEventListener('click', (event) => {
            event.preventDefault();
            hideCrisisPage();
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
