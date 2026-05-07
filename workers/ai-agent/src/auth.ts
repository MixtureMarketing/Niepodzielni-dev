// Stała-czasowa porównywarka stringów (zapobiega timing attacks).
function timingSafeEqual(a: string, b: string): boolean {
    if (a.length !== b.length) return false;
    let mismatch = 0;
    for (let i = 0; i < a.length; i++) {
        mismatch |= a.charCodeAt(i) ^ b.charCodeAt(i);
    }
    return mismatch === 0;
}

const JSON_HEADERS = { 'Content-Type': 'application/json' };

/**
 * Wymaga `Authorization: Bearer <expected>`. Zwraca null gdy OK,
 * inaczej Response 401 do natychmiastowego zwrócenia.
 */
export function requireBearer(request: Request, expected: string | undefined): Response | null {
    if (!expected) {
        return new Response(JSON.stringify({ error: 'server_misconfigured' }), {
            status: 500, headers: JSON_HEADERS,
        });
    }
    const header = request.headers.get('Authorization') ?? '';
    const match  = /^Bearer\s+(.+)$/i.exec(header);
    if (!match || !timingSafeEqual(match[1].trim(), expected)) {
        return new Response(JSON.stringify({ error: 'unauthorized' }), {
            status: 401, headers: JSON_HEADERS,
        });
    }
    return null;
}

/** Wariant header-based dla service-to-service (np. X-Worker-Secret). */
export function requireHeaderSecret(
    request: Request,
    headerName: string,
    expected: string | undefined,
): Response | null {
    if (!expected) {
        return new Response(JSON.stringify({ error: 'server_misconfigured' }), {
            status: 500, headers: JSON_HEADERS,
        });
    }
    const provided = request.headers.get(headerName) ?? '';
    if (!timingSafeEqual(provided, expected)) {
        return new Response(JSON.stringify({ error: 'unauthorized' }), {
            status: 401, headers: JSON_HEADERS,
        });
    }
    return null;
}
