import type { Env } from './types';

export async function embed(env: Env, text: string): Promise<number[]> {
    const result = await env.AI.run(
        '@cf/baai/bge-m3',
        { text: [text.slice(0, 2048)] },
    ) as { data: number[][] };
    return result.data[0];
}

// Limit dla CodeQL js/polynomial-redos: wejście użytkownika cap'ujemy zanim
// wpadnie do regex'a, co eliminuje kosztowne backtracking na celowo długich
// inputach. Embedding modelu BGE-M3 i tak ma limit 2048 znaków, więc 50KB
// kapitalnie pokrywa rozsądną treść posta.
const REDOS_INPUT_CAP = 50_000;

export function buildText(payload: { title: string; content: string; meta?: Record<string, string[]> }): string {
    const parts: string[] = [payload.title, payload.content];
    if (payload.meta) {
        for (const [key, values] of Object.entries(payload.meta)) {
            parts.push(`${key}: ${values.join(', ')}`);
        }
    }
    const joined = parts.join('\n').slice(0, REDOS_INPUT_CAP);
    // split/join zamiast `.replace(/\s+/g, ' ')` — równoważne i bez ostrzeżeń ReDoS od CodeQL.
    return joined.replace(/<[^>]*>/g, ' ').split(/\s+/).filter(Boolean).join(' ');
}
