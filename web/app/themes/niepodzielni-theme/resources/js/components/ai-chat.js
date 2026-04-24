/**
 * ai-chat.js — widget czatbota AI Niepodzielni
 *
 * Komunikuje się z Cloudflare Worker przez SSE (streaming).
 * Historia: localStorage (przeżywa zamknięcie przeglądarki).
 * Onboarding: typ konsultacji → kategoria problemu → rozmowa.
 */

const STORAGE_KEY  = 'np_ai_chat_history';
const CONSULT_KEY  = 'np_ai_consult_type';
const PANEL_KEY    = 'np_ai_panel_items';
const MAX_HISTORY  = 20;

// Lodołamacze — zastępują dwuetapowy onboarding, każdy automatycznie ustawia consult_type
const ICEBREAKERS = [
    { label: 'Szukam pomocy przy depresji lub obniżonym nastroju', consult_type: 'pelno' },
    { label: 'Potrzebuję kogoś od lęków, stresu lub ataków paniki', consult_type: 'pelno' },
    { label: 'Szukam tańszej lub bezpłatnej pomocy psychologicznej', consult_type: 'nisko' },
    { label: 'Jak wybrać dobrego psychologa dla siebie?', consult_type: 'pelno' },
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
            const saved = this._loadPanelState();
            if (saved.length) this._updatePanel(saved);
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
        <div class="np-chat__header-right">
            <button class="np-chat__panel-toggle" hidden aria-label="Pokaż dopasowanych specjalistów">
                👥 <span class="np-chat__panel-count">0</span>
            </button>
            <button class="np-chat__clear" title="Wyczyść rozmowę" aria-label="Wyczyść historię rozmowy">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"/>
                </svg>
            </button>
        </div>
    </div>

    <div class="np-chat__gdpr" role="note">
        🔒 Nie podawaj imienia, PESEL ani adresu — rozmowa służy wyłącznie do znalezienia specjalisty.
    </div>

    <div class="np-chat__body">
        <div class="np-chat__conversation">
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
        </div>

        <aside class="np-chat__panel" aria-label="Dopasowani specjaliści">
            <div class="np-chat__panel-header">
                <span>Specjaliści</span>
                <button class="np-chat__panel-close" title="Zamknij" aria-label="Zamknij panel">✕</button>
            </div>
            <div class="np-chat__panel-content">
                <p class="np-chat__panel-empty">Tu pojawią się specjaliści dopasowani do Twojego pytania.</p>
            </div>
        </aside>
    </div>
</div>`;

        this.toggleBtn      = this.root.querySelector('.np-chat__toggle');
        this.badgeEl        = this.root.querySelector('.np-chat__badge');
        this.window         = this.root.querySelector('.np-chat__window');
        this.messagesEl     = this.root.querySelector('.np-chat__messages');
        this.typingEl       = this.root.querySelector('.np-chat__typing');
        this.form           = this.root.querySelector('.np-chat__form');
        this.input          = this.root.querySelector('.np-chat__input');
        this.sendBtn        = this.root.querySelector('.np-chat__send');
        this.clearBtn       = this.root.querySelector('.np-chat__clear');
        this.iconOpen       = this.root.querySelector('.np-chat__toggle-icon--open');
        this.iconClose      = this.root.querySelector('.np-chat__toggle-icon--close');
        this.panelEl        = this.root.querySelector('.np-chat__panel');
        this.panelContent   = this.root.querySelector('.np-chat__panel-content');
        this.panelToggleBtn = this.root.querySelector('.np-chat__panel-toggle');
        this.panelCountEl   = this.root.querySelector('.np-chat__panel-count');
        this.panelCloseBtn  = this.root.querySelector('.np-chat__panel-close');
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

        this.panelToggleBtn.addEventListener('click', () => {
            this.panelEl.classList.toggle('np-chat__panel--visible');
        });

        this.panelCloseBtn.addEventListener('click', () => {
            this.panelEl.classList.remove('np-chat__panel--visible');
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) this._toggle();
        });
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

    // ── Onboarding / Icebreakers ──────────────────────────────────────────────

    _showOnboarding() {
        this._appendQuickReplies(ICEBREAKERS, 'np-chat__onboarding');
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
                // onDone callback
                // Callback na zdarzenie "done"
                let historyContent = data.reply || '';

                // Dołącz imiona + specjalizacje widocznych psychologów do historii
                // w formacie [SPECJALIŚCI: ...] — marker rozpoznawany przez Worker
                // (skip tool call) i system prompt (odpowiedź z historii bez check_availability)
                if (data.suggestions?.length) {
                    const psychologists = data.suggestions.filter(s => !s.type || s.type === 'psychologist');
                    if (psychologists.length) {
                        const names = psychologists.map(s => {
                            const title = s.title ?? s.name ?? '';
                            const spec  = s.specializations ? ` (${s.specializations})` : '';
                            return `${title}${spec}`;
                        }).join('; ');
                        historyContent += ` [SPECJALIŚCI: ${names}]`;
                    }
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
                    this._updatePanel(data.suggestions);
                }
                if (data.contact_fallback) {
                    this._appendContactFallback();
                }
            });

        } catch {
            bubbleEl.classList.remove('np-chat__bubble--streaming');
            bubbleEl.classList.add('np-chat__bubble--error');
            bubbleEl.textContent = 'Przepraszam, nie mogę teraz odpowiedzieć. Spróbuj ponownie za chwilę.';
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
        let   streaming = false; // czy przyszedł już pierwszy token

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
                        if (! streaming) {
                            // Pierwszy token — ukryj typing dots, usuń kursor streamingu
                            streaming = true;
                            this.typingEl.hidden = true;
                            bubbleEl.classList.remove('np-chat__bubble--streaming');
                        }
                        bubbleEl.innerHTML += this._escapeHtml(event.token).replace(/\n/g, '<br>');
                        this._scrollToBottom();
                    } else if (event.type === 'done') {
                        if (event.crisis) {
                            // Usuń pusty bąbelek streamingu i pokaż panel kryzysowy
                            bubbleEl.closest('.np-chat__message')?.remove();
                            this._renderCrisisPanel();
                            onDone({ ...event, reply: '', suggestions: [], quick_replies: [] });
                            return;
                        }
                        if (event.farewell) {
                            // Zachowaj bąbelek z pożegnaniem i pokaż widget oceny
                            bubbleEl.classList.remove('np-chat__bubble--streaming');
                            if (event.reply) bubbleEl.innerHTML = this._renderMarkdown(event.reply);
                            this._renderRatingWidget();
                            onDone({ ...event, suggestions: [], quick_replies: [] });
                            return;
                        }
                        // Pełny tekst z markdown — zawsze podmień innerHTML (nadpisuje błędnie zaStreamowane nazwy narzędzi)
                        bubbleEl.classList.remove('np-chat__bubble--streaming');
                        if (event.reply) {
                            bubbleEl.innerHTML = this._renderMarkdown(event.reply);
                        } else {
                            bubbleEl.closest('.np-chat__message')?.remove();
                        }
                        onDone(event);
                    } else if (event.type === 'error') {
                        bubbleEl.classList.remove('np-chat__bubble--streaming');
                        bubbleEl.classList.add('np-chat__bubble--error');
                        bubbleEl.textContent = 'Przepraszam, wystąpił błąd.';
                    }
                } catch {}
            }
        }
    }

    // ── DOM helpers ───────────────────────────────────────────────────────────

    _appendMessage(role, text, isError = false) {
        const display = role === 'assistant'
            ? text.replace(/\s*\[SPECJALIŚCI:[^\]]*\]/g, '').trim()
            : text;
        const div = document.createElement('div');
        div.className = `np-chat__message np-chat__message--${role}${isError ? ' np-chat__message--error' : ''}`;
        div.innerHTML = `<div class="np-chat__bubble">${this._renderMarkdown(display)}</div>`;
        this.messagesEl.appendChild(div);
        this._scrollToBottom();
    }

    _appendQuickReplies(replies, extraClasses = '') {
        const wrap = document.createElement('div');
        wrap.className = ['np-chat__quick-replies', extraClasses].filter(Boolean).join(' ');

        replies.forEach(item => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'np-chat__quick-reply';
            btn.textContent = item.label ?? item;

            btn.addEventListener('click', () => {
                if (this.isTyping) return;
                wrap.remove();

                if (item.consult_type) {
                    this.consultType = item.consult_type;
                    localStorage.setItem(CONSULT_KEY, item.consult_type);
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
            const t = s.title ?? s.name ?? '';
            return `
            <a href="${this._escapeHtml(s.url)}" class="np-chat__psy-card" target="_blank" rel="noopener"
               data-psy-id="${s.id}" data-psy-name="${this._escapeHtml(t)}">
                ${s.photo_url
                    ? `<img src="${this._escapeHtml(s.photo_url)}" alt="${this._escapeHtml(t)}" class="np-chat__psy-photo" loading="lazy" width="48" height="48">`
                    : `<span class="np-chat__psy-avatar" aria-hidden="true">👤</span>`
                }
                <div class="np-chat__psy-info">
                    <span class="np-chat__psy-name">${this._escapeHtml(t)}</span>
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

    _renderCrisisPanel() {
        const div = document.createElement('div');
        div.className = 'np-chat__crisis';
        div.innerHTML = `
            <strong class="np-chat__crisis-title">Wygląda na to, że to może być trudna chwila.</strong>
            <p class="np-chat__crisis-desc">Proszę zadzwoń lub napisz do kogoś, kto może pomóc teraz:</p>
            <a href="tel:116123" class="np-chat__crisis-line">
                <span class="np-chat__crisis-icon">📞</span>
                <span>Telefon Zaufania dla Dorosłych — <strong>116 123</strong><br><small>bezpłatny, całą dobę</small></span>
            </a>
            <a href="tel:116111" class="np-chat__crisis-line">
                <span class="np-chat__crisis-icon">📞</span>
                <span>Telefon Zaufania dla Dzieci i Młodzieży — <strong>116 111</strong></span>
            </a>
            <a href="tel:8007022222" class="np-chat__crisis-line">
                <span class="np-chat__crisis-icon">📞</span>
                <span>Centrum Wsparcia w Kryzysie Psychicznym — <strong>800 70 2222</strong><br><small>bezpłatny</small></span>
            </a>
            <a href="tel:112" class="np-chat__crisis-line np-chat__crisis-line--alert">
                <span class="np-chat__crisis-icon">🚨</span>
                <span>Zagrożenie życia — <strong>112</strong></span>
            </a>`;
        this.messagesEl.appendChild(div);
        this._scrollToBottom();
    }

    _renderRatingWidget() {
        const div = document.createElement('div');
        div.className = 'np-chat__farewell';
        div.innerHTML = `
            <p class="np-chat__farewell-ask">Jak oceniasz tę rozmowę?</p>
            <div class="np-chat__stars" role="group" aria-label="Ocena rozmowy">
                ${[1, 2, 3, 4, 5].map(n =>
                    `<button class="np-chat__star" data-value="${n}" aria-label="${n} ${n === 1 ? 'gwiazdka' : n < 5 ? 'gwiazdki' : 'gwiazdek'}">★</button>`
                ).join('')}
            </div>`;

        const stars = div.querySelectorAll('.np-chat__star');
        stars.forEach(btn => {
            btn.addEventListener('mouseover', () => this._highlightStars(div, +btn.dataset.value));
            btn.addEventListener('mouseout',  () => this._highlightStars(div, 0));
            btn.addEventListener('click',     () => this._submitRating(div, +btn.dataset.value));
        });

        this.messagesEl.appendChild(div);
        this._scrollToBottom();
    }

    _highlightStars(container, upTo) {
        container.querySelectorAll('.np-chat__star').forEach((btn, i) => {
            btn.classList.toggle('np-chat__star--active', i < upTo);
        });
    }

    async _submitRating(container, value) {
        this._highlightStars(container, value);
        container.querySelectorAll('.np-chat__star').forEach(btn => {
            btn.disabled = true;
        });

        const thanks = document.createElement('p');
        thanks.className = 'np-chat__farewell-thanks';
        thanks.textContent = value >= 4
            ? 'Dziękujemy za ocenę! Miło nam, że mogliśmy pomóc 💚'
            : 'Dziękujemy za opinię. Staramy się być coraz lepsi!';
        container.appendChild(thanks);
        this._scrollToBottom();

        try {
            await fetch(`${this.workerUrl}/feedback`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ value, type: 'conversation_rating' }),
            });
        } catch {}

        this._track('conversation_rated', { value });
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

    // ── Panel boczny ──────────────────────────────────────────────────────────

    _updatePanel(suggestions) {
        this.panelContent.innerHTML = '';

        if (!suggestions?.length) {
            this.panelContent.innerHTML = '<p class="np-chat__panel-empty">Tu pojawią się specjaliści dopasowani do Twojego pytania.</p>';
            this.panelToggleBtn.hidden = true;
            this._savePanelState([]);
            return;
        }

        const psyAvail   = suggestions.filter(s => (!s.type || s.type === 'psychologist') && s.nearest_date);
        const psyUnavail = suggestions.filter(s => (!s.type || s.type === 'psychologist') && !s.nearest_date);
        const content    = suggestions.filter(s => s.type && s.type !== 'psychologist');

        // Update panel header title dynamically
        const hasPsy     = psyAvail.length + psyUnavail.length > 0;
        const hasContent = content.length > 0;
        const panelTitle = this.panelEl.querySelector('.np-chat__panel-header span');
        if (panelTitle) {
            if (hasPsy && !hasContent) panelTitle.textContent = 'Specjaliści';
            else if (!hasPsy && hasContent) panelTitle.textContent = 'Materiały i zasoby';
            else panelTitle.textContent = 'Dopasowane zasoby';
        }

        // 1. Psycholodzy z wolnymi terminami — na górze
        psyAvail.forEach(s => this.panelContent.appendChild(this._createPsyCard(s, true)));

        // 2. Treści (artykuły, warsztaty, grupy)
        if (content.length) {
            if (psyAvail.length) {
                const div = document.createElement('div');
                div.className = 'np-chat__panel-divider';
                this.panelContent.appendChild(div);
            }
            content.forEach(s => this.panelContent.appendChild(this._createContentCard(s)));
        }

        // 3. Psycholodzy bez terminów — tylko gdy brak dostępnych, na dole
        if (psyUnavail.length && psyAvail.length === 0) {
            if (content.length) {
                const div = document.createElement('div');
                div.className = 'np-chat__panel-divider';
                this.panelContent.appendChild(div);
            }
            const notice = document.createElement('p');
            notice.className = 'np-chat__panel-unavail-notice';
            notice.textContent = 'Brak wolnych terminów w najbliższym czasie';
            this.panelContent.appendChild(notice);
            psyUnavail.forEach(s => this.panelContent.appendChild(this._createPsyCard(s, false)));
        }

        this.panelCountEl.textContent = suggestions.length;
        this.panelToggleBtn.hidden    = false;
        this._savePanelState(suggestions);
    }

    _createPsyCard(s, available) {
        const title = s.title ?? s.name ?? '';
        const card  = document.createElement('a');
        card.href   = s.url;
        card.target = '_blank';
        card.rel    = 'noopener';
        card.className       = 'np-chat__psy-card' + (available ? '' : ' np-chat__psy-card--unavailable');
        card.dataset.psyId   = s.id;
        card.dataset.psyName = title;

        const dateStr = available && s.nearest_date
            ? `<span class="np-chat__psy-date">${this._formatDate(s.nearest_date)}</span>`
            : !available ? `<span class="np-chat__psy-no-date">Brak wolnych terminów</span>` : '';

        card.innerHTML = `
            ${s.photo_url
                ? `<img src="${this._escapeHtml(s.photo_url)}" alt="${this._escapeHtml(title)}" class="np-chat__psy-photo" loading="lazy" width="38" height="38">`
                : `<span class="np-chat__psy-avatar" aria-hidden="true">👤</span>`
            }
            <div class="np-chat__psy-info">
                <span class="np-chat__psy-name">${this._escapeHtml(title)}</span>
                ${dateStr}
            </div>
            <span class="np-chat__psy-btn">${available ? 'Umów →' : 'Profil →'}</span>`;

        card.addEventListener('click', () => {
            this._track('psychologist_card_clicked', {
                psychologist_id:   card.dataset.psyId,
                psychologist_name: card.dataset.psyName,
            });
        });
        return card;
    }

    _createContentCard(s) {
        const title     = s.title ?? s.name ?? '';
        const typeLabel = s.type === 'workshop' ? 'Warsztat'
            : s.type === 'group' ? 'Grupa wsparcia'
            : s.type === 'article' ? 'Artykuł'
            : 'FAQ';
        const dateStr = s.nearest_date
            ? `<span class="np-chat__psy-date">${this._formatDate(s.nearest_date)}</span>`
            : '';
        const tagsStr = s.tags
            ? `<span class="np-chat__content-tags">${this._escapeHtml(s.tags)}</span>`
            : '';

        const card = document.createElement('a');
        card.href      = s.url;
        card.target    = '_blank';
        card.rel       = 'noopener';
        card.className = 'np-chat__content-card';
        card.innerHTML = `
            <div class="np-chat__content-card-top">
                <span class="np-chat__content-type">${typeLabel}</span>
                <span class="np-chat__psy-btn">Sprawdź →</span>
            </div>
            <span class="np-chat__content-name">${this._escapeHtml(title)}</span>
            ${dateStr}${tagsStr}`;
        return card;
    }

    _savePanelState(items) {
        try { localStorage.setItem(PANEL_KEY, JSON.stringify(items)); } catch {}
    }

    _loadPanelState() {
        try { return JSON.parse(localStorage.getItem(PANEL_KEY) ?? '[]'); } catch { return []; }
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
        this.isTyping         = active;
        this.typingEl.hidden  = ! active;
        this.sendBtn.disabled = active;
        // Blokuj / odblokuj quick reply buttons żeby nie wysyłać podwójnych requestów
        this.messagesEl.querySelectorAll('.np-chat__quick-reply').forEach(btn => {
            btn.disabled = active;
        });
        if (active) this._scrollToBottom();
    }

    _autoResize() {
        this.input.style.height = 'auto';
        this.input.style.height = Math.min(this.input.scrollHeight, 120) + 'px';
    }

    _clearHistory() {
        this.messages = [];
        localStorage.removeItem(STORAGE_KEY);
        localStorage.removeItem(PANEL_KEY);
        this.messagesEl.innerHTML = `
<div class="np-chat__welcome">
    <p>Cześć! Jestem asystentem Fundacji Niepodzielni. Pomogę Ci znaleźć odpowiedniego psychologa.</p>
    <p>Jak mogę Ci pomóc?</p>
</div>`;
        this._updatePanel([]);
        this._showOnboarding();
    }

    _formatDate(iso) {
        const d = new Date(iso);
        return d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' });
    }

    _renderMarkdown(text) {
        const html = this._escapeHtml(text);
        return html
            // Bold **text**
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            // Italic *text*
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            // Bullet lists: "- item" lub "• item" na początku linii
            .replace(/(^|<br>)[-•] (.+)/g, '$1<span class="np-chat__li">$2</span>')
            // Newlines
            .replace(/\n/g, '<br>');
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
