import type { Env } from './types';

export async function embed(env: Env, text: string): Promise<number[]> {
    const result = await env.AI.run(
        '@cf/baai/bge-m3',
        { text: [text.slice(0, 2048)] },
    ) as { data: number[][] };
    return result.data[0];
}

export function buildText(payload: { title: string; content: string; meta?: Record<string, string[]> }): string {
    const parts: string[] = [payload.title, payload.content];
    if (payload.meta) {
        for (const [key, values] of Object.entries(payload.meta)) {
            parts.push(`${key}: ${values.join(', ')}`);
        }
    }
    return parts.join('\n').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
}
