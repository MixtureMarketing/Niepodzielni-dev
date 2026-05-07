/**
 * Testy persystencji decyzji CMP w localStorage.
 * Zakres: TTL (6 mies.), kształt JSON, wszystkie 4 sygnały Consent Mode v2.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

// Lekki shim localStorage dla środowiska Node
class MemStorage {
    constructor() { this.store = new Map(); }
    getItem(k) { return this.store.has(k) ? this.store.get(k) : null; }
    setItem(k, v) { this.store.set(k, String(v)); }
    removeItem(k) { this.store.delete(k); }
    clear() { this.store.clear(); }
}

function withDom() {
    globalThis.localStorage = new MemStorage();
    globalThis.document = {
        readyState: 'complete',
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener: () => {},
        body: { contains: () => false },
        activeElement: null,
    };
    globalThis.window = globalThis;
}

describe('consent-banner persistence', () => {
    beforeEach(async () => {
        vi.resetModules();
        withDom();
    });

    it('readDecision zwraca null gdy localStorage pusty', async () => {
        const mod = await import('../consent-banner.js');
        expect(mod.readDecision()).toBeNull();
    });

    it('writeDecision zapisuje wszystkie 4 klucze + ts', async () => {
        const mod = await import('../consent-banner.js');
        const out = mod.writeDecision({
            analytics: true,
            ads: false,
            ad_user_data: true,
            ad_personalization: false,
        });
        expect(out.analytics).toBe(true);
        expect(out.ads).toBe(false);
        expect(out.ad_user_data).toBe(true);
        expect(out.ad_personalization).toBe(false);
        expect(typeof out.ts).toBe('number');

        const raw = JSON.parse(localStorage.getItem('np_consent'));
        expect(raw.analytics).toBe(true);
        expect(raw.ad_user_data).toBe(true);
    });

    it('readDecision zwraca null gdy wpis starszy niż TTL (6 mies.)', async () => {
        const mod = await import('../consent-banner.js');
        const stale = {
            ts: Date.now() - mod.TTL_MS - 1000,
            analytics: true, ads: true, ad_user_data: true, ad_personalization: true,
        };
        localStorage.setItem('np_consent', JSON.stringify(stale));
        expect(mod.readDecision()).toBeNull();
    });

    it('readDecision zwraca obiekt gdy wpis świeży', async () => {
        const mod = await import('../consent-banner.js');
        mod.writeDecision({ analytics: true, ads: false, ad_user_data: false, ad_personalization: false });
        const dec = mod.readDecision();
        expect(dec).not.toBeNull();
        expect(dec.analytics).toBe(true);
        expect(dec.ads).toBe(false);
    });

    it('writeDecision wymusza boolean (truthy/falsy → true/false)', async () => {
        const mod = await import('../consent-banner.js');
        const out = mod.writeDecision({ analytics: 1, ads: 0, ad_user_data: 'yes', ad_personalization: null });
        expect(out.analytics).toBe(true);
        expect(out.ads).toBe(false);
        expect(out.ad_user_data).toBe(true);
        expect(out.ad_personalization).toBe(false);
    });

    it('STORAGE_KEY === "np_consent" (kontrakt z app.js)', async () => {
        const mod = await import('../consent-banner.js');
        expect(mod.STORAGE_KEY).toBe('np_consent');
    });
});
