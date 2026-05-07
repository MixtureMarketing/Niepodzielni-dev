import * as Sentry from '@sentry/cloudflare';
import type { Env } from './types';
import { handleSync }     from './routes/sync';
import { handleSearch }   from './routes/search';
import { handleChat }     from './routes/chat';
import { handleFeedback } from './routes/feedback';
import { handleOptions, withCors } from './cors';

const handler: ExportedHandler<Env> = {
    async fetch(request: Request, env: Env): Promise<Response> {
        if (request.method === 'OPTIONS') {
            return handleOptions(request);
        }

        const url      = new URL(request.url);
        const pathname = url.pathname;

        let response: Response;

        if (pathname === '/sync' && request.method === 'POST') {
            response = await handleSync(request, env);
        } else if (pathname === '/search' && request.method === 'GET') {
            response = await handleSearch(request, env);
        } else if (pathname === '/chat' && request.method === 'POST') {
            response = await handleChat(request, env);
        } else if (pathname === '/feedback' && request.method === 'POST') {
            response = await handleFeedback(request, env);
        } else if (pathname === '/' && request.method === 'GET') {
            response = new Response(JSON.stringify({ ok: true, service: 'niepodzielni-ai-agent' }), {
                headers: { 'Content-Type': 'application/json' },
            });
        } else {
            response = new Response(JSON.stringify({ error: 'not_found' }), {
                status: 404, headers: { 'Content-Type': 'application/json' },
            });
        }

        return withCors(response, request);
    },
};

// `withSentry` wczytuje DSN per-request z env, więc gdy SENTRY_DSN jest puste
// (lokalny dev, brak wgrania sekretu) — Sentry SDK robi no-op (zero narzutu).
//
// PII filtering: `beforeSend` wycina body POST i cookies z eventu — branża
// psychoterapii, więc ostrożnie z $_POST z formularzy kontaktowych.
export default Sentry.withSentry(
    (env) => ({
        dsn:              env.SENTRY_DSN || undefined,
        environment:      env.SENTRY_ENV || 'unknown',
        sampleRate:       1.0,
        tracesSampleRate: 0.1,
        beforeSend(event) {
            if (event.request) {
                delete event.request.data;
                delete event.request.cookies;
            }
            return event;
        },
    }),
    handler,
);
