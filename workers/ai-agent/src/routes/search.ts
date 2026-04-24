import type { Env } from '../types';
import { embed } from '../embed';

export async function handleSearch(request: Request, env: Env): Promise<Response> {
    const url   = new URL(request.url);
    const query = url.searchParams.get('q')?.trim();
    const type  = url.searchParams.get('type'); // opcjonalny filtr: psycholog, article, faq…

    if (!query || query.length < 2) {
        return new Response(JSON.stringify({ results: [] }), {
            headers: { 'Content-Type': 'application/json' },
        });
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

    return new Response(JSON.stringify({ results }), {
        headers: { 'Content-Type': 'application/json' },
    });
}
