const ALLOWED_ORIGINS = [
    'https://new.niepodzielni.com',  // produkcja (canonical)
    'https://niepodzielni.pl',       // legacy
    'https://www.niepodzielni.pl',
    'https://dev.niepodzielni.com',  // staging/dev
    'http://localhost:8000',         // docker dev
];

export function corsHeaders(origin: string | null): Record<string, string> {
    const allowed = origin && ALLOWED_ORIGINS.includes(origin) ? origin : ALLOWED_ORIGINS[0];
    return {
        'Access-Control-Allow-Origin':  allowed,
        'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
        // Authorization: Bearer NP_AI_BOT_TOKEN (PR #7) dla /chat /search /feedback.
        // X-Worker-Secret: dla /sync (WP→Worker).
        'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Worker-Secret',
        'Access-Control-Max-Age':       '86400',
    };
}

export function handleOptions(request: Request): Response {
    const origin = request.headers.get('Origin');
    return new Response(null, { status: 204, headers: corsHeaders(origin) });
}

export function withCors(response: Response, request: Request): Response {
    const origin  = request.headers.get('Origin');
    const headers = new Headers(response.headers);
    for (const [k, v] of Object.entries(corsHeaders(origin))) {
        headers.set(k, v);
    }
    return new Response(response.body, { status: response.status, headers });
}
