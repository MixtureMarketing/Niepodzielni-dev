import type { Env } from './types';
import { handleSync }   from './routes/sync';
import { handleSearch } from './routes/search';
import { handleChat }   from './routes/chat';
import { handleOptions, withCors } from './cors';

export default {
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
        } else {
            response = new Response(JSON.stringify({ ok: true, service: 'niepodzielni-ai-agent' }), {
                headers: { 'Content-Type': 'application/json' },
            });
        }

        return withCors(response, request);
    },
};
