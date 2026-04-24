export interface Env {
    AI:               Ai;
    VECTORIZE_PSY:    VectorizeIndex;
    VECTORIZE_FAQ:    VectorizeIndex;
    GATEWAY_BASE_URL: string;
    CHAT_MODEL:       string;
    EMBED_MODEL:      string;
    WP_API_URL:       string;
    CF_AIG_TOKEN:     string;
    WORKER_SECRET:    string;
    WP_BOT_TOKEN:     string;
}

export interface SyncPayload {
    id:        number;
    type:      'psycholog' | 'faq';
    title:     string;
    content:   string;
    url:       string;
    photo_url?: string;
    meta?:     Record<string, string[]>;
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
    specializations?: string; // comma-separated flattened meta fields (specjalizacje, nurty, obszary)
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
    label:        string;
    filter_date?: string; // jeśli ustawione, frontend wysyła je z filter_date
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
