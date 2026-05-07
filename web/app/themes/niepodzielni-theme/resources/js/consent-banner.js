/**
 * consent-banner.js
 *
 * UI dla Consent Mode v2 (Zaraz). Domyślnie banner jest renderowany serwerowo
 * przez `partials/consent-banner.blade.php` (jako role="dialog" + ukryty
 * atrybutem `hidden` dopóki JS nie zdecyduje czy go pokazać).
 *
 * Flow:
 *  1) Page load → `app.js` woła `setConsentDefault()` (default-denied).
 *  2) Ten moduł odczytuje `localStorage.np_consent`. Jeśli wpis istnieje
 *     i nie wygasł (TTL 6 mies.) — od razu wywołuje `updateConsent(signals)`
 *     i NIE pokazuje bannera.
 *  3) Jeśli wpis brak / wygasł → banner staje się widoczny (atrybut `hidden`
 *     usunięty), focus przesunięty na pierwszy przycisk dialogu.
 *  4) Klik „Zmień zgody" w stopce (selektor `[data-np-consent-open]`) zawsze
 *     ponownie pokazuje banner.
 *
 * Crisis Hub (`[data-np-crisis-page]`) — banner NIE pojawia się
 * (privacy-by-default, zero trackingu na tej podstronie).
 */

import { CONSENT_KEYS, updateConsent } from './lib/track.js';

const STORAGE_KEY = 'np_consent';
const TTL_MS = 1000 * 60 * 60 * 24 * 30 * 6; // ~6 miesięcy

/**
 * @typedef {Object} ConsentDecision
 * @property {number} ts
 * @property {boolean} analytics
 * @property {boolean} ads
 * @property {boolean} ad_user_data
 * @property {boolean} ad_personalization
 */

function readDecision() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed.ts !== 'number') return null;
        if (Date.now() - parsed.ts > TTL_MS) return null;
        return parsed;
    } catch {
        return null;
    }
}

function writeDecision(signals) {
    const decision = { ts: Date.now() };
    for (const key of CONSENT_KEYS) {
        decision[key] = !!signals[key];
    }
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(decision));
    } catch {
        // quota exceeded / private mode → akceptujemy że banner pokaże się ponownie
    }
    return decision;
}

function applyDecision(decision) {
    const signals = {};
    for (const key of CONSENT_KEYS) signals[key] = !!decision[key];
    updateConsent(signals);
}

const ALL_GRANTED = Object.fromEntries(CONSENT_KEYS.map((k) => [k, true]));
const ALL_DENIED = Object.fromEntries(CONSENT_KEYS.map((k) => [k, false]));

let lastFocusedBeforeOpen = null;

function showBanner(banner) {
    if (!banner) return;
    lastFocusedBeforeOpen = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    banner.hidden = false;
    banner.setAttribute('aria-hidden', 'false');
    const firstBtn = banner.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (firstBtn instanceof HTMLElement) firstBtn.focus();
}

function hideBanner(banner) {
    if (!banner) return;
    banner.hidden = true;
    banner.setAttribute('aria-hidden', 'true');
    if (lastFocusedBeforeOpen && document.body.contains(lastFocusedBeforeOpen)) {
        try { lastFocusedBeforeOpen.focus(); } catch { /* noop */ }
    }
    lastFocusedBeforeOpen = null;
}

function trapFocus(banner, e) {
    if (e.key !== 'Tab') return;
    const focusables = banner.querySelectorAll(
        'button:not([disabled]), [href], input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    if (!focusables.length) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}

function readCheckboxState(banner) {
    const signals = {};
    for (const key of CONSENT_KEYS) {
        const cb = banner.querySelector(`input[type="checkbox"][data-consent="${key}"]`);
        signals[key] = cb instanceof HTMLInputElement ? cb.checked : false;
    }
    return signals;
}

function bind(banner) {
    const manageView = banner.querySelector('[data-consent-manage]');
    const acceptAllBtn = banner.querySelector('[data-consent-accept-all]');
    const rejectAllBtn = banner.querySelector('[data-consent-reject-all]');
    const manageBtn = banner.querySelector('[data-consent-show-manage]');
    const saveBtn = banner.querySelector('[data-consent-save]');

    function commit(signals) {
        const decision = writeDecision(signals);
        applyDecision(decision);
        hideBanner(banner);
    }

    acceptAllBtn?.addEventListener('click', () => commit(ALL_GRANTED));
    rejectAllBtn?.addEventListener('click', () => commit(ALL_DENIED));
    manageBtn?.addEventListener('click', () => {
        if (manageView) manageView.hidden = false;
        manageBtn.setAttribute('aria-expanded', 'true');
        const firstCb = manageView?.querySelector('input[type="checkbox"]');
        if (firstCb instanceof HTMLElement) firstCb.focus();
    });
    saveBtn?.addEventListener('click', () => commit(readCheckboxState(banner)));

    banner.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            // WCAG 2.1.2 No Keyboard Trap — Esc zawsze zamyka banner.
            // Pierwsza wizyta: traktujemy jak "Tylko niezbędne" (ALL_DENIED) — privacy-by-default,
            // GDPR compliant (brak zgody = brak trackingu).
            if (readDecision()) {
                hideBanner(banner);
            } else {
                commit(ALL_DENIED);
            }
            return;
        }
        trapFocus(banner, e);
    });

    // Hooki "Zmień zgody" w stopce / dowolnym miejscu
    document.querySelectorAll('[data-np-consent-open]').forEach((el) => {
        el.addEventListener('click', (e) => {
            e.preventDefault();
            // Pre-fill checkboxów ostatnio zapisaną decyzją
            const prev = readDecision();
            if (prev && manageView) {
                for (const key of CONSENT_KEYS) {
                    const cb = banner.querySelector(`input[type="checkbox"][data-consent="${key}"]`);
                    if (cb instanceof HTMLInputElement) cb.checked = !!prev[key];
                }
                manageView.hidden = false;
            }
            showBanner(banner);
        });
    });
}

function init() {
    // Crisis Hub — privacy-by-default
    if (document.querySelector('[data-np-crisis-page]')) return;

    const banner = document.querySelector('[data-np-consent-banner]');
    if (!banner) return;

    bind(banner);

    const decision = readDecision();
    if (decision) {
        applyDecision(decision);
        return; // banner pozostaje hidden — można otworzyć przez [data-np-consent-open]
    }

    showBanner(banner);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}

// Eksport dla testów / integracji
export { readDecision, writeDecision, STORAGE_KEY, TTL_MS };
