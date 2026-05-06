// Rate limiter oparty o KV namespace (Workers KV).  Jeśli `RATE_LIMIT` nie jest
// powiązane (lokalny dev, błąd konfiguracji), limiter jest pomijany i loguje
// jednorazowe ostrzeżenie — handler nadal działa.
//
// Sliding-fixed-window: licznik incrementowany per (key|bucket) gdzie bucket =
// floor(now / windowSec).  KV TTL = 2*windowSec (auto-cleanup).

import type { Env } from './types';

const JSON_HEADERS = { 'Content-Type': 'application/json' };

let warned = false;

function clientId(request: Request): string {
    return (
        request.headers.get('CF-Connecting-IP') ??
        request.headers.get('X-Forwarded-For')?.split(',')[0]?.trim() ??
        'unknown'
    );
}

export interface RateLimitOptions {
    bucket:    string;  // np. 'chat', 'search'
    limit:     number;  // ile requestów w oknie
    windowSec: number;  // długość okna w sekundach
}

/**
 * Zwraca null jeśli OK, lub Response 429 z `Retry-After`.
 * Atomicność: KV nie ma operacji incr — używamy GET+PUT.  Pod ciężkim ruchem
 * możliwy drobny under-count (race), ale dla protekcji per-IP wystarczająco.
 * Dla ścisłej atomicności — Durable Object.
 */
export async function rateLimit(
    request: Request,
    env: Env,
    opts: RateLimitOptions,
): Promise<Response | null> {
    const kv = env.RATE_LIMIT;
    if (!kv) {
        if (!warned) {
            console.warn('[rateLimit] RATE_LIMIT KV namespace not bound; skipping limiter');
            warned = true;
        }
        return null;
    }

    const ip       = clientId(request);
    const bucketTs = Math.floor(Date.now() / 1000 / opts.windowSec);
    const key      = `rl:${opts.bucket}:${ip}:${bucketTs}`;

    const current = parseInt((await kv.get(key)) ?? '0', 10);

    if (current >= opts.limit) {
        const retryAfter = opts.windowSec - (Math.floor(Date.now() / 1000) % opts.windowSec);
        return new Response(JSON.stringify({ error: 'rate_limited' }), {
            status: 429,
            headers: { ...JSON_HEADERS, 'Retry-After': String(Math.max(1, retryAfter)) },
        });
    }

    await kv.put(key, String(current + 1), { expirationTtl: opts.windowSec * 2 });
    return null;
}
