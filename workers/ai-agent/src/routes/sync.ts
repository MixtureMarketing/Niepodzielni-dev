import type { Env } from '../types';
import { embed, buildText } from '../embed';
import { requireHeaderSecret } from '../auth';
import { parseJsonBody } from '../jsonBody';
import { validateSyncPayload } from '../schemas';

export async function handleSync(request: Request, env: Env): Promise<Response> {
    const unauth = requireHeaderSecret(request, 'X-Worker-Secret', env.WORKER_SECRET);
    if (unauth) return unauth;

    const parsed = await parseJsonBody(request, validateSyncPayload, 512 * 1024);
    if (!parsed.ok) return parsed.response;
    const payload = parsed.value;

    const { id, type, title, url, status } = payload;
    const photo_url = payload.photo_url ?? '';

    const text   = buildText({ title, content: payload.content, meta: payload.meta });
    const vector = await embed(env, text);

    // Flatten meta → specializations (dla psychologów)
    const flatMeta = payload.meta
        ? Object.values(payload.meta).flat().filter(Boolean).join(', ')
        : '';

    // Flatten tags (dla artykułów, warsztatów, grup)
    const flatTags = payload.tags?.filter(Boolean).join(', ') ?? '';

    // ReDoS guard: capujemy input zanim trafi do regex'a (CodeQL js/polynomial-redos).
    const contentSnippet = payload.content
        ? payload.content.slice(0, 50_000).replace(/<[^>]*>/g, ' ').split(/\s+/).filter(Boolean).join(' ').slice(0, 200)
        : '';

    const sharedMetadata = {
        id, type, title, url, photo_url,
        ...(flatMeta        ? { specializations: flatMeta }        : {}),
        ...(flatTags        ? { tags: flatTags }                   : {}),
        ...(payload.event_date ? { event_date: payload.event_date } : {}),
        ...(contentSnippet  ? { content_snippet: contentSnippet }  : {}),
        ...(status          ? { status }                           : { status: 'active' }),
    } as Record<string, VectorizeVectorMetadataValue>;

    // ── Nowy unified index (primary) ─────────────────────────────────────────
    await env.VECTORIZE_KNOWLEDGE.upsert([{
        id:       String(id),
        values:   vector,
        metadata: sharedMetadata,
    }]);

    // ── Legacy mirror (backward compat podczas migracji) ─────────────────────
    if (type === 'psycholog') {
        await env.VECTORIZE_PSY.upsert([{
            id:       String(id),
            values:   vector,
            metadata: sharedMetadata,
        }]);
    } else if (type === 'faq') {
        await env.VECTORIZE_FAQ.upsert([{
            id:       String(id),
            values:   vector,
            metadata: sharedMetadata,
        }]);
    }

    return new Response(JSON.stringify({ ok: true, id, type }), {
        headers: { 'Content-Type': 'application/json' },
    });
}
