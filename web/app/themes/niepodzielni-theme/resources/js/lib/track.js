/**
 * lib/track.js
 *
 * Wspólny helper trackingu dla Niepodzielni.
 * Primary kanał: Cloudflare Zaraz (zaraz.track) → server-side, edge.
 * Fallback: window.dataLayer (GTM compat) i navigator.sendBeacon
 * dla eventów krytycznych (gdy Zaraz niedostępny + zamykana karta).
 *
 * Dodatkowo:
 *  - automatyczny event_id (UUID v4) — wymagany do deduplikacji Meta CAPI
 *  - automatyczny timestamp (epoch ms)
 *  - integracja z Zaraz Consent Manager API (setConsentDefault / updateConsent)
 *
 * UWAGA: nie używać na /pomoc-w-kryzysie/ (zero trackingu — privacy).
 */

const CRITICAL_EVENTS = new Set(['purchase', 'donation', 'generate_lead']);

/**
 * Eventy wysyłane dodatkowo do mu-plugin np-conversion-api (Server-to-Server):
 * GA4 Measurement Protocol + Meta CAPI. event_id wspólny z client-side Zaraz
 * → Meta deduplikuje. Lepsza atrybucja gdy klient blokuje JS / 3rd-party.
 *
 * UWAGA: Crisis Hub strony — zero S2S, zero PII. Lista jest hard-coded niżej.
 */
const S2S_EVENTS = new Set(['purchase', 'generate_lead', 'sign_up']);

/**
 * Wysyła event do REST endpointu /wp-json/np/v1/track (S2S Conversion API).
 * Używa sendBeacon (nieblokujące, działa przy zamykaniu karty); fallback fetch.
 *
 * @param {string} name
 * @param {Object} enriched  Event z event_id + timestamp.
 */
function sendS2S(name, enriched) {
    if (!S2S_EVENTS.has(name)) return;
    // Crisis Hub blacklist — zero S2S na tych stronach (privacy).
    if (typeof window !== 'undefined' && /\/pomoc-w-kryzysie/.test(window.location?.pathname || '')) {
        return;
    }
    const cfg = window.NP_S2S;
    if (!cfg || !cfg.url || !cfg.nonce) return;

    const { event_id, timestamp, user_data, ...customData } = enriched;
    const payload = {
        event_name: name,
        event_id,
        user_data: user_data || {},
        custom_data: { ...customData, timestamp },
        source_url: window.location?.href || '',
    };

    try {
        const body = JSON.stringify(payload);
        // sendBeacon nie pozwala ustawić nagłówków → nonce w URL query.
        if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
            const url = cfg.url + (cfg.url.includes('?') ? '&' : '?') + '_wpnonce=' + encodeURIComponent(cfg.nonce);
            const blob = new Blob([body], { type: 'application/json' });
            if (navigator.sendBeacon(url, blob)) {
                return;
            }
        }
        // Fallback: fetch keepalive z X-WP-Nonce.
        if (typeof fetch === 'function') {
            fetch(cfg.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cfg.nonce,
                },
                body,
                keepalive: true,
                credentials: 'same-origin',
            }).catch(() => {
                // noop — S2S to redundancja, błąd nie jest krytyczny
            });
        }
    } catch {
        // noop
    }
}

function uuidv4() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    // RFC4122 v4 fallback
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

/**
 * Wysyła event przez najlepszy dostępny kanał.
 * @param {string} name  Nazwa eventu (preferujemy GA4 standard names).
 * @param {Object} props Właściwości eventu.
 */
export function npTrack(name, props = {}) {
    const enriched = {
        event_id: props.event_id || uuidv4(),
        timestamp: props.timestamp || Date.now(),
        ...props,
    };

    // S2S Conversion API — równolegle z Zaraz (deduplikacja przez event_id).
    // Tylko dla krytycznych eventów (purchase / generate_lead / sign_up).
    sendS2S(name, enriched);

    if (window.zaraz && typeof window.zaraz.track === 'function') {
        try {
            window.zaraz.track(name, enriched);
            return;
        } catch {
            // fallthrough do dataLayer / beacon
        }
    }

    if (Array.isArray(window.dataLayer) || window.dataLayer) {
        try {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ event: name, ...enriched });
            return;
        } catch {
            // fallthrough
        }
    }

    // Krytyczne eventy: ostatnia szansa przez sendBeacon (np. zamykana karta)
    // Endpoint /__np_track to placeholder — Zaraz zwykle przechwytuje wszystko
    // przez injected script; ścieżka beacon ma sens tylko jeśli stworzymy mu-plugin
    // odbierający eventy. Brak endpointu == cichy noop (akceptowalne).
    if (CRITICAL_EVENTS.has(name) && typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
        try {
            const payload = JSON.stringify({ event: name, ...enriched });
            const blob = new Blob([payload], { type: 'application/json' });
            navigator.sendBeacon('/__np_track', blob);
        } catch {
            // noop
        }
    }
}

/**
 * Kontekst strony — używany jako wspólne props dla eventów Bookero/AI/listing.
 */
export function getPageContext() {
    const body = document.body;
    const className = body ? body.className : '';
    const postIdMatch = className.match(/postid-(\d+)/);
    const urlParams = new URLSearchParams(window.location.search);
    const consultType = urlParams.get('konsultacje') || 'pelno';
    const nameEl = document.querySelector('.psy-name-h1');

    // page_type: pierwszy z body class którego pasuje do template-* lub single-*
    let pageType = null;
    if (className) {
        const m = className.match(/(page-template-[\w-]+|single-[\w-]+|page-id-\d+)/);
        if (m) pageType = m[1];
    }

    return {
        postId: postIdMatch ? parseInt(postIdMatch[1], 10) : null,
        consultType,
        psychName: nameEl ? nameEl.textContent.trim() : null,
        user_id: typeof window.NP_USER_ID !== 'undefined' && window.NP_USER_ID ? window.NP_USER_ID : null,
        page_type: pageType,
    };
}

/**
 * Klucze Consent Mode v2 — semantyczne nazwy używane w kodzie i localStorage.
 * Zaraz UI generuje losowe 4-znakowe purposeIds; mapujemy semantyczne klucze
 * na faktyczne ID skonfigurowane w dashboardzie (Zaraz → Consent → Purposes).
 * Aktualizacja mapy: po zmianie purposeIds w Zaraz UI, podmień wartości tutaj.
 */
export const CONSENT_KEYS = ['analytics', 'ads', 'ad_user_data', 'ad_personalization'];

const ZARAZ_PURPOSE_IDS = {
    analytics:          'aBcw',
    ads:                'nxsi',
    ad_user_data:       'NBMn',
    ad_personalization: 'nTod',
};

/**
 * Default-denied — wywoływać przed pierwszym npTrack().
 * Bez argumentu ustawia wszystkie znane purposeIds na `false`.
 * Z argumentem (np. po wczytaniu z localStorage) re-stosuje wcześniejsze
 * decyzje użytkownika jeszcze przed inicjalizacją Zaraz.
 *
 * @param {Partial<Record<typeof CONSENT_KEYS[number], boolean>>} [signals]
 */
export function setConsentDefault(signals) {
    try {
        const consent = window.zaraz?.consent;
        // Fallback: pełny default-deny gdy brak sygnałów lub brak API set()
        if (!signals) {
            consent?.setAll?.(false);
            return;
        }
        if (typeof consent?.set === 'function') {
            const payload = {};
            for (const key of CONSENT_KEYS) {
                if (typeof signals[key] === 'boolean') {
                    payload[ZARAZ_PURPOSE_IDS[key]] = signals[key];
                }
            }
            if (Object.keys(payload).length) consent.set(payload);
        } else {
            consent?.setAll?.(false);
        }
    } catch {
        // noop — CM może być nieaktywny w dashboardzie
    }
}

/**
 * Aktualizacja sygnałów zgody (Consent Mode v2). Akceptuje dowolny podzbiór
 * z {analytics, ads, ad_user_data, ad_personalization}.
 * Po update wywołuje `sendQueuedEvents()` żeby Zaraz dosłał kolejkowane eventy.
 *
 * @param {Partial<Record<typeof CONSENT_KEYS[number], boolean>>} signals
 */
export function updateConsent(signals = {}) {
    try {
        const consent = window.zaraz?.consent;
        if (!consent || typeof consent.set !== 'function') return;
        const payload = {};
        for (const key of CONSENT_KEYS) {
            if (typeof signals[key] === 'boolean') {
                payload[ZARAZ_PURPOSE_IDS[key]] = signals[key];
            }
        }
        if (Object.keys(payload).length) consent.set(payload);
        if (typeof consent.sendQueuedEvents === 'function') {
            consent.sendQueuedEvents();
        }
    } catch {
        // noop
    }
}
