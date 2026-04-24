import type { Env, ChatRequest, ChatMessage, VectorMetadata, PanelItem, QuickReply } from '../types';
import { embed } from '../embed';

const CHAT_MODEL = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';

// ── Filtr kryzysowy — przed LLM, zero opóźnienia ─────────────────────────────

// Normalizuj polskie diakrytyki — użytkownicy często piszą bez nich
function normPL(s: string): string {
    return s.toLowerCase()
        .replace(/ą/g, 'a').replace(/ć/g, 'c').replace(/ę/g, 'e')
        .replace(/ł/g, 'l').replace(/ń/g, 'n').replace(/ó/g, 'o')
        .replace(/ś/g, 's').replace(/ź/g, 'z').replace(/ż/g, 'z');
}

const CRISIS_PHRASES = [
    'samobójstwo', 'samobójcz', 'samobójst',
    'zabić się', 'się zabić', 'zabiję się', 'się zabiję',
    'chcę umrzeć', 'umrzeć chcę', 'chce umrzec',
    'nie chcę żyć', 'nie chce zyc',
    'nie ma sensu żyć', 'nie ma sensu zyc',
    'nie chcę już żyć', 'nie chce juz zyc',
    'skrzywdzić siebie', 'krzywdzić siebie',
    'odebrać sobie życie', 'targnąć się',
    'przemoc domowa', 'molestow', 'gwałt',
    'myśli samobójcze',
];

const CRISIS_PHRASES_NORM = CRISIS_PHRASES.map(normPL);

function isCrisis(messages: ChatMessage[]): boolean {
    const last = normPL([...messages].reverse().find(m => m.role === 'user')?.content ?? '');
    return CRISIS_PHRASES_NORM.some(p => last.includes(p));
}

// ── Detekcja pożegnania ───────────────────────────────────────────────────────

const FAREWELL_PHRASES_NORM = [
    'dziekuje',
    'dzieki za', 'dziek za',          // "dzięki za pomoc", "dziękuję za rozmowę" itp.
    'bardzo dziek', 'wielkie dziek', 'ogromne dziek',
    'do widzenia', 'dowidzenia', 'do zobaczenia',
    'na razie', 'nara ',
    'zegnaj', 'zegnam',
    'to wszystko', 'to tyle',
    'koniec rozmowy',
    'wszystko jasne',
    'pomogles mi', 'pomoglas mi',
    'juz wiem', 'juz mam',
].map(normPL);

// Krótkie wiadomości traktowane jako pożegnanie gdy są jedyną treścią
const FAREWELL_EXACT_NORM = [
    'dziek', 'dzieki', 'dziekuje',
    'pa', 'pa pa', 'papa',
    'bye', 'ciao', 'thx',
    'elo', 'hej pa', 'nara',
    'ok dzieki', 'ok dziekuje',
    'jasne dzieki', 'super dzieki',
    'git dzieki', 'spoko dzieki',
].map(normPL);

function isFarewell(messages: ChatMessage[]): boolean {
    const raw = ([...messages].reverse().find(m => m.role === 'user')?.content ?? '').trim();
    if (raw.length > 80) return false;
    const last = normPL(raw);
    if (FAREWELL_EXACT_NORM.includes(last)) return true;
    return FAREWELL_PHRASES_NORM.some(p => last.includes(p));
}

// ── System prompt ─────────────────────────────────────────────────────────────

const SYSTEM_PROMPT = `ROLA: Jesteś ADMINISTRACYJNYM asystentem AI Fundacji Niepodzielni. Pomagasz pacjentom znaleźć specjalistę i umówić wizytę. NIE prowadzisz terapii, NIE diagnozujesz, NIE udzielasz porad psychologicznych. Jeśli widzisz sygnały kryzysu emocjonalnego lub prośbę o terapię — natychmiast użyj narzędzia escalate_to_human.

Proces nawigacji pacjenta:
1. Gdy pacjent opisze problem — potwierdź go JEDNYM zdaniem. Karty pasujących specjalistów pojawią się w panelu obok automatycznie (nie wymieniaj ich w tekście).
2. Opcjonalnie zadaj JEDNO krótkie pytanie (online/stacjonarnie, język konsultacji).
3. Gdy pacjent pyta o terminy lub dostępność — sprawdź dostępność NATYCHMIAST, bez pytania o potwierdzenie.

Zasady:
- Odpowiadaj ZAWSZE w języku pacjenta (polski domyślnie; angielski, ukraiński i inne).
- Odpowiadaj krótko — max 2–3 zdania. Nie tłumacz instrukcji.
- NIE wymieniaj psychologów po imieniu z URL-em — karty są w panelu po prawej.
- NIE wspominaj o narzędziach, funkcjach ani procesach wewnętrznych — reaguj naturalnie.
- NIE sprawdzaj dostępności gdy pacjent pyta o artykuły, warsztaty lub grupy wsparcia.
- Jeśli pytasz o artykuły/treści: zajrzyj do kontekstu — jeśli brak artykułu, wskaż warsztaty lub grupy z panelu.
- Konsultacje pełnopłatne: standardowa oferta; niskopłatne: dla osób w trudnej sytuacji finansowej.
- KLUCZOWE: Jeśli historia zawiera "[SPECJALIŚCI: ...]" — odpowiadaj na pytania o nich BEZPOŚREDNIO. NIE sprawdzaj dostępności dla pytań o specjalizacje.
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
    {
        type: 'function' as const,
        function: {
            name:        'end_conversation',
            description: 'Użyj gdy użytkownik żegna się, dziękuje za pomoc lub wyraźnie sygnalizuje że zakończył rozmowę i nie potrzebuje już pomocy.',
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

// ── Context z Vectorize ───────────────────────────────────────────────────────

interface ContextResult {
    contextText:   string;
    suggestions:   PanelItem[];
}

async function fetchNearestDates(env: Env, psychologistIds: number[]): Promise<Map<number, string>> {
    const map = new Map<number, string>();
    try {
        const res = await fetch(
            `${env.WP_API_URL}/bot-availability?consult_type=pelno&days=30`,
            { headers: { 'X-API-Key': env.WP_BOT_TOKEN, 'User-Agent': 'NiepodzielniBot/1.0' } },
        );
        if (!res.ok) {
            console.error('[fetchNearestDates] HTTP', res.status, await res.text().catch(() => ''));
            return map;
        }
        const data = await res.json<{ slots: Array<{ date: string; psychologist_ids: number[] }> }>();
        for (const slot of (data.slots ?? [])) {
            for (const pid of slot.psychologist_ids) {
                if (psychologistIds.includes(pid) && !map.has(pid)) {
                    map.set(pid, slot.date);
                }
            }
        }
    } catch (e) {
        console.error('[fetchNearestDates] error:', e);
    }
    return map;
}

// Kontekst dostępności — pobiera psychologów z wolnymi terminami, sortuje po dacie
async function buildAvailabilityContext(env: Env, consultType: string): Promise<{
    contextText:   string;
    suggestions:   PanelItem[];
    quick_replies: QuickReply[];
}> {
    try {
        const res = await fetch(
            `${env.WP_API_URL}/bot-availability?consult_type=${consultType}&days=30`,
            { headers: { 'X-API-Key': env.WP_BOT_TOKEN, 'User-Agent': 'NiepodzielniBot/1.0' } },
        );
        if (!res.ok) {
            console.error('[buildAvailabilityContext] HTTP', res.status);
            return { contextText: 'Brak dostępnych terminów.', suggestions: [], quick_replies: [] };
        }

        const data = await res.json<{ slots: Array<{ date: string; count: number; psychologist_ids: number[] }> }>();
        if (!data.slots?.length) {
            return { contextText: 'Brak dostępnych terminów w najbliższych 30 dniach.', suggestions: [], quick_replies: [] };
        }

        // Zbuduj mapę id → najwcześniejsza data (sloty są posortowane chronologicznie)
        const idDate = new Map<number, string>();
        for (const slot of data.slots) {
            for (const pid of (slot.psychologist_ids ?? [])) {
                if (!idDate.has(pid)) idDate.set(pid, slot.date);
            }
        }

        // Posortuj po dacie rosnąco, weź pierwszych 8
        const sortedIds = [...idDate.entries()]
            .sort((a, b) => a[1].localeCompare(b[1]))
            .slice(0, 8)
            .map(([id]) => id);

        // Pobierz metadane z obu indeksów — KB + PSY (uzupełniające)
        const strIds    = sortedIds.map(String);
        const kbVectors = await env.VECTORIZE_KNOWLEDGE.getByIds(strIds);
        const kbHitIds  = new Set(kbVectors.filter(v => v.metadata).map(v => v.id));
        const missingIds = strIds.filter(id => !kbHitIds.has(id));
        const psyVectors = missingIds.length > 0
            ? await env.VECTORIZE_PSY.getByIds(missingIds)
            : [];
        const vectors = [...kbVectors.filter(v => v.metadata), ...psyVectors.filter(v => v.metadata)];

        const suggestions: PanelItem[] = vectors
            .map(v => {
                const meta  = v.metadata as unknown as VectorMetadata;
                const idNum = Number(v.id);
                return {
                    type:            'psychologist' as const,
                    id:              idNum,
                    title:           String(meta.title),
                    url:             String(meta.url),
                    photo_url:       String(meta.photo_url ?? ''),
                    score:           1.0,
                    nearest_date:    idDate.get(idNum),
                    specializations: meta.specializations ? String(meta.specializations) : undefined,
                };
            })
            .sort((a, b) => (a.nearest_date ?? '').localeCompare(b.nearest_date ?? ''));

        // Kontekst dla LLM — imiona z datami
        const lines = suggestions.map(s =>
            `- ${s.title}${s.specializations ? ` (${s.specializations})` : ''}: ${s.nearest_date ? formatDatePL(s.nearest_date) : 'brak'}`,
        );

        // Ostrzeżenie o ograniczonej dostępności niskopłatnej
        let scarcityNote = '';
        if (consultType === 'nisko') {
            if (suggestions.length === 0) {
                scarcityNote = '\n\nUWAGA: Brak dostępnych terminów niskopłatnych w najbliższych 30 dniach. Poinformuj pacjenta i zaproponuj sprawdzenie terminów pełnopłatnych.';
            } else if (suggestions.length === 1) {
                scarcityNote = '\n\nUWAGA: To JEDYNY dostępny termin niskopłatny w najbliższych 30 dniach. Powiedz to pacjentowi wprost i zaproponuj, że możesz też pokazać terminy pełnopłatne.';
            } else if (suggestions.length <= 3) {
                scarcityNote = `\n\nUWAGA: Dostępne są tylko ${suggestions.length} terminy niskopłatne w najbliższych 30 dniach. Poinformuj pacjenta, że oferta jest ograniczona.`;
            }
        }

        const contextText = `Dostępni specjaliści z wolnymi terminami (od najwcześniejszego):\n${lines.join('\n')}\n\nWAŻNE: Przy odpowiedzi ZAWSZE wymieniaj psychologów z ich konkretną datą z powyższej listy. Polecaj tych z najwcześniejszym terminem.${scarcityNote}`;

        // Quick replies — generuj tylko gdy są DODATKOWE terminy/wyniki których użytkownik jeszcze nie widzi
        const quick_replies: QuickReply[] = [];
        const allUniqueDates = [...new Set(data.slots.map(s => s.date))];
        const shownDates     = new Set(suggestions.map(s => s.nearest_date).filter(Boolean) as string[]);

        if (consultType === 'nisko' && suggestions.length <= 3) {
            // Mało wyników nisko → zaproponuj pełnopłatne zamiast datowych filtrów
            quick_replies.push({
                label:        'Sprawdź dostępne terminy pełnopłatne',
                consult_type: 'pelno',
            });
        } else {
            // Daty z terminami których jeszcze nie pokazano
            const extraDates = allUniqueDates.filter(d => !shownDates.has(d)).slice(0, 3);
            quick_replies.push(...extraDates.map(d => ({
                label:       `Psycholodzy na ${formatDatePL(d)}`,
                filter_date: d,
            })));
        }

        return { contextText, suggestions, quick_replies };
    } catch (e) {
        console.error('[buildAvailabilityContext] error:', e);
        return { contextText: 'Nie udało się pobrać terminów.', suggestions: [], quick_replies: [] };
    }
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
        // Pobierz wektory z obu indeksów — uzupełniające, nie alternatywne
        const kbVec    = await env.VECTORIZE_KNOWLEDGE.getByIds(ids);
        const kbHits   = new Set(kbVec.filter(v => v.metadata).map(v => v.id));
        const missing  = ids.filter(id => !kbHits.has(id));
        const psyVec   = missing.length > 0 ? await env.VECTORIZE_PSY.getByIds(missing) : [];
        const vectors  = [...kbVec.filter(v => v.metadata), ...psyVec.filter(v => v.metadata)];

        const suggestions: PanelItem[] = vectors
            .map(v => {
                const meta = v.metadata as unknown as VectorMetadata;
                return {
                    type:            'psychologist' as const,
                    id:              Number(meta.id),
                    title:           String(meta.title),
                    url:             String(meta.url),
                    photo_url:       String(meta.photo_url ?? ''),
                    score:           1.0,
                    nearest_date:    filterDate,
                    specializations: meta.specializations ? String(meta.specializations) : undefined,
                };
            });

        const psyLines = suggestions.map(s => {
            const spec = s.specializations ? ` (${s.specializations})` : '';
            return `${s.title}${spec}`;
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

        // Jednoznaczne zapytanie do KB — bez metadata filter (filter nie indeksuje starych danych).
        // Rozdzielamy psychologów od treści w kodzie po polu type.
        const useKB = !!env.VECTORIZE_KNOWLEDGE;
        const rKB = useKB
            ? await env.VECTORIZE_KNOWLEDGE.query(vector, { topK: 10, returnMetadata: 'all' })
            : await env.VECTORIZE_PSY.query(vector, { topK: 5, returnMetadata: 'all' });

        const chunks:      string[]     = [];
        const suggestions: PanelItem[]  = [];

        for (const m of rKB.matches) {
            if (!(m.score ?? 0) || !m.metadata) continue;
            const meta  = m.metadata as unknown as VectorMetadata;
            const score = m.score ?? 0;

            if (meta.type === 'psycholog') {
                // Psycholodzy → panel (próg niski — BGE-M3 asymmetric retrieval gap)
                if (score > 0.30) {
                    const spec = meta.specializations ? ` | specjalizacje: ${meta.specializations}` : '';
                    chunks.push(`Psycholog: ${meta.title}${spec} — ${meta.url}`);
                    suggestions.push({
                        type:            'psychologist',
                        id:              Number(meta.id),
                        title:           String(meta.title),
                        url:             String(meta.url),
                        photo_url:       String(meta.photo_url ?? ''),
                        score,
                        specializations: meta.specializations ? String(meta.specializations) : undefined,
                    });
                }
            } else {
                // Treści (FAQ, artykuły, warsztaty, grupy) → context + panel
                if (score > 0.48) {
                    const typeLabel = meta.type === 'faq' ? 'FAQ'
                        : meta.type === 'article' ? 'Artykuł'
                        : meta.type === 'workshop' ? 'Warsztat'
                        : meta.type === 'group' ? 'Grupa wsparcia'
                        : meta.type;
                    const extra = meta.content_snippet ? `\n${meta.content_snippet}` : '';
                    chunks.push(`${typeLabel}: ${meta.title}${extra} — ${meta.url}`);
                    suggestions.push({
                        type:      (meta.type === 'article' ? 'article'
                                   : meta.type === 'workshop' ? 'workshop'
                                   : meta.type === 'group' ? 'group'
                                   : 'faq') as PanelItem['type'],
                        id:        Number(meta.id),
                        title:     String(meta.title),
                        url:       String(meta.url),
                        photo_url: meta.photo_url ? String(meta.photo_url) : undefined,
                        tags:      meta.tags ? String(meta.tags) : undefined,
                        nearest_date: meta.event_date ? String(meta.event_date) : undefined,
                        score,
                    });
                }
            }
        }

        // Fallback do legacy VECTORIZE_PSY gdy KB zwróciło 0 psychologów
        if (suggestions.filter(s => s.type === 'psychologist').length === 0) {
            const rFallback = await env.VECTORIZE_PSY.query(vector, { topK: 5, returnMetadata: 'all' });
            for (const m of rFallback.matches) {
                if ((m.score ?? 0) > 0.30 && m.metadata) {
                    const meta = m.metadata as unknown as VectorMetadata;
                    const spec = meta.specializations ? ` | specjalizacje: ${meta.specializations}` : '';
                    if (!chunks.some(c => c.includes(String(meta.title)))) {
                        chunks.push(`Psycholog: ${meta.title}${spec} — ${meta.url}`);
                    }
                    suggestions.push({
                        type:            'psychologist',
                        id:              Number(meta.id),
                        title:           String(meta.title),
                        url:             String(meta.url),
                        photo_url:       String(meta.photo_url ?? ''),
                        score:           m.score ?? 0,
                        specializations: meta.specializations ? String(meta.specializations) : undefined,
                    });
                }
            }
        }

        const psychologistIds = suggestions.filter(s => s.type === 'psychologist').map(s => s.id);
        const nearestDates = await fetchNearestDates(env, psychologistIds);
        for (const s of suggestions) {
            if (s.type === 'psychologist') {
                const d = nearestDates.get(s.id);
                if (d) s.nearest_date = d;
            }
        }

        // Dodaj do kontekstu info o dostępności — LLM może odpowiedzieć o braku terminów
        if (psychologistIds.length > 0) {
            const withDate = suggestions.filter(s => s.type === 'psychologist' && s.nearest_date);
            if (withDate.length > 0) {
                const lines = withDate.map(s => `${s.title}: ${formatDatePL(s.nearest_date!)}`);
                chunks.push(`DOSTĘPNOŚĆ TERMINÓW: ${lines.join(', ')}`);
            } else {
                chunks.push('DOSTĘPNOŚĆ TERMINÓW: Aktualnie żaden z dopasowanych specjalistów nie ma wolnych terminów w najbliższych 30 dniach. Poinformuj pacjenta, że warto sprawdzić ponownie za kilka dni lub skontaktować się z recepcją Fundacji.');
            }
        }

        if (chunks.length === 0 && suggestions.length === 0) {
            console.log(JSON.stringify({ type: 'blind_alley', query: userMessage.slice(0, 200), ts: Date.now() }));
        }

        return {
            contextText: chunks.length ? '\n\nKontekst:\n' + chunks.join('\n\n') : '',
            suggestions,
        };
    } catch (e) {
        console.error('[buildContext] error:', e);
        return { contextText: '', suggestions: [] };
    }
}

// ── Miks panelu: sortuj psychologów po dostępności, content gdy dominuje ──────

function pickPanelItems(suggestions: PanelItem[], max = 8): PanelItem[] {
    const psychologists = suggestions.filter(s => s.type === 'psychologist');
    const content       = suggestions.filter(s => s.type !== 'psychologist');

    // Sortuj psychologów: z nearest_date na górze
    const psySorted = [...psychologists].sort((a, b) => {
        if (a.nearest_date && !b.nearest_date) return -1;
        if (!a.nearest_date && b.nearest_date) return  1;
        return b.score - a.score;
    });

    // Jeśli treści dominują (avg score > psycholodzy + 0.15) → panel treści
    if (content.length >= 2) {
        const avgPsy = psychologists.reduce((s, x) => s + x.score, 0) / (psychologists.length || 1);
        const avgCon = content.reduce((s, x) => s + x.score, 0) / content.length;
        if (avgCon > avgPsy + 0.15) {
            return [...content.slice(0, max)];
        }
    }

    // Standardowy miks: do 5 psychologów (posortowanych) + do 3 treści
    const psySlot = Math.min(5, psySorted.length);
    const conSlot = Math.min(3, content.length);
    return [...psySorted.slice(0, psySlot), ...content.slice(0, conSlot)].slice(0, max);
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

    // ── Detekcja pożegnania — przed LLM ──────────────────────────────────────
    if (isFarewell(messages)) {
        const reply = 'Cieszę się, że mogłem pomóc! Jeśli kiedyś znowu będziesz szukać specjalisty — chętnie pomogę. Powodzenia! 🤝';
        const { readable, writable } = new TransformStream();
        const writer = writable.getWriter();
        const enc    = new TextEncoder();
        (async () => {
            writer.write(enc.encode(sseEvent({ type: 'token', token: reply })));
            writer.write(enc.encode(sseEvent({ type: 'done', farewell: true, reply, suggestions: [], quick_replies: [], contact_fallback: false })));
            writer.close();
        })();
        return new Response(readable, { headers: {
            'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache',
            'Connection': 'keep-alive', 'Access-Control-Allow-Origin': '*',
        }});
    }

    const lastUser = [...messages].reverse().find(m => m.role === 'user')?.content ?? '';

    // Krótkie wiadomości (preferencje: "online", "tak" itp.) nie mają wartości semantycznej
    // — użyj najdłuższej wiadomości użytkownika (typowo: pierwsze opisanie problemu)
    // żeby panel pokazywał spójne wyniki przez całą rozmowę
    const semanticQuery = (() => {
        if (lastUser.length >= 40) return lastUser;
        const userMsgs = messages.filter(m => m.role === 'user').slice(-8).map(m => m.content);
        return userMsgs.reduce((a, b) => (b.length > a.length ? b : a), lastUser);
    })();

    const { contextText, suggestions } = filter_date
        ? await buildDateFilteredContext(env, filter_date)
        : await buildContext(env, semanticQuery);

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

    // Llama czasem pisze nazwę narzędzia jako tekst zamiast tool_call — wykryj i obsłuż
    const TOOL_NAME_RE = /\b(escalate_to_human|check_availability|recommend_resources|get_pricing_info|end_conversation)\b/;
    const botchedCall  = !toolCheck.tool_calls?.length && TOOL_NAME_RE.exec(toolCheck.response ?? '');
    if (botchedCall && !toolCheck.tool_calls?.length) {
        const name = botchedCall[1];
        if (name === 'escalate_to_human') {
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
        if (name === 'end_conversation') {
            const reply = 'Cieszę się, że mogłem pomóc! Jeśli kiedyś znowu będziesz szukać specjalisty — chętnie pomogę. Powodzenia! 🤝';
            const { readable, writable } = new TransformStream();
            const writer = writable.getWriter();
            const enc    = new TextEncoder();
            (async () => {
                writer.write(enc.encode(sseEvent({ type: 'token', token: reply })));
                writer.write(enc.encode(sseEvent({ type: 'done', farewell: true, reply, suggestions: [], quick_replies: [], contact_fallback: false })));
                writer.close();
            })();
            return new Response(readable, { headers: sseHeaders });
        }
        // Dla innych narzędzi: utwórz syntetyczny tool_call i kontynuuj
        if (!toolCheck.tool_calls) (toolCheck as AiChatResponse).tool_calls = [];
        toolCheck.tool_calls!.push({ name, arguments: {} });
    }

    if (toolCheck.tool_calls?.length) {
        const call = toolCheck.tool_calls[0];

        if (call.name === 'end_conversation') {
            const reply = 'Cieszę się, że mogłem pomóc! Jeśli kiedyś znowu będziesz szukać specjalisty — chętnie pomogę. Powodzenia! 🤝';
            const { readable, writable } = new TransformStream();
            const writer = writable.getWriter();
            const enc    = new TextEncoder();
            (async () => {
                writer.write(enc.encode(sseEvent({ type: 'token', token: reply })));
                writer.write(enc.encode(sseEvent({ type: 'done', farewell: true, reply, suggestions: [], quick_replies: [], contact_fallback: false })));
                writer.close();
            })();
            return new Response(readable, { headers: sseHeaders });
        }

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
            // Bez metadata filter (nie działa na starych danych) — filtrujemy w kodzie
            const rAll = await (env.VECTORIZE_KNOWLEDGE ?? env.VECTORIZE_PSY).query(vector, {
                topK: 10, returnMetadata: 'all',
            });

            const toolSuggestions: PanelItem[] = [];
            const resLines: string[] = [];

            for (const m of rAll.matches) {
                if (!(m.score ?? 0) || !m.metadata) continue;
                const meta  = m.metadata as unknown as VectorMetadata;
                const score = m.score ?? 0;

                if (meta.type === 'psycholog' && score > 0.30) {
                    const spec = meta.specializations ? ` (specjalizacje: ${meta.specializations})` : '';
                    resLines.push(`Psycholog: ${meta.title}${spec} — ${meta.url}`);
                    toolSuggestions.push({
                        type:            'psychologist',
                        id:              Number(meta.id),
                        title:           String(meta.title),
                        url:             String(meta.url),
                        photo_url:       String(meta.photo_url ?? ''),
                        score,
                        specializations: meta.specializations ? String(meta.specializations) : undefined,
                    });
                } else if (meta.type !== 'psycholog' && score > 0.48) {
                    const typeLabel = meta.type === 'workshop' ? 'Warsztat'
                        : meta.type === 'group' ? 'Grupa wsparcia'
                        : meta.type === 'article' ? 'Artykuł' : 'FAQ';
                    resLines.push(`${typeLabel}: ${meta.title} — ${meta.url}`);
                    toolSuggestions.push({
                        type: (meta.type === 'workshop' ? 'workshop'
                            : meta.type === 'group' ? 'group'
                            : meta.type === 'article' ? 'article' : 'faq') as PanelItem['type'],
                        id:        Number(meta.id),
                        title:     String(meta.title),
                        url:       String(meta.url),
                        photo_url: meta.photo_url ? String(meta.photo_url) : undefined,
                        tags:      meta.tags ? String(meta.tags) : undefined,
                        score,
                    });
                }
            }

            const psyCount     = toolSuggestions.filter(s => s.type === 'psychologist').length;
            const contentCount = toolSuggestions.filter(s => s.type !== 'psychologist').length;
            const toolContext  = resLines.length
                ? `Znaleziono ${psyCount} specjalistów i ${contentCount} zasobów pasujących do problemu "${query}". Karty są już w panelu bocznym — NIE wymieniaj ich z imienia w odpowiedzi.`
                : `Nie znaleziono specjalistów pasujących do: "${query}". Odpowiedz, że możesz sprawdzić dostępne terminy.`;

            const nearestDates = await fetchNearestDates(env, toolSuggestions.filter(s => s.type === 'psychologist').map(s => s.id));
            for (const s of toolSuggestions) {
                const d = nearestDates.get(s.id);
                if (d) s.nearest_date = d;
            }

            const followUp = await env.AI.run(CHAT_MODEL, {
                messages: [
                    ...allMessages,
                    { role: 'tool',      content: toolContext },
                ],
                stream: true,
            }) as ReadableStream;

            return streamResponse(followUp, pickPanelItems(toolSuggestions), [], sseHeaders);
        }

        if (call.name === 'get_pricing_info') {
            const followUp = await env.AI.run(CHAT_MODEL, {
                messages: [
                    ...allMessages,
                    { role: 'tool',      content: PRICING_INFO },
                ],
                stream: true,
            }) as ReadableStream;

            return streamResponse(followUp, pickPanelItems(suggestions), [], sseHeaders);
        }

        const typ = call.arguments.consult_type ?? consult_type ?? 'pelno';
        const { contextText: availCtx, suggestions: availSugg, quick_replies: availQR } =
            await buildAvailabilityContext(env, typ);

        const followUp = await env.AI.run(CHAT_MODEL, {
            messages: [
                ...allMessages,
                { role: 'tool', content: availCtx },
            ],
            stream: true,
        }) as ReadableStream;

        return streamResponse(followUp, pickPanelItems(availSugg), availQR, sseHeaders);
    }

    // ── Normalny tryb: streaming ──────────────────────────────────────────────
    const stream = await env.AI.run(CHAT_MODEL, {
        messages: allMessages,
        stream:   true,
    }) as ReadableStream;

    return streamResponse(stream, pickPanelItems(suggestions), [], sseHeaders);
}

// ── Stream → SSE response ─────────────────────────────────────────────────────

function streamResponse(
    aiStream:     ReadableStream,
    suggestions:  PanelItem[],
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
            // Jeśli LLM wpisał nazwę narzędzia jako tekst zamiast tool_call — nadpisz odpowiedź
            const STREAM_TOOL_RE = /\b(escalate_to_human|end_conversation)\b/;
            const streamBotched  = STREAM_TOOL_RE.exec(fullReply);
            if (streamBotched) {
                if (streamBotched[1] === 'escalate_to_human') {
                    const r = 'Rozumiem, że potrzebujesz bezpośredniej pomocy. Oto dane kontaktowe Fundacji:';
                    writer.write(enc.encode(sseEvent({ type: 'done', reply: r, suggestions: [], quick_replies: [], contact_fallback: true, crisis: false })));
                } else {
                    const r = 'Cieszę się, że mogłem pomóc! Jeśli kiedyś znowu będziesz szukać specjalisty — chętnie pomogę. Powodzenia! 🤝';
                    writer.write(enc.encode(sseEvent({ type: 'done', farewell: true, reply: r, suggestions: [], quick_replies: [], contact_fallback: false })));
                }
            } else {
                writer.write(enc.encode(sseEvent({
                    type:             'done',
                    reply:            fullReply,
                    suggestions,
                    quick_replies,
                    contact_fallback: needsContactFallback(fullReply),
                })));
            }
            writer.close();
        }
    })();

    return new Response(readable, { headers });
}
