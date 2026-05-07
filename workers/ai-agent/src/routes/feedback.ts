import type { Env } from '../types';
import { requireBearer } from '../auth';
import { rateLimit } from '../rateLimit';
import { parseJsonBody } from '../jsonBody';
import { validateFeedback } from '../schemas';
import { fetchWithTimeout } from '../fetchWithTimeout';

export async function handleFeedback(request: Request, env: Env): Promise<Response> {
    const unauth = requireBearer(request, env.NP_AI_BOT_TOKEN);
    if (unauth) return unauth;

    const limited = await rateLimit(request, env, { bucket: 'feedback', limit: 30, windowSec: 60 });
    if (limited) return limited;

    const parsed = await parseJsonBody(request, validateFeedback, 4 * 1024);
    if (!parsed.ok) return parsed.response;
    const { value, type = 'conversation_rating' } = parsed.value;

    console.log(JSON.stringify({ event: 'bot_feedback', type, value, ts: Date.now() }));

    // Zapisz do WordPress (fire-and-forget)
    if (env.WP_API_URL && env.WP_BOT_TOKEN) {
        fetchWithTimeout(`${env.WP_API_URL}/bot-feedback`, {
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
}
