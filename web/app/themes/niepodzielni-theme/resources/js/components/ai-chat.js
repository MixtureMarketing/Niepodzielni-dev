/**
 * ai-chat.js — widget czatbota AI Niepodzielni
 *
 * Komunikuje się z Cloudflare Worker przez SSE (streaming).
 * Historia: localStorage (przeżywa zamknięcie przeglądarki).
 * Onboarding: typ konsultacji → kategoria problemu → rozmowa.
 */

const STORAGE_KEY  = 'np_ai_chat_history';
const CONSULT_KEY  = 'np_ai_consult_type';
const MAX_HISTORY  = 20;

const PROBLEM_CHIPS = [
    'Depresja i obniżony nastrój',
    'Lęki i stres',
    'Trauma i PTSD',
    'Relacje i związki',
    'Wypalenie zawodowe',
    'Inne',
];

class NpAiChat {
    constructor(root) {
        this.root        = root;
        this.cfg         = window.npAiChat || {};
        this.workerUrl   = this.cfg.workerUrl || '';
        this.contact     = this.cfg.contact   || {};
        this.messages    = this._loadHistory();
        this.consultType = localStorage.getItem(CONSULT_KEY) || 'pelno';
        this.isOpen      = false;
        this.isTyping    = false;
        this.unread      = 0;

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
    <span class="np-chat__badge" hidden aria-label="Nowe wiadomości">0</span>
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
            <p>Cześć! Jestem asystentem Fundacji Niepodzielni. Pomogę Ci znaleźć odpowiedniego psychologa.</p>
            <p>Jak mogę Ci pomóc?</p>
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
        this.badgeEl    = this.root.querySelector('.np-chat__badge');
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
        this.window.hidden     = ! this.isOpen;
        this.iconOpen.hidden   = this.isOpen;
        this.iconClose.hidden  = ! this.isOpen;
        this.toggleBtn.setAttribute('aria-expanded', String(this.isOpen));

        if (this.isOpen) {
            this._clearBadge();
            this._scrollToBottom();
            this._track('chat_opened');

            if (this.messages.length === 0) {
                this._showOnboarding();
            } else {
                this.input.focus();
            }
        }
    }

    // ── Unread badge ──────────────────────────────────────────────────────────

    _addUnread() {
        if (this.isOpen) return;
        this.unread++;
        this.badgeEl.textContent = this.unread;
        this.badgeEl.hidden = false;
    }

    _clearBadge() {
        this.unread = 0;
        this.badgeEl.hidden = true;
    }

    // ── Onboarding ────────────────────────────────────────────────────────────

    _showOnboarding() {
        // Krok 1: typ konsultacji
        this._appendQuickReplies([
            { label: 'Konsultacja standardowa (pełnopłatna)', consult_type: 'pelno' },
            { label: 'Konsultacja niskopłatna (dla osób w trudnej sytuacji)', consult_type: 'nisko' },
        ], 'np-chat__onboarding np-chat__onboarding--consult');
        this._scrollToBottom();
    }

    _showProblemChips() {
        const msg = document.createElement('div');
        msg.className = 'np-chat__message np-chat__message--assistant';
        msg.innerHTML = '<div class="np-chat__bubble">Czego głównie dotyczy Twoja potrzeba?</div>';
        this.messagesEl.appendChild(msg);

        this._appendQuickReplies(
            PROBLEM_CHIPS.map(label => ({ label })),
            'np-chat__quick-replies np-chat__onboarding--problems',
        );
        this._scrollToBottom();
    }

    // ── Wysyłanie ─────────────────────────────────────────────────────────────

    async _send(filterDate = null) {
        const text = this.input.value.trim();
        if (! text || this.isTyping) return;

        this.messagesEl.querySelectorAll(
            '.np-chat__onboarding, .np-chat__quick-replies, .np-chat__onboarding--problems'
        ).forEach(el => el.remove());

        this.messages.push({ role: 'user', content: text });
        this._saveHistory();
        this._appendMessage('user', text);
        this._track('message_sent', { message: text.slice(0, 100) });

        this.input.value      = '';
        this.sendBtn.disabled = true;
        this._autoResize();
        this._setTyping(true);

        const payload = {
            messages:     this.messages.slice(-MAX_HISTORY),
            consult_type: this.consultType,
        };
        if (filterDate) payload.filter_date = filterDate;

        // Tworzymy bąbelek odpowiedzi — będzie uzupełniany przez streaming
        const bubbleEl = this._createStreamBubble();

        try {
            const response = await fetch(`${this.workerUrl}/chat`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            });

            if (! response.ok) throw new Error(`HTTP ${response.status}`);

            await this._readStream(response.body, bubbleEl, (data) => {
                // Callback na zdarzenie "done"
                let historyContent = data.reply || '';

                // Dołącz imiona + specjalizacje widocznych psychologów do historii
                // w formacie [SPECJALIŚCI: ...] — marker rozpoznawany przez Worker
                // (skip tool call) i system prompt (odpowiedź z historii bez check_availability)
                if (data.suggestions?.length) {
                    const names = data.suggestions.map(s => {
                        const spec = s.specializations ? ` (${s.specializations})` : '';
                        return `${s.name}${spec}`;
                    }).join('; ');
                    historyContent += ` [SPECJALIŚCI: ${names}]`;
                }

                if (historyContent) {
                    this.messages.push({ role: 'assistant', content: historyContent });
                    this._saveHistory();
                    this._addUnread();
                }

                if (data.quick_replies?.length) {
                    this._appendQuickReplies(data.quick_replies);
                }
                if (data.suggestions?.length) {
                    this._appendSuggestions(data.suggestions);
                }
                if (data.contact_fallback) {
                    this._appendContactFallback();
                }
            });

        } catch {
            bubbleEl.innerHTML = `<div class="np-chat__bubble np-chat__bubble--error">Przepraszam, nie mogę teraz odpowiedzieć. Spróbuj ponownie za chwilę.</div>`;
            this._appendContactFallback();
        } finally {
            this._setTyping(false);
            this.input.focus();
        }
    }

    // ── SSE streaming ─────────────────────────────────────────────────────────

    _createStreamBubble() {
        const div = document.createElement('div');
        div.className = 'np-chat__message np-chat__message--assistant';
        div.innerHTML = '<div class="np-chat__bubble np-chat__bubble--streaming"></div>';
        this.messagesEl.appendChild(div);
        this._scrollToBottom();
        return div.querySelector('.np-chat__bubble');
    }

    async _readStream(body, bubbleEl, onDone) {
        const reader  = body.getReader();
        const dec     = new TextDecoder();
        let   buf     = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buf += dec.decode(value, { stream: true });

            // Parsuj kompletne zdarzenia SSE (kończą się \n\n)
            const parts = buf.split('\n\n');
            buf = parts.pop(); // ostatni fragment może być niepełny

            for (const part of parts) {
                const line = part.replace(/^data:\s*/, '').trim();
                if (! line) continue;
                try {
                    const event = JSON.parse(line);
                    if (event.type === 'token') {
                        bubbleEl.classList.remove('np-chat__bubble--streaming');
                        bubbleEl.innerHTML += this._escapeHtml(event.token).replace(/\n/g, '<br>');
                        this._scrollToBottom();
                    } else if (event.type === 'done') {
                        // Pełny tekst — podmień innerHTML żeby uniknąć podwójnego escape
                        if (event.reply) {
                            bubbleEl.innerHTML = this._escapeHtml(event.reply).replace(/\n/g, '<br>');
                        }
                        onDone(event);
                    } else if (event.type === 'error') {
                        bubbleEl.textContent = 'Przepraszam, wystąpił błąd.';
                    }
                } catch {}
            }
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

    _appendQuickReplies(replies, extraClass = 'np-chat__quick-replies') {
        const wrap = document.createElement('div');
        wrap.className = extraClass.startsWith('np-chat__onboarding')
            ? `np-chat__quick-replies ${extraClass}`
            : `np-chat__quick-replies ${extraClass}`.trim();

        replies.forEach(item => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'np-chat__quick-reply';
            btn.textContent = item.label ?? item;

            btn.addEventListener('click', () => {
                wrap.remove();

                // Obsługa wyboru typu konsultacji (onboarding krok 1)
                if (item.consult_type) {
                    this.consultType = item.consult_type;
                    localStorage.setItem(CONSULT_KEY, item.consult_type);
                    this._showProblemChips();
                    return;
                }

                this.input.value      = item.label ?? item;
                this.sendBtn.disabled = false;
                this._send(item.filter_date ?? null);
            });

            wrap.appendChild(btn);
        });

        this.messagesEl.appendChild(wrap);
        this._scrollToBottom();
    }

    _appendSuggestions(suggestions) {
        const wrap = document.createElement('div');
        wrap.className = 'np-chat__suggestions';
        wrap.innerHTML = suggestions.map(s => {
            const dateStr = s.nearest_date
                ? `<span class="np-chat__psy-date">${this._formatDate(s.nearest_date)}</span>`
                : '';
            return `
            <a href="${this._escapeHtml(s.url)}" class="np-chat__psy-card" target="_blank" rel="noopener"
               data-psy-id="${s.id}" data-psy-name="${this._escapeHtml(s.name)}">
                ${s.photo_url
                    ? `<img src="${this._escapeHtml(s.photo_url)}" alt="${this._escapeHtml(s.name)}" class="np-chat__psy-photo" loading="lazy" width="48" height="48">`
                    : `<span class="np-chat__psy-avatar" aria-hidden="true">👤</span>`
                }
                <div class="np-chat__psy-info">
                    <span class="np-chat__psy-name">${this._escapeHtml(s.name)}</span>
                    ${dateStr}
                </div>
                <span class="np-chat__psy-btn">Umów →</span>
            </a>`;
        }).join('');

        // Tracking kliknięcia w kartę
        wrap.querySelectorAll('.np-chat__psy-card').forEach(card => {
            card.addEventListener('click', () => {
                this._track('psychologist_card_clicked', {
                    psychologist_id:   card.dataset.psyId,
                    psychologist_name: card.dataset.psyName,
                });
                this._track('booking_intent', {
                    psychologist_id:   card.dataset.psyId,
                    psychologist_name: card.dataset.psyName,
                });
            });
        });

        this.messagesEl.appendChild(wrap);
        this._scrollToBottom();
    }

    _appendContactFallback() {
        if (! this.contact.phone && ! this.contact.email && ! this.contact.formUrl) return;

        const wrap = document.createElement('div');
        wrap.className = 'np-chat__contact-fallback';

        if (this.contact.phone) {
            wrap.innerHTML += `
            <a href="tel:${this._escapeHtml(this.contact.phone)}" class="np-chat__contact-card">
                <span class="np-chat__contact-icon">📞</span>
                <div class="np-chat__contact-info">
                    <span class="np-chat__contact-label">Zadzwoń do nas</span>
                    <span class="np-chat__contact-value">${this._escapeHtml(this.contact.phone)}</span>
                </div>
            </a>`;
        }
        if (this.contact.email) {
            wrap.innerHTML += `
            <a href="mailto:${this._escapeHtml(this.contact.email)}" class="np-chat__contact-card">
                <span class="np-chat__contact-icon">✉️</span>
                <div class="np-chat__contact-info">
                    <span class="np-chat__contact-label">Napisz do nas</span>
                    <span class="np-chat__contact-value">${this._escapeHtml(this.contact.email)}</span>
                </div>
            </a>`;
        }
        if (this.contact.formUrl) {
            wrap.innerHTML += `
            <a href="${this._escapeHtml(this.contact.formUrl)}" class="np-chat__contact-card" target="_blank" rel="noopener">
                <span class="np-chat__contact-icon">📋</span>
                <div class="np-chat__contact-info">
                    <span class="np-chat__contact-label">Formularz kontaktowy</span>
                    <span class="np-chat__contact-value">niepodzielni.pl/kontakt</span>
                </div>
            </a>`;
        }

        this.messagesEl.appendChild(wrap);
        this._scrollToBottom();
    }

    _renderHistory() {
        const welcome = this.messagesEl.querySelector('.np-chat__welcome');
        if (welcome) welcome.remove();
        this.messages.forEach(({ role, content }) => this._appendMessage(role, content));
    }

    _scrollToBottom() {
        requestAnimationFrame(() => {
            this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
        });
    }

    _setTyping(active) {
        this.isTyping        = active;
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
        localStorage.removeItem(STORAGE_KEY);
        this.messagesEl.innerHTML = `
<div class="np-chat__welcome">
    <p>Cześć! Jestem asystentem Fundacji Niepodzielni. Pomogę Ci znaleźć odpowiedniego psychologa.</p>
    <p>Jak mogę Ci pomóc?</p>
</div>`;
        this._showOnboarding();
    }

    _formatDate(iso) {
        const d = new Date(iso);
        return d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' });
    }

    _escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Tracking ──────────────────────────────────────────────────────────────

    _track(event, props = {}) {
        try {
            if (typeof zaraz !== 'undefined') {
                zaraz.track(event, props);
            } else if (window.dataLayer) {
                window.dataLayer.push({ event, ...props });
            }
        } catch {}
    }

    // ── localStorage ──────────────────────────────────────────────────────────

    _loadHistory() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch {
            return [];
        }
    }

    _saveHistory() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.messages.slice(-MAX_HISTORY)));
        } catch {}
    }
}

// ── Init ─────────────────────────────────────────────────────────────────────

const root = document.getElementById('np-ai-chat');
if (root && window.npAiChat?.workerUrl) {
    new NpAiChat(root);
}
