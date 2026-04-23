const ALLOWED_ORIGINS = [
    'https://niepodzielni.pl',
    'https://www.niepodzielni.pl',
    'http://localhost:8000',
];

export function corsHeaders(origin: string | null): Record<string, string> {
    const allowed = origin && ALLOWED_ORIGINS.includes(origin) ? origin : ALLOWED_ORIGINS[0];
    return {
        'Access-Control-Allow-Origin':  allowed,
        'Access-Control-Allow-Methods': 'POST, GET, OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type, X-Worker-Secret',
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
