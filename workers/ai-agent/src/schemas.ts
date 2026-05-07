import type { ChatRequest, SyncPayload, KnowledgeType } from './types';

export type Validated<T> = { ok: true; value: T } | { ok: false; error: string };

const KNOWLEDGE_TYPES: ReadonlyArray<KnowledgeType> = ['psycholog', 'faq', 'article', 'workshop', 'group'];
const CONSULT_TYPES = ['pelno', 'nisko'] as const;
const ROLES = ['user', 'assistant', 'system'] as const;
const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

function isPlainObject(v: unknown): v is Record<string, unknown> {
    return typeof v === 'object' && v !== null && !Array.isArray(v);
}

function isStringArray(v: unknown): v is string[] {
    return Array.isArray(v) && v.every(x => typeof x === 'string');
}

export function validateSyncPayload(input: unknown): Validated<SyncPayload> {
    if (!isPlainObject(input)) return { ok: false, error: 'payload must be an object' };

    const id = input.id;
    if (typeof id !== 'number' || !Number.isFinite(id) || id <= 0) {
        return { ok: false, error: 'id must be a positive number' };
    }

    const type = input.type;
    if (typeof type !== 'string' || !KNOWLEDGE_TYPES.includes(type as KnowledgeType)) {
        return { ok: false, error: `type must be one of ${KNOWLEDGE_TYPES.join('|')}` };
    }

    const title = input.title;
    if (typeof title !== 'string' || title.length === 0 || title.length > 500) {
        return { ok: false, error: 'title must be a non-empty string ≤500 chars' };
    }

    const content = input.content;
    if (typeof content !== 'string' || content.length > 100_000) {
        return { ok: false, error: 'content must be a string ≤100000 chars' };
    }

    const url = input.url;
    if (typeof url !== 'string' || url.length === 0 || url.length > 2048) {
        return { ok: false, error: 'url must be a non-empty string ≤2048 chars' };
    }

    if (input.photo_url !== undefined && (typeof input.photo_url !== 'string' || input.photo_url.length > 2048)) {
        return { ok: false, error: 'photo_url must be a string ≤2048 chars' };
    }

    if (input.tags !== undefined && !isStringArray(input.tags)) {
        return { ok: false, error: 'tags must be an array of strings' };
    }

    if (input.event_date !== undefined && (typeof input.event_date !== 'string' || input.event_date.length > 32)) {
        return { ok: false, error: 'event_date must be a short ISO string' };
    }

    if (input.status !== undefined && input.status !== 'active' && input.status !== 'inactive') {
        return { ok: false, error: 'status must be "active" or "inactive"' };
    }

    if (input.meta !== undefined) {
        if (!isPlainObject(input.meta)) return { ok: false, error: 'meta must be an object' };
        for (const v of Object.values(input.meta)) {
            if (!isStringArray(v)) return { ok: false, error: 'meta values must be string arrays' };
        }
    }

    return { ok: true, value: input as unknown as SyncPayload };
}

export function validateChatRequest(input: unknown): Validated<ChatRequest> {
    if (!isPlainObject(input)) return { ok: false, error: 'payload must be an object' };

    const messages = input.messages;
    if (!Array.isArray(messages) || messages.length === 0 || messages.length > 50) {
        return { ok: false, error: 'messages must be an array (1..50)' };
    }

    for (const m of messages) {
        if (!isPlainObject(m)) return { ok: false, error: 'each message must be an object' };
        if (typeof m.role !== 'string' || !ROLES.includes(m.role as typeof ROLES[number])) {
            return { ok: false, error: `message.role must be one of ${ROLES.join('|')}` };
        }
        if (typeof m.content !== 'string' || m.content.length === 0 || m.content.length > 8000) {
            return { ok: false, error: 'message.content must be a non-empty string ≤8000 chars' };
        }
    }

    if (input.post_id !== undefined && (typeof input.post_id !== 'number' || !Number.isFinite(input.post_id))) {
        return { ok: false, error: 'post_id must be a number' };
    }

    if (input.consult_type !== undefined && !CONSULT_TYPES.includes(input.consult_type as typeof CONSULT_TYPES[number])) {
        return { ok: false, error: `consult_type must be one of ${CONSULT_TYPES.join('|')}` };
    }

    if (input.filter_date !== undefined && (typeof input.filter_date !== 'string' || !ISO_DATE_RE.test(input.filter_date))) {
        return { ok: false, error: 'filter_date must match YYYY-MM-DD' };
    }

    if (input.intent !== undefined && (typeof input.intent !== 'string' || input.intent.length > 64)) {
        return { ok: false, error: 'intent must be a short string' };
    }

    return { ok: true, value: input as unknown as ChatRequest };
}

export interface FeedbackBody {
    value: number;
    type?: string;
}

export function validateFeedback(input: unknown): Validated<FeedbackBody> {
    if (!isPlainObject(input)) return { ok: false, error: 'payload must be an object' };

    const value = input.value;
    if (typeof value !== 'number' || !Number.isFinite(value) || value < 1 || value > 5) {
        return { ok: false, error: 'value must be a number between 1 and 5' };
    }

    if (input.type !== undefined && (typeof input.type !== 'string' || input.type.length > 64)) {
        return { ok: false, error: 'type must be a short string' };
    }

    return { ok: true, value: { value, type: input.type as string | undefined } };
}

export interface SearchQuery {
    query: string;
    type?: string;
}

export function validateSearchQuery(url: URL): Validated<SearchQuery> {
    const query = url.searchParams.get('q')?.trim() ?? '';
    if (query.length === 0) return { ok: false, error: 'q is required' };
    if (query.length > 500)  return { ok: false, error: 'q too long (>500)' };

    const type = url.searchParams.get('type')?.trim() || undefined;
    if (type !== undefined && (type.length === 0 || type.length > 32 || !/^[a-z_]+$/.test(type))) {
        return { ok: false, error: 'type must match [a-z_]{1,32}' };
    }

    return { ok: true, value: { query, type } };
}
