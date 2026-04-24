import type { Env, ChatRequest, ChatMessage, VectorMetadata, PsychologistSuggestion, QuickReply } from '../types';
import { embed } from '../embed';

const CHAT_MODEL = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';

// ── Filtr kryzysowy — przed LLM, zero opóźnienia ─────────────────────────────

const CRISIS_RE = /(samobójstwo|samobójcz|zabić się|nie chcę żyć|skrzywdzić się|się zabiję|chcę umrzeć|myśl\w* samob|krzywdz\w* siebie|odebrać sobie życie|targnąć się|przemoc domowa|gwałt\b|molestow)/i;

function isCrisis(messages: ChatMessage[]): boolean {
    const last = [...messages].reverse().find(m => m.role === 'user')?.content ?? '';
    return CRISIS_RE.test(last);
}

// ── System prompt ─────────────────────────────────────────────────────────────

const SYSTEM_PROMPT = `ROLA: Jesteś ADMINISTRACYJNYM asystentem AI Fundacji Niepodzielni. Pomagasz pacjentom znaleźć specjalistę i umówić wizytę. NIE prowadzisz terapii, NIE diagnozujesz, NIE udzielasz porad psychologicznych. Jeśli widzisz sygnały kryzysu emocjonalnego lub prośbę o terapię — natychmiast użyj narzędzia escalate_to_human.

Proces nawigacji pacjenta:
1. Gdy pacjent opisze problem — potwierdź go JEDNYM zdaniem. Karty pasujących specjalistów pojawią się w panelu obok automatycznie (nie wymieniaj ich w tekście).
2. Opcjonalnie zadaj JEDNO krótkie pytanie (online/stacjonarnie, język konsultacji).
3. Gdy pacjent pyta o terminy lub dostępność — wywołaj check_availability NATYCHMIAST, bez pytania o potwierdzenie.

Zasady:
- Odpowiadaj ZAWSZE w języku pacjenta (polski domyślnie; angielski, ukraiński i inne).
- Odpowiadaj krótko — max 2–3 zdania. Nie tłumacz instrukcji.
- NIE wymieniaj psychologów po imieniu z URL-em — karty są w panelu po prawej.
- NIE pytaj "Czy chcesz sprawdzić terminy?" — po prostu wywołaj check_availability.
- Konsultacje pełnopłatne: standardowa oferta; niskopłatne: dla osób w trudnej sytuacji finansowej.
- KLUCZOWE: Jeśli historia zawiera "[SPECJALIŚCI: ...]" — odpowiadaj na pytania o nich BEZPOŚREDNIO. NIE wywołuj check_availability dla pytań o specjalizacje.
- Nie wymyślaj informacji — opieraj się wyłącznie na dostarczonym kontekście.`;

// ── Narzędzia AI ──────────────────────────────────────────────────────────────

const TOOLS = [
    {
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
    },
    {
        type: 'function' as const,
        function: {
            name:        'escalate_to_human',
            description: 'Użyj gdy: pacjent jest sfrustrowany brakiem pomocy, wyraźnie w kryzysie emocjonalnym, prosi o kontakt z żywą osobą lub gdy AI wielokrotnie nie może odpowiedzieć na pytanie.',
            parameters:  { type: 'object', properties: {}, required: [] },
        },
    },
    {
        type: 'function' as const,
        function: {
            name:        'recommend_resources',
            description: 'Wyszukuje psychologów pasujących do problemu pacjenta. Użyj gdy pacjent opisał swój problem i potrzebujesz zaproponować konkretnych specjalistów.',
            parameters:  {
                type:       'object',
                properties: {
                    query: {
                        type:        'string',
                        description: 'Opis problemu pacjenta użyty do wyszukiwania semantycznego',
                    },
                },
                required: ['query'],
            },
        },
    },
    {
        type: 'function' as const,
        function: {
            name:        'get_pricing_info',
            description: 'Zwraca informacje o cenach i typach konsultacji (pełnopłatne vs niskopłatne).',
            parameters:  { type: 'object', properties: {}, required: [] },
        },
    },
];

const PRICING_INFO = `Konsultacje w Fundacji Niepodzielni:
• Konsultacje PEŁNOPŁATNE: standardowa oferta dla wszystkich pacjentów. Cena zależy od specjalisty i widoczna jest na profilu psychologa na stronie niepodzielni.pl.
• Konsultacje NISKOPŁATNE: dla osób w trudnej sytuacji finansowej lub materialnej. Dostępne po wstępnej kwalifikacji przez recepcję Fundacji.
Aby umówić konsultację lub dowiedzieć się o aktualnych stawkach, skontaktuj się z Fundacją.`;

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
        console.log("-> Sprawdzam token. Długość:", env.WP_BOT_TOKEN?.length);
        
        const res = await fetch(
            `${env.WP_API_URL}/bot-availability?consult_type=${consultType}&days=14`,
            { 
                headers: { 
                    'X-API-Key': env.WP_BOT_TOKEN,
                    'User-Agent': 'NiepodzielniBot/1.0' // Omijamy blokadę tunelu CF
                } 
            },
        );
        
        if (!res.ok) {
            // Wypluje dokładny błąd do Twojej konsoli!
            console.error("-> BŁĄD API:", res.status, await res.text());
            return { text: 'Brak danych o dostępności.', quick_replies: [] };
        }

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
    } catch (err) {
        console.error("-> BŁĄD KRYTYCZNY:", err);
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

        const ids = slot.psychologist_ids.slice(0, 6).map(String);
        // Pobierz wektory — preferuj KB, fallback do VECTORIZE_PSY jeśli KB zwróci puste
        let vectors = await env.VECTORIZE_KNOWLEDGE.getByIds(ids);
        if (vectors.filter(v => v.metadata).length === 0) {
            vectors = await env.VECTORIZE_PSY.getByIds(ids);
        }

        const suggestions: PsychologistSuggestion[] = vectors
            .filter(v => v.metadata)
            .map(v => {
                const meta = v.metadata as unknown as VectorMetadata;
                return {
                    id:              Number(meta.id),
                    name:            String(meta.title),
                    url:             String(meta.url),
                    photo_url:       String(meta.photo_url ?? ''),
                    score:           1.0,
                    nearest_date:    filterDate,
                    specializations: meta.specializations ? String(meta.specializations) : undefined,
                };
            });

        const psyLines = suggestions.map(s => {
            const spec = s.specializations ? ` (${s.specializations})` : '';
            return `${s.name}${spec}`;
        }).join('; ');

        return {
            contextText: `\n\nDostępni psycholodzy na ${formatDatePL(filterDate)}: ${psyLines}`,
            suggestions,
        };
    } catch {
        return { contextText: '', suggestions: [] };
    }
}

// Kontekst semantyczny (normalny tryb) — unified KNOWLEDGE_BASE z metadata filtering
async function buildContext(env: Env, userMessage: string): Promise<ContextResult> {
    try {
        const vector = await embed(env, userMessage);

        // Zapytania równoległe: psycholodzy (panel) + treści (kontekst tekstowy)
        const useKB = !!env.VECTORIZE_KNOWLEDGE;
        const [rPsy, rContent] = await Promise.all([
            useKB
                ? env.VECTORIZE_KNOWLEDGE.query(vector, {
                    topK: 5, returnMetadata: 'all',
                    filter: { type: { $eq: 'psycholog' } },
                  })
                : env.VECTORIZE_PSY.query(vector, { topK: 4, returnMetadata: 'all' }),
            useKB
                ? env.VECTORIZE_KNOWLEDGE.query(vector, { topK: 4, returnMetadata: 'all' })
                : env.VECTORIZE_FAQ.query(vector, { topK: 2, returnMetadata: 'all' }),
        ]);

        const chunks:      string[]                 = [];
        const suggestions: PsychologistSuggestion[] = [];

        // Treści (FAQ, artykuły, warsztaty, grupy) → context text
        // Pomijamy psychologów — obsługiwani przez rPsy z filtrem type
        for (const m of rContent.matches) {
            if ((m.score ?? 0) > 0.52 && m.metadata) {
                const meta = m.metadata as unknown as VectorMetadata;
                if (meta.type === 'psycholog') continue;
                const typeLabel = meta.type === 'faq' ? 'FAQ'
                    : meta.type === 'article' ? 'Artykuł'
                    : meta.type === 'workshop' ? 'Warsztat'
                    : meta.type === 'group' ? 'Grupa wsparcia'
                    : meta.type;
                const extra = meta.content_snippet ? `\n${meta.content_snippet}` : '';
                chunks.push(`${typeLabel}: ${meta.title}${extra} — ${meta.url}`);
            }
        }

        // Psycholodzy → suggestions (panel boczny)
        for (const m of rPsy.matches) {
            if ((m.score ?? 0) > 0.46 && m.metadata) {
                const meta = m.metadata as unknown as VectorMetadata;
                const spec = meta.specializations ? ` | specjalizacje: ${meta.specializations}` : '';
                chunks.push(`Psycholog: ${meta.title}${spec} — ${meta.url}`);
                suggestions.push({
                    id:              Number(meta.id),
                    name:            String(meta.title),
                    url:             String(meta.url),
                    photo_url:       String(meta.photo_url ?? ''),
                    score:           m.score ?? 0,
                    specializations: meta.specializations ? String(meta.specializations) : undefined,
                });
            }
        }

        // Fallback do legacy VECTORIZE_PSY gdy KB filtr nie zwrócił psychologów
        // (metadata index może jeszcze nie być gotowy po migracji)
        if (suggestions.length === 0) {
            const rFallback = await env.VECTORIZE_PSY.query(vector, { topK: 4, returnMetadata: 'all' });
            for (const m of rFallback.matches) {
                if ((m.score ?? 0) > 0.46 && m.metadata) {
                    const meta = m.metadata as unknown as VectorMetadata;
                    const spec = meta.specializations ? ` | specjalizacje: ${meta.specializations}` : '';
                    if (!chunks.some(c => c.includes(String(meta.title)))) {
                        chunks.push(`Psycholog: ${meta.title}${spec} — ${meta.url}`);
                    }
                    suggestions.push({
                        id:              Number(meta.id),
                        name:            String(meta.title),
                        url:             String(meta.url),
                        photo_url:       String(meta.photo_url ?? ''),
                        score:           m.score ?? 0,
                        specializations: meta.specializations ? String(meta.specializations) : undefined,
                    });
                }
            }
        }

        const nearestDates = await fetchNearestDates(env, suggestions.map(s => s.id));
        for (const s of suggestions) {
            const d = nearestDates.get(s.id);
            if (d) s.nearest_date = d;
        }

        if (chunks.length === 0 && suggestions.length === 0) {
            console.log(JSON.stringify({ type: 'blind_alley', query: userMessage.slice(0, 200), ts: Date.now() }));
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

    // ── Filtr kryzysowy — przed LLM, zero opóźnienia ─────────────────────────
    if (isCrisis(messages)) {
        const { readable, writable } = new TransformStream();
        const writer = writable.getWriter();
        const enc    = new TextEncoder();
        (async () => {
            writer.write(enc.encode(sseEvent({ type: 'done', crisis: true, reply: '', suggestions: [], quick_replies: [], contact_fallback: false })));
            writer.close();
        })();
        return new Response(readable, { headers: {
            'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache',
            'Connection': 'keep-alive', 'Access-Control-Allow-Origin': '*',
        }});
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
    // Pomiń tool call gdy ostatnia wiadomość asystenta zawiera dane już pokazanych
    // specjalistów — LLM powinien odpowiedzieć z historii, nie pobierać terminów ponownie.
    const lastAssistantContent = [...messages].reverse().find(m => m.role === 'assistant')?.content ?? '';
    const hasShownSpecialists  = lastAssistantContent.includes('[SPECJALIŚCI:');

    const toolCheck = hasShownSpecialists ? { response: '', tool_calls: [] } : await env.AI.run(CHAT_MODEL, {
        messages: allMessages,
        tools:    TOOLS,
    }) as AiChatResponse;

    if (toolCheck.tool_calls?.length) {
        const call = toolCheck.tool_calls[0];

        if (call.name === 'escalate_to_human') {
            const reply = 'Rozumiem, że potrzebujesz bezpośredniej pomocy. Oto dane kontaktowe Fundacji:';
            const { readable, writable } = new TransformStream();
            const writer = writable.getWriter();
            const enc    = new TextEncoder();
            (async () => {
                writer.write(enc.encode(sseEvent({ type: 'token', token: reply })));
                writer.write(enc.encode(sseEvent({ type: 'done', reply, suggestions: [], quick_replies: [], contact_fallback: true, crisis: false })));
                writer.close();
            })();
            return new Response(readable, { headers: sseHeaders });
        }

        if (call.name === 'recommend_resources') {
            const query  = call.arguments.query ?? lastUser;
            const vector = await embed(env, query);
            const index  = env.VECTORIZE_KNOWLEDGE ?? env.VECTORIZE_PSY;
            const rPsy   = await index.query(vector, {
                topK: 5, returnMetadata: 'all',
                filter: { type: { $eq: 'psycholog' } },
            });

            const toolSuggestions: PsychologistSuggestion[] = [];
            const resLines: string[] = [];

            for (const m of rPsy.matches) {
                if ((m.score ?? 0) > 0.40 && m.metadata) {
                    const meta = m.metadata as unknown as VectorMetadata;
                    const spec = meta.specializations ? ` (specjalizacje: ${meta.specializations})` : '';
                    resLines.push(`Psycholog: ${meta.title}${spec} — ${meta.url}`);
                    toolSuggestions.push({
                        id:              Number(meta.id),
                        name:            String(meta.title),
                        url:             String(meta.url),
                        photo_url:       String(meta.photo_url ?? ''),
                        score:           m.score ?? 0,
                        specializations: meta.specializations ? String(meta.specializations) : undefined,
                    });
                }
            }

            const toolContext = resLines.length
                ? `Znalezieni specjaliści pasujący do problemu "${query}":\n${resLines.join('\n')}`
                : `Nie znaleziono specjalistów pasujących do: "${query}".`;

            const nearestDates = await fetchNearestDates(env, toolSuggestions.map(s => s.id));
            for (const s of toolSuggestions) {
                const d = nearestDates.get(s.id);
                if (d) s.nearest_date = d;
            }

            const followUp = await env.AI.run(CHAT_MODEL, {
                messages: [
                    ...allMessages,
                    { role: 'assistant', content: `Szukam specjalistów: ${query}` },
                    { role: 'tool',      content: toolContext },
                ],
                stream: true,
            }) as ReadableStream;

            return streamResponse(followUp, toolSuggestions.slice(0, 3), [], sseHeaders);
        }

        if (call.name === 'get_pricing_info') {
            const followUp = await env.AI.run(CHAT_MODEL, {
                messages: [
                    ...allMessages,
                    { role: 'assistant', content: 'Sprawdzam informacje o cenach.' },
                    { role: 'tool',      content: PRICING_INFO },
                ],
                stream: true,
            }) as ReadableStream;

            return streamResponse(followUp, suggestions.slice(0, 3), [], sseHeaders);
        }

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
