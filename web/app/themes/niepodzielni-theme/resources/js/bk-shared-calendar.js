/**
 * Shared Bookero Calendar — 3-column UX
 *
 * Krok 1: Kliknij dzień    → środkowa kolumna: lista godzin
 * Krok 2: Kliknij godzinę  → prawa kolumna: specjaliści dostępni o tej godzinie
 * Krok 3: Kliknij specj.   → formularz rezerwacji
 *
 * Stan domyślny (brak wybranego dnia): prawa kolumna pokazuje 5 najbliższych terminów.
 *
 * Mount point:  <div class="bk-shared-cal" data-typ="nisko|pelno"></div>
 * Requires:     window.niepodzielniBookero.{ajaxUrl, nonce}
 */

const DAYS_PL   = ['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'Sb', 'Nd'];
const DAY_NAMES = ['Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota', 'Niedziela'];
const MONTHS_PL = ['', 'Stycznia', 'Lutego', 'Marca', 'Kwietnia', 'Maja', 'Czerwca',
                   'Lipca', 'Sierpnia', 'Września', 'Października', 'Listopada', 'Grudnia'];

const STALE_THRESHOLD_SEC = 20 * 60;
const DEFAULT_PANEL_LIMIT = 5;

// Progi wskaźników dostępności (liczba psychologów w danym dniu)
const AVAIL_HIGH   = 6; // >= zielony
const AVAIL_MEDIUM = 2; // >= żółty; < AVAIL_MEDIUM = czerwony

class BkSharedCalendar {
    constructor(el) {
        this.el           = el;
        this.typ          = el.dataset.typ || 'nisko';
        this.plusMonths   = 0;
        this.data         = null;
        this.activeDate   = null;
        this.activeHour   = null;
        this._slotWorkers = []; // workers[] z bk_get_date_slots dla activeDate

        this._render();
        this._fetch();
    }

    // ─── Skeleton ─────────────────────────────────────────────────────────────

    _render() {
        this.el.innerHTML = `
            <div class="bk-sc__layout">
                <div class="bk-sc__cal-col">
                    <div class="bk-sc__nav">
                        <button class="bk-sc__prev" aria-label="Poprzedni miesiąc">&#8249;</button>
                        <span  class="bk-sc__title">Ładowanie…</span>
                        <button class="bk-sc__next" aria-label="Następny miesiąc">&#8250;</button>
                    </div>
                    <div class="bk-sc__weekdays">${DAYS_PL.map(d => `<span>${d}</span>`).join('')}</div>
                    <div class="bk-sc__grid"></div>
                </div>
                <div class="bk-sc__hours-col" hidden>
                    <div class="bk-sc__hours-header">
                        <span class="bk-sc__hours-date-label"></span>
                        <button class="bk-sc__hours-clear">&#8249; Zmień datę</button>
                    </div>
                    <div class="bk-sc__hours-list"></div>
                </div>
                <div class="bk-sc__panel">
                    <div class="bk-sc__panel-cards-wrap">
                        <div class="bk-sc__panel-header"></div>
                        <div class="bk-sc__panel-cards"></div>
                    </div>
                    <div class="bk-sc__booking-inner" hidden></div>
                </div>
            </div>
        `;

        this.q('.bk-sc__prev').addEventListener('click', () => this._changeMonth(-1));
        this.q('.bk-sc__next').addEventListener('click', () => this._changeMonth(+1));
        this.q('.bk-sc__hours-clear').addEventListener('click', () => this._clearDate());
    }

    // ─── Data ─────────────────────────────────────────────────────────────────

    _fetch() {
        this._setLoading(true);
        const cfg  = window.niepodzielniBookero || {};
        const body = new FormData();
        body.append('action',      'bk_get_shared_month');
        body.append('nonce',       cfg.nonce || '');
        body.append('typ',         this.typ);
        body.append('plus_months', this.plusMonths);

        fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body })
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error('API error');
                if (!res.data || !res.data.dates) {
                    this.q('.bk-sc__title').textContent = '—';
                    this.q('.bk-sc__grid').innerHTML =
                        '<p class="bk-sc__info">Terminy są aktualizowane. Odśwież stronę za chwilę.</p>';
                    this.q('.bk-sc__panel-cards').innerHTML = '';
                    return;
                }
                this.data = res.data;
                this._renderMonth();
            })
            .catch(() => {
                this.q('.bk-sc__title').textContent = '—';
                this.q('.bk-sc__grid').innerHTML =
                    '<p class="bk-sc__error">Nie udało się pobrać terminów. Spróbuj ponownie.</p>';
                this.q('.bk-sc__panel-cards').innerHTML = '';
            })
            .finally(() => this._setLoading(false));
    }

    _changeMonth(delta) {
        this.plusMonths   = Math.max(0, this.plusMonths + delta);
        this.activeDate   = null;
        this.activeHour   = null;
        this._slotWorkers = [];
        this._closeHoursCol();
        this._fetch();
    }

    // ─── Month grid ───────────────────────────────────────────────────────────

    _renderMonth() {
        const d = this.data;
        this.q('.bk-sc__title').textContent = d.month_name;
        this.q('.bk-sc__prev').disabled     = (this.plusMonths === 0);

        const now   = Math.floor(Date.now() / 1000);
        const stale = d.oldest_sync > 0 && (now - d.oldest_sync) > STALE_THRESHOLD_SEC;
        const grid  = this.q('.bk-sc__grid');
        grid.innerHTML = stale ? '<p class="bk-sc__stale">Terminy aktualizowane co kilka minut</p>' : '';

        for (let i = 1; i < d.first_dow; i++) {
            const blank = document.createElement('span');
            blank.className = 'bk-sc__day bk-sc__day--blank';
            grid.appendChild(blank);
        }

        for (let day = 1; day <= d.days_in_month; day++) {
            const dateStr = `${d.year_month}-${String(day).padStart(2, '0')}`;
            const avail   = d.dates[dateStr];
            const btn     = document.createElement('button');

            btn.className    = 'bk-sc__day';
            btn.textContent  = day;
            btn.dataset.date = dateStr;

            if (dateStr < d.today) {
                btn.classList.add('bk-sc__day--past');
                btn.disabled = true;
            } else if (avail && avail.length) {
                btn.classList.add('bk-sc__day--available');
                const n = avail.length;
                btn.classList.add(n >= AVAIL_HIGH ? 'bk-sc__day--avail-high'
                                : n >= AVAIL_MEDIUM ? 'bk-sc__day--avail-medium'
                                : 'bk-sc__day--avail-low');
                btn.addEventListener('click', () => this._selectDate(dateStr));
            } else {
                btn.classList.add('bk-sc__day--empty');
                btn.disabled = true;
            }

            if (dateStr === d.today)        btn.classList.add('bk-sc__day--today');
            if (dateStr === this.activeDate) btn.classList.add('bk-sc__day--selected');
            grid.appendChild(btn);
        }

        // Przywróć stan gdy np. zmieniono miesiąc z powrotem
        if (this.activeDate && this._slotWorkers.length) {
            this._openHoursCol(this.activeDate, this._slotWorkers);
            if (this.activeHour) this._selectHour(this.activeHour, this._slotWorkers);
        } else if (!this.activeDate) {
            // Przy pierwszym załadowaniu / po zmianie miesiąca — auto-wybierz najbliższy dzień
            const firstDate = Object.keys(d.dates).filter(dt => dt >= d.today).sort()[0];
            if (firstDate) {
                this._selectDate(firstDate);
            } else {
                this._renderNearestPanel();
            }
        }
    }

    // ─── Krok 1: Wybór dnia ───────────────────────────────────────────────────

    _selectDate(dateStr) {
        if (this.activeDate === dateStr) {
            this._clearDate();
            return;
        }

        this.activeDate   = dateStr;
        this.activeHour   = null;
        this._slotWorkers = [];

        // Zaznacz dzień w siatce
        this.el.querySelectorAll('.bk-sc__day--selected')
            .forEach(el => el.classList.remove('bk-sc__day--selected'));
        const btn = this.el.querySelector(`[data-date="${dateStr}"]`);
        if (btn) btn.classList.add('bk-sc__day--selected');

        // Pokaż kolumnę godzin ze stanem ładowania
        this.q('.bk-sc__hours-date-label').textContent = this._formatDate(dateStr);
        this.q('.bk-sc__hours-list').innerHTML = '<p class="bk-sc__info">Ładowanie godzin…</p>';
        this._openHoursCol(dateStr, null);

        // Prompt w prawej kolumnie — poczekaj na wybór godziny
        this.q('.bk-sc__panel-header').innerHTML = '';
        this.q('.bk-sc__panel-cards').innerHTML  =
            '<p class="bk-sc__info bk-sc__info--prompt">Wybierz godzinę, aby zobaczyć specjalistów</p>';

        // Pobierz sloty dla dnia
        const cfg  = window.niepodzielniBookero || {};
        const body = new FormData();
        body.append('action', 'bk_get_date_slots');
        body.append('nonce',  cfg.nonce || '');
        body.append('typ',    this.typ);
        body.append('date',   dateStr);

        fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body })
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error('API error');
                this._slotWorkers = res.data.workers || [];
                this._openHoursCol(dateStr, this._slotWorkers);
            })
            .catch(() => {
                this.q('.bk-sc__hours-list').innerHTML =
                    '<p class="bk-sc__error">Nie udało się pobrać godzin. Spróbuj ponownie.</p>';
            });
    }

    _clearDate() {
        this.activeDate   = null;
        this.activeHour   = null;
        this._slotWorkers = [];
        this.el.querySelectorAll('.bk-sc__day--selected')
            .forEach(el => el.classList.remove('bk-sc__day--selected'));
        this._closeHoursCol();
        this._renderNearestPanel();
    }

    // ─── Kolumna godzin ───────────────────────────────────────────────────────

    _openHoursCol(dateStr, workers) {
        const hoursCol = this.q('.bk-sc__hours-col');
        this.q('.bk-sc__hours-date-label').textContent = this._formatDate(dateStr);
        hoursCol.hidden = false;
        this.q('.bk-sc__layout').classList.add('bk-sc__layout--hours-open');

        if (workers === null) return; // ładowanie — lista zostanie wypełniona później

        const list = this.q('.bk-sc__hours-list');
        list.innerHTML = '';

        // Zbierz unikalne godziny ze wszystkich pracowników, posortuj
        const hourSet = new Set();
        workers.forEach(w => (w.hours || []).forEach(h => hourSet.add(h)));
        const hours = [...hourSet].sort();

        if (!hours.length) {
            list.innerHTML = '<p class="bk-sc__info">Brak dostępnych godzin.</p>';
            return;
        }

        hours.forEach(h => {
            const btn = document.createElement('button');
            btn.className   = 'bk-sc__hour-btn';
            btn.textContent = h;
            if (h === this.activeHour) btn.classList.add('bk-sc__hour-btn--active');
            btn.addEventListener('click', () => this._selectHour(h, workers));
            list.appendChild(btn);
        });

        // Auto-zaznacz pierwszą dostępną godzinę
        this._selectHour(hours[0], workers);
    }

    _closeHoursCol() {
        this.q('.bk-sc__hours-col').hidden = true;
        this.q('.bk-sc__hours-list').innerHTML = '';
        this.q('.bk-sc__layout').classList.remove('bk-sc__layout--hours-open');
    }

    // ─── Krok 2: Wybór godziny ────────────────────────────────────────────────

    _selectHour(hour, workers) {
        this.activeHour = hour;

        // Podświetl aktywny przycisk godziny
        this.q('.bk-sc__hours-list').querySelectorAll('.bk-sc__hour-btn')
            .forEach(b => b.classList.toggle('bk-sc__hour-btn--active', b.textContent === hour));

        // Filtruj specjalistów do tych dostępnych o tej godzinie
        const matched = workers.filter(w => (w.hours || []).includes(hour));

        const header = this.q('.bk-sc__panel-header');
        const cards  = this.q('.bk-sc__panel-cards');

        header.innerHTML = `<span>${this._formatDate(this.activeDate)} · <strong>${hour}</strong></span>`;
        cards.innerHTML  = '';

        if (!matched.length) {
            cards.innerHTML = '<p class="bk-sc__info">Brak specjalistów o tej godzinie.</p>';
            return;
        }

        matched.forEach(w => cards.appendChild(this._buildWorkerCard(this.activeDate, hour, w)));

        // Background verification — sprawdź aktualność terminów w API (z debounce 250ms)
        clearTimeout(this._verifyDebounce);
        if (this._verifyAbort) this._verifyAbort.abort();
        const toVerify = matched.filter(w => w.bookero_id).map(w => w.bookero_id);
        if (toVerify.length) {
            this._verifyDebounce = setTimeout(() => this._verifyHourSlots(hour, toVerify), 250);
        }
    }

    // ─── Weryfikacja dostępności godziny w tle ────────────────────────────────

    _verifyHourSlots(hour, bookeroIds) {
        // Snapshot stanu — odpowiedź przetworzymy tylko jeśli stan się nie zmienił
        const snapshotDate = this.activeDate;
        const snapshotHour = hour;

        // Anuluj poprzedni request jeśli trwa
        if (this._verifyAbort) this._verifyAbort.abort();
        this._verifyAbort = new AbortController();

        const cards = this.q('.bk-sc__panel-cards');
        cards.classList.add('bk-sc__panel-cards--verifying');

        const cfg  = window.niepodzielniBookero || {};
        const body = new FormData();
        body.append('action', 'bk_verify_hour');
        body.append('nonce',  cfg.nonce || '');
        body.append('typ',    this.typ);
        body.append('date',   snapshotDate);
        body.append('hour',   snapshotHour);
        bookeroIds.forEach(id => body.append('bookero_ids[]', id));

        fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method: 'POST', body, signal: this._verifyAbort.signal,
        })
            .then(r => r.json())
            .then(res => {
                // Porzuć jeśli użytkownik zmienił datę/godzinę podczas trwania requestu
                if (this.activeDate !== snapshotDate || this.activeHour !== snapshotHour) return;

                if (!res.success || !Array.isArray(res.data.removed)) return;
                if (!res.data.removed.length) return;

                // Ukryj karty z nieaktualnymi terminami (animacja fade-out)
                const cards = this.q('.bk-sc__panel-cards');
                res.data.removed.forEach(bid => {
                    // Utrwal wynik — usuń godzinę z _slotWorkers
                    const worker = this._slotWorkers.find(w => String(w.bookero_id) === String(bid));
                    if (worker && worker.hours) {
                        worker.hours = worker.hours.filter(h => h !== snapshotHour);
                    }

                    const card = cards.querySelector(`[data-bookero-id="${bid}"]`);
                    if (!card) return;
                    card.classList.add('bk-sc__slot-card--removed');
                    card.addEventListener('transitionend', () => card.remove(), { once: true });
                });

                // Jeśli wszystkie karty usunięte — pokaż komunikat
                setTimeout(() => {
                    // Sprawdź ponownie — użytkownik mógł zmienić godzinę w ciągu 400ms animacji
                    if (this.activeHour !== snapshotHour) return;

                    const remaining = cards.querySelectorAll(
                        '.bk-sc__slot-card:not(.bk-sc__slot-card--removed)'
                    );
                    if (!remaining.length) {
                        cards.innerHTML =
                            '<p class="bk-sc__info bk-sc__info--warn">Termin zajęty. Wybierz inną godzinę.</p>';
                    }

                    // Ukryj przyciski godzin, dla których nie ma już żadnego specjalisty
                    this.q('.bk-sc__hours-list').querySelectorAll('.bk-sc__hour-btn').forEach(btn => {
                        const h           = btn.textContent.trim();
                        const hasWorkers  = this._slotWorkers.some(w => (w.hours || []).includes(h));
                        if (!hasWorkers) {
                            btn.classList.add('bk-sc__hour-btn--gone');
                            btn.disabled = true;
                        }
                    });

                    // Jeśli aktywna godzina zniknęła — auto-przejdź na pierwszą dostępną
                    if (this.activeHour === snapshotHour) {
                        const activeBtn = this.q('.bk-sc__hours-list .bk-sc__hour-btn--active');
                        if (activeBtn && activeBtn.disabled) {
                            const next = this.q('.bk-sc__hours-list .bk-sc__hour-btn:not(:disabled)');
                            if (next) next.click();
                        }
                    }
                }, 400);
            })
            .catch(err => {
                if (err.name === 'AbortError') return; // Anulowany — OK
            })
            .finally(() => {
                this.q('.bk-sc__panel-cards').classList.remove('bk-sc__panel-cards--verifying');
            });
    }

    // ─── Prawa kolumna — 5 najbliższych (stan domyślny) ──────────────────────

    _renderNearestPanel() {
        const d      = this.data;
        const header = this.q('.bk-sc__panel-header');
        const cards  = this.q('.bk-sc__panel-cards');

        let slots = [];
        const allDates = Object.keys(d.dates).filter(dt => dt >= d.today).sort();
        for (const dt of allDates) {
            (d.dates[dt] || []).forEach(w => {
                (w.hours || []).forEach(h => slots.push({ dateStr: dt, hour: h, worker: w }));
            });
        }
        slots.sort((a, b) => {
            const cmp = a.dateStr.localeCompare(b.dateStr);
            return cmp !== 0 ? cmp : a.hour.localeCompare(b.hour);
        });
        slots = slots.slice(0, DEFAULT_PANEL_LIMIT);

        header.innerHTML = '<span>Najbliższe terminy</span>';
        cards.innerHTML  = '';

        if (!slots.length) {
            cards.innerHTML = '<p class="bk-sc__info">Brak dostępnych terminów w tym miesiącu.</p>';
            return;
        }

        slots.forEach(({ dateStr: dt, hour, worker }) =>
            cards.appendChild(this._buildSlotCard(dt, hour, worker))
        );
    }

    // ─── Krok 3: Karta specjalisty (po wyborze godziny) ──────────────────────

    _buildWorkerCard(dateStr, hour, worker) {
        const avatarHtml  = this._avatarHtml(worker);
        const rodzajBadge = this._rodzajBadge(worker.rodzaj || '');
        const priceHtml   = worker.price
            ? `<div class="bk-sc__booking-price">${this._esc(worker.price)} / 50 min</div>` : '';

        const card = document.createElement('button');
        card.className = 'bk-sc__slot-card';
        if (worker.bookero_id) card.dataset.bookeroId = worker.bookero_id;
        card.innerHTML = `
            ${avatarHtml}
            <div class="bk-sc__booking-info">
                <div class="bk-sc__booking-specialist">${this._esc(worker.name)}</div>
                ${rodzajBadge ? `<div class="bk-sc__visit-badges">${rodzajBadge}</div>` : ''}
                ${priceHtml}
            </div>
            <span class="bk-sc__slot-card-arrow">&#8250;</span>
        `;
        card.addEventListener('click', () => this._showBooking(this._formatDate(dateStr), hour, worker));
        return card;
    }

    // Karta "5 najbliższych" — pokazuje też datę i godzinę
    _buildSlotCard(dateStr, hour, worker) {
        const dateLabel   = this._formatDate(dateStr);
        const avatarHtml  = this._avatarHtml(worker);
        const rodzajBadge = this._rodzajBadge(worker.rodzaj || '');
        const priceHtml   = worker.price
            ? `<div class="bk-sc__booking-price">${this._esc(worker.price)} / 50 min</div>` : '';

        const card = document.createElement('button');
        card.className = 'bk-sc__slot-card';
        card.innerHTML = `
            ${avatarHtml}
            <div class="bk-sc__booking-info">
                <div class="bk-sc__booking-datetime">${dateLabel} · <strong>${hour}</strong></div>
                <div class="bk-sc__booking-specialist">${this._esc(worker.name)}</div>
                ${rodzajBadge ? `<div class="bk-sc__visit-badges">${rodzajBadge}</div>` : ''}
                ${priceHtml}
            </div>
            <span class="bk-sc__slot-card-arrow">&#8250;</span>
        `;
        card.addEventListener('click', () => {
            this.activeDate = dateStr;
            this._showBooking(dateLabel, hour, worker);
        });
        return card;
    }

    // ─── Powrót do kalendarza z formularza ────────────────────────────────────

    _showCalendar() {
        this.q('.bk-sc__panel-cards-wrap').hidden = false;
        this.q('.bk-sc__booking-inner').hidden    = true;

        if (this.activeHour && this._slotWorkers.length) {
            this._selectHour(this.activeHour, this._slotWorkers);
        } else if (this.activeDate) {
            this.q('.bk-sc__panel-header').innerHTML = '';
            this.q('.bk-sc__panel-cards').innerHTML  =
                '<p class="bk-sc__info bk-sc__info--prompt">Wybierz godzinę, aby zobaczyć specjalistów</p>';
        } else {
            this._renderNearestPanel();
        }
    }

    // ─── Formularz rezerwacji ─────────────────────────────────────────────────

    _showBooking(dateLabel, hour, worker) {
        if (!worker.service_id) {
            window.open(worker.profile_url + '#bookero_button_jumper_place', '_blank', 'noopener');
            return;
        }

        const inner       = this.q('.bk-sc__booking-inner');
        const avatarHtml  = this._avatarHtml(worker);
        const rodzajBadge = this._rodzajBadge(worker.rodzaj || '');
        const priceHtml   = worker.price
            ? `<div class="bk-sc__booking-price">${this._esc(worker.price)} / 50 min</div>` : '';

        inner.innerHTML = `
            <button class="bk-sc__booking-back">&#8249; Wróć</button>
            <div class="bk-sc__booking-card">
                ${avatarHtml}
                <div class="bk-sc__booking-info">
                    <div class="bk-sc__booking-datetime">
                        <strong>${dateLabel}</strong>, godz. <strong>${hour}</strong>
                    </div>
                    <div class="bk-sc__booking-specialist">${this._esc(worker.name)}</div>
                    ${rodzajBadge ? `<div class="bk-sc__visit-badges">${rodzajBadge}</div>` : ''}
                    ${priceHtml}
                </div>
            </div>
            <form class="bk-sc__booking-form" novalidate>
                <div class="bk-sc__form-row">
                    <label class="bk-sc__form-label" for="bk-name-${this.typ}">Imię i nazwisko *</label>
                    <input class="bk-sc__form-input" id="bk-name-${this.typ}" name="name" type="text" required autocomplete="name" placeholder="Jan Kowalski">
                </div>
                <div class="bk-sc__form-row">
                    <label class="bk-sc__form-label" for="bk-email-${this.typ}">Adres e-mail *</label>
                    <input class="bk-sc__form-input" id="bk-email-${this.typ}" name="email" type="email" required autocomplete="email" placeholder="jan@example.com">
                </div>
                <div class="bk-sc__form-row">
                    <label class="bk-sc__form-label" for="bk-phone-${this.typ}">Numer telefonu *</label>
                    <input class="bk-sc__form-input" id="bk-phone-${this.typ}" name="phone" type="tel" required autocomplete="tel" placeholder="+48 500 000 000">
                </div>
                <div class="bk-sc__form-row-2col">
                    <div class="bk-sc__form-row">
                        <label class="bk-sc__form-label" for="bk-ulica-${this.typ}">Ulica *</label>
                        <input class="bk-sc__form-input" id="bk-ulica-${this.typ}" name="ulica" type="text" required autocomplete="street-address" placeholder="ul. Kwiatowa">
                    </div>
                    <div class="bk-sc__form-row">
                        <label class="bk-sc__form-label" for="bk-nrdomu-${this.typ}">Nr domu/lok. *</label>
                        <input class="bk-sc__form-input" id="bk-nrdomu-${this.typ}" name="nr_domu" type="text" required placeholder="12A/3">
                    </div>
                </div>
                <div class="bk-sc__form-row-2col">
                    <div class="bk-sc__form-row">
                        <label class="bk-sc__form-label" for="bk-kod-${this.typ}">Kod pocztowy *</label>
                        <input class="bk-sc__form-input" id="bk-kod-${this.typ}" name="kod_poczt" type="text" required autocomplete="postal-code" placeholder="00-000">
                    </div>
                    <div class="bk-sc__form-row">
                        <label class="bk-sc__form-label" for="bk-miasto-${this.typ}">Miejscowość *</label>
                        <input class="bk-sc__form-input" id="bk-miasto-${this.typ}" name="miasto" type="text" required autocomplete="address-level2" placeholder="Warszawa">
                    </div>
                </div>
                <div class="bk-sc__form-row">
                    <label class="bk-sc__form-label" for="bk-powod-${this.typ}">Powód konsultacji</label>
                    <textarea class="bk-sc__form-input bk-sc__form-textarea" id="bk-powod-${this.typ}" name="powod" rows="2" placeholder="Opcjonalnie — opisz krótko temat konsultacji"></textarea>
                </div>
                <div class="bk-sc__form-row">
                    <label class="bk-sc__form-label" for="bk-zaimki-${this.typ}">Zaimki</label>
                    <input class="bk-sc__form-input" id="bk-zaimki-${this.typ}" name="zaimki" type="text" placeholder="np. ona/jej, on/jego (opcjonalnie)">
                </div>
                <div class="bk-sc__form-check">
                    <input type="checkbox" id="bk-age-${this.typ}" name="agree_18" required>
                    <label for="bk-age-${this.typ}">Oświadczam, że mam ukończone 18 lat *</label>
                </div>
                <p class="bk-sc__form-privacy">Dbamy o Twoją prywatność. Administratorem danych osobowych jest Fundacja Niepodzielni, ul. Szamarzewskiego 13/15 lok. 8, 60-514 Poznań, KRS 0000973514. Dane będą przetwarzane w celu realizacji rezerwowanej usługi. Szczegóły w <a href="/regulamin-konsultacji/" target="_blank" rel="noopener">regulaminie konsultacji</a>.</p>
                <div class="bk-sc__form-check">
                    <input type="checkbox" id="bk-tp-${this.typ}" name="agree_tp" required>
                    <label for="bk-tp-${this.typ}">Zapoznałam/em się i akceptuję <a href="/regulamin-konsultacji/" target="_blank" rel="noopener">regulamin rezerwacji i politykę prywatności</a> *</label>
                </div>
                <div class="bk-sc__form-check">
                    <input type="checkbox" id="bk-tel-${this.typ}" name="agree_tel">
                    <label for="bk-tel-${this.typ}">Wyrażam zgodę na kontakt telefoniczny oraz SMS w celu przedstawienia informacji na temat promocji, nowości i usług Fundacji Niepodzielni. Wiem, że w każdej chwili mogę wycofać zgodę.</label>
                </div>
                <p class="bk-sc__form-error" hidden></p>
                <button type="submit" class="bk-sc__booking-cta">Zarezerwuj wizytę</button>
            </form>
            <p class="bk-sc__booking-note">Zostaniesz przekierowany do strony płatności Bookero.</p>
        `;

        inner.querySelector('.bk-sc__booking-back')
            .addEventListener('click', () => this._showCalendar());
        inner.querySelector('.bk-sc__booking-form')
            .addEventListener('submit', e => { e.preventDefault(); this._submitBooking(e.target, worker, hour); });

        this.q('.bk-sc__panel-cards-wrap').hidden = true;
        this.q('.bk-sc__booking-inner').hidden    = false;
    }

    _submitBooking(form, worker, hour) {
        if (this._bookingInFlight) return;
        const btn      = form.querySelector('[type=submit]');
        const errEl    = form.querySelector('.bk-sc__form-error');
        const name     = form.querySelector('[name=name]').value.trim();
        const email    = form.querySelector('[name=email]').value.trim();
        const phone    = form.querySelector('[name=phone]').value.trim();
        const ulica    = form.querySelector('[name=ulica]').value.trim();
        const nrDomu   = form.querySelector('[name=nr_domu]').value.trim();
        const kodPoczt = form.querySelector('[name=kod_poczt]').value.trim();
        const miasto   = form.querySelector('[name=miasto]').value.trim();
        const powod    = form.querySelector('[name=powod]').value.trim();
        const zaimki   = form.querySelector('[name=zaimki]').value.trim();
        const age      = form.querySelector('[name=agree_18]').checked;
        const tp       = form.querySelector('[name=agree_tp]').checked;
        const tel      = form.querySelector('[name=agree_tel]').checked;

        errEl.hidden = true;

        if (!name)                           return this._formErr(errEl, 'Podaj imię i nazwisko.');
        if (!email || !email.includes('@'))  return this._formErr(errEl, 'Podaj prawidłowy adres e-mail.');
        if (!phone)                          return this._formErr(errEl, 'Podaj numer telefonu.');
        if (!ulica)                          return this._formErr(errEl, 'Podaj ulicę.');
        if (!nrDomu)                         return this._formErr(errEl, 'Podaj numer domu/mieszkania.');
        if (!kodPoczt)                       return this._formErr(errEl, 'Podaj kod pocztowy.');
        if (!miasto)                         return this._formErr(errEl, 'Podaj miejscowość.');
        if (!age)                            return this._formErr(errEl, 'Wymagane oświadczenie o wieku 18+.');
        if (!tp)                             return this._formErr(errEl, 'Wymagana akceptacja regulaminu.');

        this._bookingInFlight = true;
        btn.disabled    = true;
        btn.textContent = 'Rezerwowanie…';

        const cfg  = window.niepodzielniBookero || {};
        const body = new FormData();
        body.append('action',    'bk_create_booking');
        body.append('nonce',     cfg.nonce || '');
        body.append('cal_hash',  worker.cal_hash);
        body.append('service',   worker.service_id);
        body.append('worker',    worker.bookero_id);
        body.append('date',      this.activeDate);
        body.append('hour',      hour);
        body.append('name',      name);
        body.append('email',     email);
        body.append('phone',     phone);
        body.append('ulica',     ulica);
        body.append('nr_domu',   nrDomu);
        body.append('kod_poczt', kodPoczt);
        body.append('miasto',    miasto);
        body.append('powod',     powod);
        body.append('zaimki',    zaimki);
        body.append('agree_18',  age ? '1' : '');
        body.append('agree_tp',  tp  ? '1' : '');
        body.append('agree_tel', tel ? '1' : '');

        fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body })
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error(res.data || 'Błąd rezerwacji');
                window.location.href = res.data.payment_url;
            })
            .catch(err => {
                btn.disabled    = false;
                btn.textContent = 'Zarezerwuj wizytę';
                this._formErr(errEl, err.message || 'Wystąpił błąd. Spróbuj ponownie.');
            })
            .finally(() => { this._bookingInFlight = false; });
    }

    _formErr(el, msg) { el.textContent = msg; el.hidden = false; }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    _avatarHtml(worker) {
        return worker.avatar
            ? `<img class="bk-sc__booking-avatar" src="${this._esc(worker.avatar)}" alt="${this._esc(worker.name)}" loading="lazy">`
            : `<div class="bk-sc__booking-avatar bk-sc__booking-avatar--placeholder">${this._initials(worker.name)}</div>`;
    }

    _formatDate(dateStr) {
        const parts     = dateStr.split('-');
        const ts        = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        const dowName   = DAY_NAMES[ts.getDay() === 0 ? 6 : ts.getDay() - 1];
        const monthName = MONTHS_PL[parseInt(parts[1], 10)];
        return `${dowName}, ${parseInt(parts[2], 10)} ${monthName}`;
    }

    _rodzajBadge(rodzaj) {
        const badges = [];
        if (rodzaj.includes('Online'))       badges.push('<span class="bk-sc__visit-badge bk-sc__visit-badge--online">Online</span>');
        if (rodzaj.includes('Stacjonarnie')) badges.push('<span class="bk-sc__visit-badge bk-sc__visit-badge--stationary">Stacjonarnie · Poznań</span>');
        return badges.join('');
    }

    q(sel)          { return this.el.querySelector(sel); }
    _esc(str)       { return (str || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    _initials(name) { return (name || '?').split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase(); }
    _setLoading(on) { this.el.classList.toggle('bk-sc--loading', on); }
}

// ─── Boot ─────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.bk-shared-cal').forEach(el => new BkSharedCalendar(el));
});
