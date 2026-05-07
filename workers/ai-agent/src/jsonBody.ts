import type { Validated } from './schemas';

export type ParseResult<T> =
    | { ok: true; value: T }
    | { ok: false; response: Response };

const JSON_HEADERS = { 'Content-Type': 'application/json' };

function errorResponse(status: number, error: string, details?: string): Response {
    const body: Record<string, string> = { error };
    if (details) body.details = details;
    return new Response(JSON.stringify(body), { status, headers: JSON_HEADERS });
}

export async function parseJsonBody<T>(
    request: Request,
    validator: (raw: unknown) => Validated<T>,
    maxBytes = 256 * 1024,
): Promise<ParseResult<T>> {
    const contentLength = Number(request.headers.get('Content-Length') ?? 0);
    if (contentLength > maxBytes) {
        return { ok: false, response: errorResponse(413, 'payload_too_large') };
    }

    let raw: unknown;
    try {
        raw = await request.json();
    } catch {
        return { ok: false, response: errorResponse(400, 'invalid_json') };
    }

    const result = validator(raw);
    if (!result.ok) {
        return { ok: false, response: errorResponse(400, 'invalid_payload', result.error) };
    }

    return { ok: true, value: result.value };
}
