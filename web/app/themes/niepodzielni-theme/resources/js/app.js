import.meta.glob([
  '../images/**',
  '../fonts/**',
]);

// Tracking foundation — ładowane jak najwcześniej, żeby consent default
// (denied) został ustawiony zanim cokolwiek wystartuje npTrack().
// Jeśli użytkownik podjął już decyzję (localStorage `np_consent` z TTL 6 mies.),
// stosujemy ją od razu — to upraszcza Zaraz Consent Manager (mniej kolejkowania
// eventów) i daje pełen Consent Mode v2 default-state przed pierwszym hitem GA4.
import { setConsentDefault, CONSENT_KEYS } from './lib/track.js';
(() => {
    const fallback = Object.fromEntries(CONSENT_KEYS.map((k) => [k, false]));
    try {
        const raw = typeof localStorage !== 'undefined' ? localStorage.getItem('np_consent') : null;
        if (raw) {
            const parsed = JSON.parse(raw);
            const TTL = 1000 * 60 * 60 * 24 * 30 * 6;
            if (parsed && typeof parsed.ts === 'number' && Date.now() - parsed.ts <= TTL) {
                const signals = {};
                for (const k of CONSENT_KEYS) signals[k] = !!parsed[k];
                setConsentDefault(signals);
                return;
            }
        }
    } catch {
        // noop — fallback poniżej
    }
    setConsentDefault(fallback);
})();

import './components/slider.js';
import './components/dynamic-content.js';
import './components/appointment-widget.js';
import './custom-accordion.js';
import './mega-menu.js';
import './tabs.js';
// Defer chat widget load until the main thread is idle — creates a separate Vite chunk
// that doesn't block critical rendering. Falls back to 1s timeout on unsupported browsers.
if ('requestIdleCallback' in window) {
    requestIdleCallback(() => import('./components/ai-chat.js'), { timeout: 3000 });
} else {
    setTimeout(() => import('./components/ai-chat.js'), 1000);
}
