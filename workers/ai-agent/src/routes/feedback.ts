import type { Env } from '../types';

export async function handleFeedback(request: Request, env: Env): Promise<Response> {
    try {
        const body = await request.json<{ value: number; type?: string }>();
        const { value, type = 'conversation_rating' } = body;

        if (typeof value !== 'number' || value < 1 || value > 5) {
            return new Response(JSON.stringify({ error: 'Nieprawidłowa ocena' }), {
                status: 400, headers: { 'Content-Type': 'application/json' },
            });
        }

        console.log(JSON.stringify({ type, value, ts: Date.now() }));

        // Zapisz do WordPress (fire-and-forget)
        if (env.WP_API_URL && env.WP_BOT_TOKEN) {
            fetch(`${env.WP_API_URL}/bot-feedback`, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-API-Key':    env.WP_BOT_TOKEN,
                    'User-Agent':   'NiepodzielniBot/1.0',
                },
                body: JSON.stringify({ value, type }),
            }).catch(() => {});
        }

        return new Response(JSON.stringify({ ok: true }), {
            headers: { 'Content-Type': 'application/json' },
        });
    } catch {
        return new Response(JSON.stringify({ ok: false }), {
            status: 400, headers: { 'Content-Type': 'application/json' },
        });
    }
}
