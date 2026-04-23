/**
 * ai-chat.js — widget czatbota AI Niepodzielni
 *
 * Komunikuje się bezpośrednio z Cloudflare Worker (/chat).
 * Historia rozmowy trzymana w sessionStorage — przeżywa odświeżenie strony.
 * Worker URL i token dostarczane przez wp_localize_script (window.npAiChat).
 */

const STORAGE_KEY = 'np_ai_chat_history';
const MAX_HISTORY = 20; // max wiadomości wysyłanych do API

class NpAiChat {
    constructor(root) {
        this.root       = root;
        this.cfg        = window.npAiChat || {};
        this.workerUrl  = this.cfg.workerUrl || '';
        this.messages   = this._loadHistory();
        this.isOpen     = false;
        this.isTyping   = false;

        this._render();
        this._bindEvents();

        if (this.messages.length > 0) {
            this._renderHistory();
        }
    }

    // ── DOM ──────────────────────────────────────────────────────────────────

    _render() {
        this.root.innerHTML = `
<button class="np-chat__toggle" aria-label="Otwórz czat z asystentem" aria-expanded="false">
    <span class="np-chat__toggle-icon np-chat__toggle-icon--open">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M20 2H4C2.9 2 2 2.9 2 4v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z" fill="currentColor"/>
        </svg>
    </span>
    <span class="np-chat__toggle-icon np-chat__toggle-icon--close" hidden>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" fill="currentColor"/>
        </svg>
    </span>
</button>

<div class="np-chat__window" role="dialog" aria-label="Asystent Niepodzielni" hidden>
    <div class="np-chat__header">
        <div class="np-chat__header-info">
            <span class="np-chat__avatar" aria-hidden="true">🤝</span>
            <div>
                <strong class="np-chat__title">Asystent Niepodzielni</strong>
                <span class="np-chat__subtitle">Pomogę Ci znaleźć specjalistę</span>
            </div>
        </div>
        <button class="np-chat__clear" title="Wyczyść rozmowę" aria-label="Wyczyść historię rozmowy">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"/>
            </svg>
        </button>
    </div>

    <div class="np-chat__messages" role="log" aria-live="polite" aria-atomic="false">
        <div class="np-chat__welcome">
            <p>Cześć! Jestem asystentem Fundacji Niepodzielni. Pomogę Ci znaleźć odpowiedniego psychologa lub odpowiem na pytania dotyczące naszej oferty.</p>
            <p>O co chcesz zapytać? 👋</p>
        </div>
    </div>

    <div class="np-chat__typing" hidden aria-live="polite">
        <span></span><span></span><span></span>
    </div>

    <form class="np-chat__form" novalidate>
        <textarea
            class="np-chat__input"
            placeholder="Napisz wiadomość…"
            rows="1"
            maxlength="500"
            aria-label="Twoja wiadomość"
        ></textarea>
        <button type="submit" class="np-chat__send" aria-label="Wyślij wiadomość" disabled>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/>
            </svg>
        </button>
    </form>
</div>`;

        this.toggleBtn  = this.root.querySelector('.np-chat__toggle');
        this.window     = this.root.querySelector('.np-chat__window');
        this.messagesEl = this.root.querySelector('.np-chat__messages');
        this.typingEl   = this.root.querySelector('.np-chat__typing');
        this.form       = this.root.querySelector('.np-chat__form');
        this.input      = this.root.querySelector('.np-chat__input');
        this.sendBtn    = this.root.querySelector('.np-chat__send');
        this.clearBtn   = this.root.querySelector('.np-chat__clear');
        this.iconOpen   = this.root.querySelector('.np-chat__toggle-icon--open');
        this.iconClose  = this.root.querySelector('.np-chat__toggle-icon--close');
    }

    // ── Eventy ───────────────────────────────────────────────────────────────

    _bindEvents() {
        this.toggleBtn.addEventListener('click', () => this._toggle());

        this.form.addEventListener('submit', (e) => {
            e.preventDefault();
            this._send();
        });

        this.input.addEventListener('input', () => {
            this._autoResize();
            this.sendBtn.disabled = ! this.input.value.trim();
        });

        this.input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && ! e.shiftKey) {
                e.preventDefault();
                if (! this.sendBtn.disabled) this._send();
            }
        });

        this.clearBtn.addEventListener('click', () => this._clearHistory());
    }

    _toggle() {
        this.isOpen = ! this.isOpen;
        this.window.hidden          = ! this.isOpen;
        this.iconOpen.hidden        = this.isOpen;
        this.iconClose.hidden       = ! this.isOpen;
        this.toggleBtn.setAttribute('aria-expanded', String(this.isOpen));

        if (this.isOpen) {
            this._scrollToBottom();
            this.input.focus();
        }
    }

    // ── Wysyłanie ─────────────────────────────────────────────────────────────

    async _send() {
        const text = this.input.value.trim();
        if (! text || this.isTyping) return;

        this.messages.push({ role: 'user', content: text });
        this._saveHistory();
        this._appendMessage('user', text);

        this.input.value       = '';
        this.sendBtn.disabled  = true;
        this._autoResize();
        this._setTyping(true);

        try {
            const payload  = this.messages.slice(-MAX_HISTORY);
            const response = await fetch(`${this.workerUrl}/chat`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ messages: payload }),
            });

            if (! response.ok) throw new Error(`HTTP ${response.status}`);

            const data  = await response.json();
            const reply = data.reply || 'Przepraszam, coś poszło nie tak.';

            this.messages.push({ role: 'assistant', content: reply });
            this._saveHistory();
            this._appendMessage('assistant', reply);
        } catch {
            this._appendMessage('assistant', 'Przepraszam, nie mogę teraz odpowiedzieć. Spróbuj ponownie za chwilę lub skontaktuj się z nami bezpośrednio.', true);
        } finally {
            this._setTyping(false);
        }
    }

    // ── DOM helpers ───────────────────────────────────────────────────────────

    _appendMessage(role, text, isError = false) {
        const div = document.createElement('div');
        div.className = `np-chat__message np-chat__message--${role}${isError ? ' np-chat__message--error' : ''}`;
        div.innerHTML = `<div class="np-chat__bubble">${this._escapeHtml(text).replace(/\n/g, '<br>')}</div>`;
        this.messagesEl.appendChild(div);
        this._scrollToBottom();
    }

    _renderHistory() {
        const welcome = this.messagesEl.querySelector('.np-chat__welcome');
        if (welcome) welcome.remove();

        this.messages.forEach(({ role, content }) => {
            this._appendMessage(role, content);
        });
    }

    _scrollToBottom() {
        requestAnimationFrame(() => {
            this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
        });
    }

    _setTyping(active) {
        this.isTyping       = active;
        this.typingEl.hidden = ! active;
        this.sendBtn.disabled = active;
        if (active) this._scrollToBottom();
    }

    _autoResize() {
        this.input.style.height = 'auto';
        this.input.style.height = Math.min(this.input.scrollHeight, 120) + 'px';
    }

    _clearHistory() {
        this.messages = [];
        sessionStorage.removeItem(STORAGE_KEY);
        this.messagesEl.innerHTML = `
<div class="np-chat__welcome">
    <p>Cześć! Jestem asystentem Fundacji Niepodzielni. Pomogę Ci znaleźć odpowiedniego psychologa lub odpowiem na pytania dotyczące naszej oferty.</p>
    <p>O co chcesz zapytać? 👋</p>
</div>`;
    }

    _escapeHtml(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── sessionStorage ────────────────────────────────────────────────────────

    _loadHistory() {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch {
            return [];
        }
    }

    _saveHistory() {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(this.messages.slice(-MAX_HISTORY)));
        } catch {}
    }
}

// ── Init ─────────────────────────────────────────────────────────────────────

const root = document.getElementById('np-ai-chat');
if (root && window.npAiChat?.workerUrl) {
    new NpAiChat(root);
}
