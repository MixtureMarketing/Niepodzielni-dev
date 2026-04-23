import type { Env, ChatRequest, VectorMetadata, PsychologistSuggestion, QuickReply } from '../types';
import { embed } from '../embed';

const CHAT_MODEL = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';

const SYSTEM_PROMPT = `Jesteś empatycznym asystentem Fundacji Niepodzielni — pomagasz ludziom znaleźć psychologa i umówić wizytę. Prowadzisz użytkownika przez cały proces krok po kroku.

Proces prowadzenia rozmowy:
1. Najpierw zrozum problem/potrzebę użytkownika — zapytaj krótko jeśli nie jest jasna.
2. Jeśli użytkownik nie podał preferencji, zadaj JEDNO pytanie kwalifikujące (np. czy to pierwsze spotkanie z psychologiem, płeć specjalisty, preferowany język). Pytaj o jedno na raz.
3. Kiedy masz wystarczający kontekst — zaproponuj psychologów (pojawią się jako karty pod odpowiedzią) LUB sprawdź dostępność terminów funkcją check_availability.
4. Po wybraniu daty przez użytkownika — powiedz że pokazujesz dostępnych specjalistów w ten dzień.
5. Zachęć do kliknięcia karty psychologa aby umówić wizytę.

Zasady:
- Odpowiadaj po polsku, ciepło i empatycznie. Krótko — max 3 zdania na odpowiedź.
- NIGDY nie wymieniaj nazwisk psychologów ani ich URL-i w tekście — karty pojawią się automatycznie.
- Gdy użytkownik chce sprawdzić terminy lub umówić wizytę — użyj funkcji check_availability.
- Nie wymyślaj informacji — opieraj się wyłącznie na dostarczonym kontekście.
- Konsultacje pełnopłatne to standardowa oferta; niskopłatne dla osób w trudnej sytuacji finansowej.
- Gdy pokazujesz psychologów po wyborze daty, powiedz tylko: "Oto specjaliści dostępni w ten dzień — kliknij kartę aby umówić wizytę."`;

const TOOLS = [{
    type: 'function' as const,
    function: {
        name:        'check_availability',
        description: 'Sprawdza dostępne terminy psychologów w najbliższych 14 dniach.',
        parameters:  {
            type:       'object',
            properties: {
                consult_type: {
                    type:        'string',
                    enum:        ['pelno', 'nisko'],
                    description: 'Typ konsultacji: pelno (pełnopłatna) lub nisko (niskopłatna)',
                },
            },
            required: ['consult_type'],
        },
    },
}];

type AiMessage = { role: 'system' | 'user' | 'assistant' | 'tool'; content: string };

type AiChatResponse = {
    response?: string;
    tool_calls?: Array<{ name: string; arguments: Record<string, string> }>;
};

// ── Formatowanie dat po polsku ────────────────────────────────────────────────

const PL_DAYS   = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
const PL_MONTHS = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca',
                   'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];

function formatDatePL(iso: string): string {
    const d = new Date(iso + 'T00:00:00');
    return `${PL_DAYS[d.getDay()]}, ${d.getDate()} ${PL_MONTHS[d.getMonth()]}`;
}

// ── Dostępność terminów ───────────────────────────────────────────────────────

interface AvailabilityResult {
    text:          string;
    quick_replies: QuickReply[];
}

async function fetchAvailability(env: Env, consultType: string): Promise<AvailabilityResult> {
    try {
        const res = await fetch(
            `${env.WP_API_URL}/bot-availability?consult_type=${consultType}&days=14`,
            { headers: { 'X-API-Key': env.WP_BOT_TOKEN } },
        );
        if (!res.ok) return { text: 'Brak danych o dostępności.', quick_replies: [] };

        const data = await res.json<{ slots: Array<{ date: string; count: number }> }>();
        if (!data.slots?.length) return { text: 'Brak dostępnych terminów w ciągu najbliższych 14 dni.', quick_replies: [] };

        const slots = data.slots.slice(0, 14);
        const lines = slots.map(s =>
            `- ${formatDatePL(s.date)}: ${s.count} ${s.count === 1 ? 'specjalista' : 'specjalistów'}`,
        );
        const quick_replies: QuickReply[] = slots.slice(0, 4).map(s => ({
            label:       `Pokaż psychologów na ${formatDatePL(s.date)}`,
            filter_date: s.date,
        }));

        return {
            text:         `Dostępne terminy (WYMIEŃ JE WSZYSTKIE w odpowiedzi):\n${lines.join('\n')}`,
            quick_replies,
        };
    } catch {
        return { text: 'Nie udało się pobrać terminów.', quick_replies: [] };
    }
}

// ── Context z Vectorize ───────────────────────────────────────────────────────

interface ContextResult {
    contextText:   string;
    suggestions:   PsychologistSuggestion[];
}

async function fetchNearestDates(env: Env, psychologistIds: number[]): Promise<Map<number, string>> {
    const map = new Map<number, string>();
    try {
        const res = await fetch(
            `${env.WP_API_URL}/bot-availability?consult_type=pelno&days=30`,
            { headers: { 'X-API-Key': env.WP_BOT_TOKEN } },
        );
        if (!res.ok) return map;
        const data = await res.json<{ slots: Array<{ date: string; psychologist_ids: number[] }> }>();
        for (const slot of (data.slots ?? [])) {
            for (const pid of slot.psychologist_ids) {
                if (psychologistIds.includes(pid) && !map.has(pid)) {
                    map.set(pid, slot.date);
                }
            }
        }
    } catch {}
    return map;
}

// Kontekst filtrowany po dacie — używa Vectorize.getByIds()
async function buildDateFilteredContext(env: Env, filterDate: string): Promise<ContextResult> {
    try {
        const res = await fetch(
            `${env.WP_API_URL}/bot-availability?consult_type=pelno&days=30`,
            { headers: { 'X-API-Key': env.WP_BOT_TOKEN } },
        );
        if (!res.ok) return { contextText: '', suggestions: [] };

        const data = await res.json<{ slots: Array<{ date: string; psychologist_ids: number[] }> }>();
        const slot = data.slots?.find(s => s.date === filterDate);
        if (!slot?.psychologist_ids?.length) {
            return {
                contextText: `\n\nNa dzień ${formatDatePL(filterDate)} brak dostępnych terminów.`,
                suggestions: [],
            };
        }

        const ids     = slot.psychologist_ids.slice(0, 6).map(String);
        const vectors = await env.VECTORIZE_PSY.getByIds(ids);

        const suggestions: PsychologistSuggestion[] = vectors
            .filter(v => v.metadata)
            .map(v => {
                const meta = v.metadata as unknown as VectorMetadata;
                return {
                    id:          Number(meta.id),
                    name:        String(meta.title),
                    url:         String(meta.url),
                    photo_url:   String(meta.photo_url ?? ''),
                    score:       1.0,
                    nearest_date: filterDate,
                };
            });

        return {
            contextText: `\n\nDostępni psycholodzy na ${formatDatePL(filterDate)}: ${suggestions.map(s => s.name).join(', ')}`,
            suggestions,
        };
    } catch {
        return { contextText: '', suggestions: [] };
    }
}

// Kontekst semantyczny (normalny tryb)
async function buildContext(env: Env, userMessage: string): Promise<ContextResult> {
    try {
        const vector = await embed(env, userMessage);
        const [rPsy, rFaq] = await Promise.all([
            env.VECTORIZE_PSY.query(vector, { topK: 4, returnMetadata: 'all' }),
            env.VECTORIZE_FAQ.query(vector, { topK: 2, returnMetadata: 'all' }),
        ]);

        const chunks:      string[]                 = [];
        const suggestions: PsychologistSuggestion[] = [];

        for (const m of rFaq.matches) {
            if ((m.score ?? 0) > 0.55 && m.metadata) {
                const meta = m.metadata as unknown as VectorMetadata & { content?: string };
                chunks.push(`FAQ: ${meta.title}\n${meta.content ?? ''}`);
            }
        }
        for (const m of rPsy.matches) {
            if ((m.score ?? 0) > 0.48 && m.metadata) {
                const meta = m.metadata as unknown as VectorMetadata;
                chunks.push(`Psycholog: ${meta.title} — ${meta.url}`);
                suggestions.push({
                    id:        Number(meta.id),
                    name:      String(meta.title),
                    url:       String(meta.url),
                    photo_url: String(meta.photo_url ?? ''),
                    score:     m.score ?? 0,
                });
            }
        }

        const nearestDates = await fetchNearestDates(env, suggestions.map(s => s.id));
        for (const s of suggestions) {
            const d = nearestDates.get(s.id);
            if (d) s.nearest_date = d;
        }

        return {
            contextText: chunks.length ? '\n\nKontekst:\n' + chunks.join('\n\n') : '',
            suggestions,
        };
    } catch {
        return { contextText: '', suggestions: [] };
    }
}

// ── Contact fallback detection ────────────────────────────────────────────────

const CONTACT_TRIGGERS = [
    'nie wiem', 'nie znam', 'nie mogę odpowiedzieć', 'skontaktuj się',
    'kontakt bezpośredni', 'nie mam informacji', 'nie jestem w stanie',
    'zadzwoń', 'napisz do nas', 'więcej informacji udzieli',
];

function needsContactFallback(reply: string): boolean {
    const lower = reply.toLowerCase();
    return CONTACT_TRIGGERS.some(t => lower.includes(t));
}

// ── SSE helper ────────────────────────────────────────────────────────────────

function sseEvent(data: unknown): string {
    return `data: ${JSON.stringify(data)}\n\n`;
}

// ── Handler ───────────────────────────────────────────────────────────────────

export async function handleChat(request: Request, env: Env): Promise<Response> {
    const body = await request.json<ChatRequest>();
    const { messages, filter_date, consult_type } = body;

    if (!messages?.length) {
        return new Response(JSON.stringify({ error: 'Brak wiadomości' }), {
            status: 400,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    const lastUser = [...messages].reverse().find(m => m.role === 'user')?.content ?? '';

    const { contextText, suggestions } = filter_date
        ? await buildDateFilteredContext(env, filter_date)
        : await buildContext(env, lastUser);

    const history: AiMessage[] = messages.slice(-20).map(m => ({
        role:    m.role as AiMessage['role'],
        content: m.content,
    }));

    const allMessages: AiMessage[] = [
        { role: 'system', content: SYSTEM_PROMPT + contextText },
        ...history,
    ];

    const sseHeaders = {
        'Content-Type':                'text/event-stream',
        'Cache-Control':               'no-cache',
        'Connection':                  'keep-alive',
        'Access-Control-Allow-Origin': '*',
    };

    // ── Tryb filter_date: odpowiedź natychmiastowa (bez LLM) ──────────────────
    if (filter_date) {
        const dateMsg = suggestions.length
            ? 'Oto specjaliści dostępni w ten dzień — kliknij kartę aby umówić wizytę.'
            : `Na ${formatDatePL(filter_date)} nie znaleziono dostępnych specjalistów. Sprawdź inne terminy.`;

        const { readable, writable } = new TransformStream();
        const writer = writable.getWriter();
        const enc    = new TextEncoder();

        (async () => {
            writer.write(enc.encode(sseEvent({ type: 'token', token: dateMsg })));
            writer.write(enc.encode(sseEvent({
                type:         'done',
                reply:        dateMsg,
                suggestions,
                quick_replies: [],
                contact_fallback: false,
            })));
            writer.close();
        })();

        return new Response(readable, { headers: sseHeaders });
    }

    // ── Sprawdzenie dostępności (tool call — nie można streamować) ────────────
    const toolCheck = await env.AI.run(CHAT_MODEL, {
        messages: allMessages,
        tools:    TOOLS,
    }) as AiChatResponse;

    if (toolCheck.tool_calls?.length) {
        const call  = toolCheck.tool_calls[0];
        const typ   = call.arguments.consult_type ?? consult_type ?? 'pelno';
        const avail = await fetchAvailability(env, typ);

        const followUp = await env.AI.run(CHAT_MODEL, {
            messages: [
                ...allMessages,
                { role: 'assistant', content: `Wywołuję ${call.name}` },
                { role: 'tool',      content: avail.text },
                { role: 'user',      content: 'Wymień wszystkie dostępne terminy z powyższej listy, każdy w osobnej linii.' },
            ],
            stream: true,
        }) as ReadableStream;

        return streamResponse(followUp, suggestions.slice(0, 3), avail.quick_replies, sseHeaders);
    }

    // ── Normalny tryb: streaming ──────────────────────────────────────────────
    const stream = await env.AI.run(CHAT_MODEL, {
        messages: allMessages,
        stream:   true,
    }) as ReadableStream;

    return streamResponse(stream, suggestions.slice(0, 3), [], sseHeaders);
}

// ── Stream → SSE response ─────────────────────────────────────────────────────

function streamResponse(
    aiStream:     ReadableStream,
    suggestions:  PsychologistSuggestion[],
    quick_replies: QuickReply[],
    headers:      Record<string, string>,
): Response {
    const { readable, writable } = new TransformStream();
    const writer  = writable.getWriter();
    const enc     = new TextEncoder();
    let   fullReply = '';

    (async () => {
        try {
            const reader = aiStream.getReader();
            const dec    = new TextDecoder();

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                // Workers AI SSE: "data: {...}\n\n" per chunk
                const raw = dec.decode(value, { stream: true });
                for (const line of raw.split('\n')) {
                    const trimmed = line.replace(/^data:\s*/, '').trim();
                    if (!trimmed || trimmed === '[DONE]') continue;
                    try {
                        const parsed = JSON.parse(trimmed);
                        const token  = parsed.response ?? '';
                        if (token) {
                            fullReply += token;
                            writer.write(enc.encode(sseEvent({ type: 'token', token })));
                        }
                    } catch {}
                }
            }
        } catch (e) {
            writer.write(enc.encode(sseEvent({ type: 'error', message: 'Błąd streamingu' })));
        } finally {
            writer.write(enc.encode(sseEvent({
                type:             'done',
                reply:            fullReply,
                suggestions,
                quick_replies,
                contact_fallback: needsContactFallback(fullReply),
            })));
            writer.close();
        }
    })();

    return new Response(readable, { headers });
}
