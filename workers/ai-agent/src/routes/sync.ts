import type { Env, SyncPayload } from '../types';
import { embed, buildText } from '../embed';

export async function handleSync(request: Request, env: Env): Promise<Response> {
    const secret = request.headers.get('X-Worker-Secret');
    if (secret !== env.WORKER_SECRET) {
        return new Response('Unauthorized', { status: 401 });
    }

    const payload = await request.json<SyncPayload>();
    const { id, type, title, url, meta } = payload;
    const photo_url = payload.photo_url ?? '';

    const text   = buildText({ title, content: payload.content, meta });
    const vector = await embed(env, text);
    const index  = type === 'faq' ? env.VECTORIZE_FAQ : env.VECTORIZE_PSY;

    const flatMeta = payload.meta
        ? Object.values(payload.meta).flat().filter(Boolean).join(', ')
        : '';

    await index.upsert([{
        id:       String(id),
        values:   vector,
        metadata: {
            id, type, title, url, photo_url,
            ...(flatMeta ? { specializations: flatMeta } : {}),
        } as Record<string, VectorizeVectorMetadataValue>,
    }]);

    return new Response(JSON.stringify({ ok: true, id, type }), {
        headers: { 'Content-Type': 'application/json' },
    });
}
