import type { Env } from '../types';
import { embed } from '../embed';
import { requireBearer } from '../auth';
import { rateLimit } from '../rateLimit';
import { validateSearchQuery } from '../schemas';

const JSON_HEADERS = { 'Content-Type': 'application/json' };
const SEARCH_CACHE_TTL = 600; // 10 min — query+filter musi się powtórzyć w tym oknie

// SHA-256 cache key — stabilna względem case'u i białych znaków po normalizacji.
// Klucz zawiera query (lower+trim) + type żeby `?q=foo&type=article` i `?q=foo`
// trafiały w różne wpisy.
async function searchCacheKey(query: string, type: string | undefined): Promise<string> {
    const norm = `${query.trim().toLowerCase()}|${type ?? ''}`;
    const buf  = new TextEncoder().encode(norm);
    const hash = await crypto.subtle.digest('SHA-256', buf);
    const hex  = [...new Uint8Array(hash)].map(b => b.toString(16).padStart(2, '0')).join('');
    return `search:${hex}`;
}

export async function handleSearch(request: Request, env: Env): Promise<Response> {
    const unauth = requireBearer(request, env.NP_AI_BOT_TOKEN);
    if (unauth) return unauth;

    // B14: 30 req/min/IP — bardziej konserwatywne niż chat (search jest publiczny
    // bez UX'u czatu, więc łatwiej wykryć i zablokować nadużycia).
    const limited = await rateLimit(request, env, { bucket: 'search', limit: 30, windowSec: 60 });
    if (limited) return limited;

    const requestUrl = new URL(request.url);
    const validated  = validateSearchQuery(requestUrl);
    if (!validated.ok) {
        return new Response(JSON.stringify({ error: 'invalid_query', details: validated.error }), {
            status: 400, headers: JSON_HEADERS,
        });
    }
    const { query, type } = validated.value;

    if (query.length < 2) {
        return new Response(JSON.stringify({ results: [] }), { headers: JSON_HEADERS });
    }

    // ── KV cache (B5): /search jest deterministyczne (vector → topK) per query+type.
    //    TTL 600s równoważy świeżość kontentu (sync hookach z WP) i koszt
    //    Vectorize (~1ms ale liczne wywołania pod /search/api z autocomplete).
    const cacheKey = env.AI_SEARCH_CACHE ? await searchCacheKey(query, type) : null;
    if (env.AI_SEARCH_CACHE && cacheKey) {
        const hit = await env.AI_SEARCH_CACHE.get(cacheKey);
        if (hit) {
            return new Response(hit, {
                headers: { ...JSON_HEADERS, 'X-Cache': 'HIT' },
            });
        }
    }

    const vector = await embed(env, query);

    const filter = type ? { type: { $eq: type } } : undefined;

    let results: Array<Record<string, unknown>> = [];

    if (env.VECTORIZE_KNOWLEDGE) {
        const r = await env.VECTORIZE_KNOWLEDGE.query(vector, {
            topK: 8,
            returnMetadata: 'all',
            ...(filter ? { filter } : {}),
        });
        results = r.matches
            .filter(m => (m.score ?? 0) > 0.48)
            .map(m => ({ ...m.metadata, score: m.score }));
    }

    // Fallback do legacy jeśli KNOWLEDGE_BASE jeszcze nie ma danych
    if (results.length === 0) {
        const [rPsy, rFaq] = await Promise.all([
            env.VECTORIZE_PSY.query(vector, { topK: 5, returnMetadata: 'all' }),
            env.VECTORIZE_FAQ.query(vector, { topK: 3, returnMetadata: 'all' }),
        ]);
        results = [
            ...rPsy.matches.map(m => ({ ...m.metadata, score: m.score })),
            ...rFaq.matches.map(m => ({ ...m.metadata, score: m.score })),
        ].filter(r => (r.score ?? 0) > 0.5)
         .sort((a, b) => ((b.score as number) ?? 0) - ((a.score as number) ?? 0));
    }

    const body = JSON.stringify({ results });

    // Fire-and-forget cache write — nie blokuj response.
    if (env.AI_SEARCH_CACHE && cacheKey) {
        env.AI_SEARCH_CACHE.put(cacheKey, body, { expirationTtl: SEARCH_CACHE_TTL })
            .catch(() => {});
    }

    return new Response(body, {
        headers: { ...JSON_HEADERS, 'X-Cache': 'MISS' },
    });
}
