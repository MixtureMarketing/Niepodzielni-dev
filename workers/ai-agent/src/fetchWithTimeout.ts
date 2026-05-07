// Hard cap dla wszystkich wywołań fetch do upstreamów (OpenAI/Anthropic via AI Gateway,
// WordPress REST). 30s pokrywa generację LLM ze sporym marginesem; po tym czasie
// obrywamy AbortError zamiast wisieć w nieskończoność i blokować subrequest budget.
//
// Workers runtime ma własny global timeout (30s subrequest na free, 6 minut na paid),
// ale na poziomie aplikacyjnym chcemy KONKRETNĄ wartość żeby:
//  - mieć przewidywalne komunikaty błędów (AbortError → user-friendly fallback),
//  - chronić streaming endpointy (chat) przed wiszącym TCP gdy upstream zaczyna stream
//    ale nigdy go nie kończy.
//
// Użycie:  fetchWithTimeout(url, { ... })  ←  drop-in replacement.

const DEFAULT_TIMEOUT_MS = 30_000;

export function fetchWithTimeout(
    input: RequestInfo | URL,
    init: RequestInit = {},
    timeoutMs: number = DEFAULT_TIMEOUT_MS,
): Promise<Response> {
    // Jeśli caller już dostarczył signal — łączymy je przez AbortSignal.any.
    const timeoutSignal = AbortSignal.timeout(timeoutMs);
    const signal = init.signal
        ? AbortSignal.any([init.signal, timeoutSignal])
        : timeoutSignal;
    return fetch(input, { ...init, signal });
}
