export interface Env {
    AI:                   Ai;
    VECTORIZE_PSY:        VectorizeIndex; // legacy — fallback podczas migracji
    VECTORIZE_FAQ:        VectorizeIndex; // legacy — fallback podczas migracji
    VECTORIZE_KNOWLEDGE:  VectorizeIndex; // unified knowledge base
    GATEWAY_BASE_URL:     string;
    CHAT_MODEL:           string;
    EMBED_MODEL:          string;
    WP_API_URL:           string;
    CF_AIG_TOKEN:         string;
    WORKER_SECRET:        string;
    WP_BOT_TOKEN:         string;
}

export type KnowledgeType = 'psycholog' | 'faq' | 'article' | 'workshop' | 'group';

export interface SyncPayload {
    id:          number;
    type:        KnowledgeType;
    title:       string;
    content:     string;
    url:         string;
    photo_url?:  string;
    tags?:       string[];       // tematyczne tagi: depresja, lęki, adhd…
    event_date?: string;         // ISO — dla warsztatów/wydarzeń
    status?:     'active' | 'inactive';
    meta?:       Record<string, string[]>;
}

export interface ChatMessage {
    role:    'user' | 'assistant' | 'system';
    content: string;
}

export interface ChatRequest {
    messages:      ChatMessage[];
    post_id?:      number;
    consult_type?: 'pelno' | 'nisko';
    filter_date?:  string; // ISO "YYYY-MM-DD" — filtruje psychologów po dacie
}

export interface VectorMetadata {
    id:               number;
    type:             string;
    title:            string;
    url:              string;
    photo_url?:       string;
    specializations?: string; // comma-separated: specjalizacje, nurty, obszary (psycholog)
    tags?:            string; // comma-separated tematyczne tagi (artykuły, warsztaty, grupy)
    event_date?:      string; // ISO date (warsztaty/wydarzenia)
    content_snippet?: string; // pierwsze ~200 znaków treści
    status?:          string; // 'active' | 'inactive'
}

export interface PsychologistSuggestion {
    id:               number;
    name:             string;
    url:              string;
    photo_url:        string;
    score:            number;
    nearest_date?:    string;
    specializations?: string;
}

export interface QuickReply {
    label:         string;
    filter_date?:  string;           // filtruje wyniki po dacie
    consult_type?: 'pelno' | 'nisko'; // przełącza typ konsultacji i odpytuje dostępność
}

export interface PanelItem {
    type:              'psychologist' | 'article' | 'workshop' | 'group' | 'faq';
    id:                number;
    title:             string;
    url:               string;
    photo_url?:        string;
    tags?:             string;
    nearest_date?:     string;
    specializations?:  string;
    score:             number;
}
