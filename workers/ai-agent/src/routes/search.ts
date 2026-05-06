import type { Env } from '../types';
import { embed } from '../embed';
import { requireBearer } from '../auth';
import { rateLimit } from '../rateLimit';
import { validateSearchQuery } from '../schemas';

const JSON_HEADERS = { 'Content-Type': 'application/json' };

export async function handleSearch(request: Request, env: Env): Promise<Response> {
    const unauth = requireBearer(request, env.NP_AI_BOT_TOKEN);
    if (unauth) return unauth;

    const limited = await rateLimit(request, env, { bucket: 'search', limit: 60, windowSec: 60 });
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

    return new Response(JSON.stringify({ results }), { headers: JSON_HEADERS });
}
