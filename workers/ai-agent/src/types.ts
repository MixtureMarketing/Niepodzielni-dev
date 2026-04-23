export interface Env {
    AI:              Ai;
    VECTORIZE_PSY:   VectorizeIndex;
    VECTORIZE_FAQ:   VectorizeIndex;
    GATEWAY_BASE_URL: string;
    CHAT_MODEL:      string;
    EMBED_MODEL:     string;
    WP_API_URL:      string;
    CF_AIG_TOKEN:    string;
    WORKER_SECRET:   string;
    WP_BOT_TOKEN:    string;
}

export interface SyncPayload {
    id:      number;
    type:    'psycholog' | 'faq';
    title:   string;
    content: string;
    url:     string;
    meta?:   Record<string, string[]>;
}

export interface ChatMessage {
    role:    'user' | 'assistant' | 'system';
    content: string;
}

export interface ChatRequest {
    messages:    ChatMessage[];
    post_id?:    number;
    consult_type?: 'pelno' | 'nisko';
}

export interface VectorMetadata {
    id:    number;
    type:  string;
    title: string;
    url:   string;
}
