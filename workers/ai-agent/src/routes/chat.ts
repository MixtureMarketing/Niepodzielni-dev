import type { Env, ChatRequest, VectorMetadata } from '../types';
import { embed } from '../embed';

const CHAT_MODEL = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';

const SYSTEM_PROMPT = `Jesteś pomocnym asystentem Fundacji Niepodzielni — organizacji łączącej ludzi z psychologami i specjalistami zdrowia psychicznego w Polsce. Pomagasz użytkownikom znaleźć odpowiedniego specjalistę, odpowiadasz na pytania dotyczące oferty i pomagasz umówić wizytę.

Zasady:
- Odpowiadaj po polsku, ciepło i empatycznie.
- Gdy użytkownik chce umówić wizytę lub sprawdzić dostępne terminy, użyj funkcji check_availability.
- Nie wymyślaj informacji — opieraj się wyłącznie na dostarczonym kontekście.
- Jeśli nie znasz odpowiedzi, powiedz szczerze i zaproponuj kontakt bezpośredni.
- Konsultacje pełnopłatne to standardowa oferta; niskopłatne są dostępne dla osób w trudnej sytuacji finansowej.`;

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

async function fetchAvailability(env: Env, consultType: string): Promise<string> {
    try {
        const res = await fetch(
            `${env.WP_API_URL}/bot-availability?consult_type=${consultType}&days=14`,
            { headers: { 'X-API-Key': env.WP_BOT_TOKEN } },
        );
        if (!res.ok) return 'Brak danych o dostępności.';
        const data = await res.json<{ slots: Array<{ date: string; count: number }> }>();
        if (!data.slots?.length) return 'Brak dostępnych terminów w ciągu najbliższych 14 dni.';
        const lines = data.slots.slice(0, 10).map(s => `${s.date}: ${s.count} specjalistów`);
        return 'Dostępne terminy:\n' + lines.join('\n');
    } catch {
        return 'Nie udało się pobrać terminów.';
    }
}

async function buildContext(env: Env, userMessage: string): Promise<string> {
    try {
        const vector = await embed(env, userMessage);
        const [rPsy, rFaq] = await Promise.all([
            env.VECTORIZE_PSY.query(vector, { topK: 3, returnMetadata: 'all' }),
            env.VECTORIZE_FAQ.query(vector, { topK: 2, returnMetadata: 'all' }),
        ]);

        const chunks: string[] = [];

        for (const m of rFaq.matches) {
            if ((m.score ?? 0) > 0.55 && m.metadata) {
                const meta = m.metadata as unknown as VectorMetadata & { content?: string };
                chunks.push(`FAQ: ${meta.title}\n${meta.content ?? ''}`);
            }
        }
        for (const m of rPsy.matches) {
            if ((m.score ?? 0) > 0.50 && m.metadata) {
                const meta = m.metadata as unknown as VectorMetadata;
                chunks.push(`Psycholog: ${meta.title} — ${meta.url}`);
            }
        }

        return chunks.length ? '\n\nKontekst:\n' + chunks.join('\n\n') : '';
    } catch {
        return '';
    }
}

export async function handleChat(request: Request, env: Env): Promise<Response> {
    const body = await request.json<ChatRequest>();
    const { messages } = body;

    if (!messages?.length) {
        return new Response(JSON.stringify({ error: 'Brak wiadomości' }), {
            status: 400,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    const lastUser = [...messages].reverse().find(m => m.role === 'user')?.content ?? '';
    const context  = await buildContext(env, lastUser);

    const history: AiMessage[] = messages.slice(-20).map(m => ({
        role:    m.role as AiMessage['role'],
        content: m.content,
    }));

    const allMessages: AiMessage[] = [
        { role: 'system', content: SYSTEM_PROMPT + context },
        ...history,
    ];

    const result = await env.AI.run(CHAT_MODEL, {
        messages: allMessages,
        tools:    TOOLS,
    }) as AiChatResponse;

    // Obsługa tool calling (Workers AI zwraca tool_calls jako obiekt, nie string)
    if (result.tool_calls?.length) {
        const call         = result.tool_calls[0];
        const availability = await fetchAvailability(env, call.arguments.consult_type ?? 'pelno');

        const followUp = await env.AI.run(CHAT_MODEL, {
            messages: [
                ...allMessages,
                { role: 'assistant', content: `Wywołuję ${call.name}` },
                { role: 'tool',      content: availability },
            ],
        }) as AiChatResponse;

        return new Response(JSON.stringify({
            reply: followUp.response ?? '',
        }), { headers: { 'Content-Type': 'application/json' } });
    }

    return new Response(JSON.stringify({
        reply: result.response ?? '',
    }), { headers: { 'Content-Type': 'application/json' } });
}
