import type { Env } from '../types';
import { embed } from '../embed';

export async function handleSearch(request: Request, env: Env): Promise<Response> {
    const url   = new URL(request.url);
    const query = url.searchParams.get('q')?.trim();

    if (!query || query.length < 2) {
        return new Response(JSON.stringify({ results: [] }), {
            headers: { 'Content-Type': 'application/json' },
        });
    }

    const vector  = await embed(env, query);
    const [rPsy, rFaq] = await Promise.all([
        env.VECTORIZE_PSY.query(vector, { topK: 5, returnMetadata: 'all' }),
        env.VECTORIZE_FAQ.query(vector, { topK: 3, returnMetadata: 'all' }),
    ]);

    const results = [
        ...rPsy.matches.map(m => ({ ...m.metadata, score: m.score, index: 'psy' })),
        ...rFaq.matches.map(m => ({ ...m.metadata, score: m.score, index: 'faq' })),
    ].filter(r => (r.score ?? 0) > 0.5)
     .sort((a, b) => (b.score ?? 0) - (a.score ?? 0));

    return new Response(JSON.stringify({ results }), {
        headers: { 'Content-Type': 'application/json' },
    });
}
