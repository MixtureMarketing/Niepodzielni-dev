/**
 * Psychomapa — interaktywna mapa ośrodków pomocy
 * Leaflet.js + Leaflet.markercluster (CDN) + REST /wp-json/niepodzielni/v1/psychomapa
 */

// ── Custom Dropdown ───────────────────────────────────────────────────────────

class PmDropdown {
    #el; #mode; #trigger; #label; #panel; #searchInput; #items;
    #selected = new Set();
    #defaultLabel = '';
    #onChange;

    constructor(el, onChange) {
        this.#el           = el;
        this.#mode         = el.dataset.mode;     // 'multi' | 'single'
        this.#trigger      = el.querySelector('.pm-dropdown__trigger');
        this.#label        = el.querySelector('.pm-dropdown__label');
        this.#panel        = el.querySelector('.pm-dropdown__panel');
        this.#searchInput  = el.querySelector('.pm-dropdown__search');
        this.#items        = [...el.querySelectorAll('.pm-dropdown__item')];
        this.#onChange     = onChange;
        this.#defaultLabel = this.#label.textContent.trim();

        // Pre-select items already marked is-selected (single "Wszyscy")
        this.#items.forEach(item => {
            if (item.classList.contains('is-selected') && item.dataset.value) {
                this.#selected.add(item.dataset.value);
            }
        });

        this.#bind();
    }

    #bind() {
        this.#trigger.addEventListener('click', () => this.#toggle());

        document.addEventListener('click', e => {
            if (!this.#el.contains(e.target)) this.#close();
        });

        this.#el.addEventListener('keydown', e => {
            if (e.key === 'Escape') this.#close();
        });

        this.#searchInput?.addEventListener('input', () => this.#filterItems());

        this.#items.forEach(item => {
            item.addEventListener('click', () => this.#pick(item));
            item.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.#pick(item); }
            });
        });
    }

    #toggle() { this.#panel.hidden ? this.#open() : this.#close(); }

    #open() {
        this.#panel.hidden = false;
        this.#trigger.setAttribute('aria-expanded', 'true');
        this.#el.classList.add('is-open');
        this.#searchInput?.focus();
    }

    #close() {
        this.#panel.hidden = true;
        this.#trigger.setAttribute('aria-expanded', 'false');
        this.#el.classList.remove('is-open');
        if (this.#searchInput) {
            this.#searchInput.value = '';
            this.#filterItems();
        }
    }

    #filterItems() {
        const q = (this.#searchInput?.value ?? '').toLowerCase();
        this.#items.forEach(item => {
            const text = item.querySelector('.pm-dropdown__item-text')?.textContent.toLowerCase() ?? '';
            item.hidden = q !== '' && !text.includes(q);
        });
    }

    #pick(item) {
        const val = item.dataset.value;

        if (this.#mode === 'single') {
            this.#items.forEach(i => {
                i.classList.remove('is-selected');
                i.setAttribute('aria-selected', 'false');
            });
            item.classList.add('is-selected');
            item.setAttribute('aria-selected', 'true');
            this.#selected = new Set(val ? [val] : []);
            this.#close();
        } else {
            if (this.#selected.has(val)) {
                this.#selected.delete(val);
                item.classList.remove('is-selected');
                item.setAttribute('aria-selected', 'false');
            } else {
                this.#selected.add(val);
                item.classList.add('is-selected');
                item.setAttribute('aria-selected', 'true');
            }
        }

        this.#updateLabel();
        this.#onChange(this.getValues());
    }

    #updateLabel() {
        const count = this.#selected.size;
        if (count === 0) {
            this.#label.textContent = this.#defaultLabel;
            this.#trigger.classList.remove('has-value');
        } else if (this.#mode === 'single') {
            const active = this.#el.querySelector('.pm-dropdown__item.is-selected .pm-dropdown__item-text');
            this.#label.textContent = active ? active.textContent : this.#defaultLabel;
            this.#trigger.classList.add('has-value');
        } else {
            this.#label.textContent = count === 1
                ? this.#el.querySelector('.pm-dropdown__item.is-selected .pm-dropdown__item-text')?.textContent
                : `${this.#defaultLabel} (${count})`;
            this.#trigger.classList.add('has-value');
        }
    }

    getValues() {
        return [...this.#selected].map(v => parseInt(v, 10)).filter(n => !isNaN(n) && n > 0);
    }

    hasValue() { return this.#selected.size > 0; }

    reset() {
        this.#selected.clear();
        this.#items.forEach(i => {
            i.classList.remove('is-selected');
            i.setAttribute('aria-selected', 'false');
        });
        if (this.#mode === 'single') {
            const all = this.#el.querySelector('[data-value=""]');
            if (all) { all.classList.add('is-selected'); all.setAttribute('aria-selected', 'true'); }
        }
        this.#updateLabel();
    }
}

// ── Init ──────────────────────────────────────────────────────────────────────

function initPsychomapa() {
    // Single ośrodek: mini-mapa
    const singleMapEl = document.getElementById('osrodek-map');
    if (singleMapEl && window.L) {
        const lat   = parseFloat(singleMapEl.dataset.lat);
        const lng   = parseFloat(singleMapEl.dataset.lng);
        const title = singleMapEl.dataset.title ?? '';
        if (lat && lng) {
            const sMap = L.map(singleMapEl, { scrollWheelZoom: false }).setView([lat, lng], 15);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/">CARTO</a>',
                subdomains: 'abcd', maxZoom: 19,
            }).addTo(sMap);
            L.marker([lat, lng]).addTo(sMap).bindPopup(`<strong>${title}</strong>`).openPopup();
        }
        return;
    }

    // Template Psychomapa
    const mapEl     = document.getElementById('psychomapa-map');
    const gridEl    = document.getElementById('psychomapa-list');
    const countEl   = document.getElementById('psychomapa-count');
    const loadingEl = document.getElementById('pm-map-loading');

    if (!mapEl || !gridEl || !window.L || !window.npPsychomapa) return;

    const cfg = window.npPsychomapa;

    const rodzajeArr = Array.isArray(cfg.rodzajeTerms) ? cfg.rodzajeTerms : [];
    const grupyArr   = Array.isArray(cfg.grupyTerms)   ? cfg.grupyTerms   : [];
    const rodzajeMap = Object.fromEntries(rodzajeArr.map(t => [t.id, t.name]));
    const grupyMap   = Object.fromEntries(grupyArr.map(t => [t.id, t.name]));

    // ── Leaflet map ───────────────────────────────────────────────────────────
    const map = L.map(mapEl, { zoomControl: false }).setView([52.0, 19.5], 6);

    // CARTO tiles — Cloudflare-friendly, nie wymagają osobnego connect-src
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/">CARTO</a>',
        subdomains: 'abcd',
        maxZoom: 19,
    }).addTo(map);

    L.control.zoom({ position: 'topright' }).addTo(map);

    // Upewnij się że mapa zna swój rozmiar po renderze layoutu
    setTimeout(() => map.invalidateSize(), 300);

    // ── Marker cluster ────────────────────────────────────────────────────────
    let clusterGroup;
    try {
        clusterGroup = L.markerClusterGroup({
            maxClusterRadius: 60,
            spiderfyOnMaxZoom: true,
            showCoverageOnHover: false,
            iconCreateFunction(cluster) {
                const count = cluster.getChildCount();
                const size  = count > 99 ? 'lg' : count > 9 ? 'md' : 'sm';
                return L.divIcon({
                    html: `<div class="pm-cluster pm-cluster--${size}"><span>${count}</span></div>`,
                    className: '',
                    iconSize: L.point(40, 40),
                });
            },
        });
    } catch {
        clusterGroup = L.layerGroup();
    }
    map.addLayer(clusterGroup);

    const markerIcon = L.divIcon({
        className: '', html: '<div class="pm-marker"></div>',
        iconSize: [14, 14], iconAnchor: [7, 7], popupAnchor: [0, -10],
    });
    const markerIconActive = L.divIcon({
        className: '', html: '<div class="pm-marker pm-marker--active"></div>',
        iconSize: [20, 20], iconAnchor: [10, 10], popupAnchor: [0, -12],
    });

    // ── State ─────────────────────────────────────────────────────────────────
    let allItems  = [];
    let markerMap = {};

    // ── Filtry — custom dropdowns ─────────────────────────────────────────────
    const searchInput = document.getElementById('psychomapa-search');
    const resetBtn    = document.getElementById('pm-reset');

    let rodzajDropdown = null;
    let grupaDropdown  = null;

    const rodzajEl = document.getElementById('pm-drop-rodzaj');
    const grupaEl  = document.getElementById('pm-drop-grupa');

    if (rodzajEl) rodzajDropdown = new PmDropdown(rodzajEl, () => render());
    if (grupaEl)  grupaDropdown  = new PmDropdown(grupaEl,  () => render());

    function getFilters() {
        return {
            q:      (searchInput?.value ?? '').toLowerCase().trim(),
            rodzaje: rodzajDropdown ? rodzajDropdown.getValues() : [],
            grupa:  grupaDropdown  ? (grupaDropdown.getValues()[0] ?? 0) : 0,
        };
    }

    function hasActiveFilter(f) {
        return f.q !== '' || f.rodzaje.length > 0 || f.grupa !== 0;
    }

    function matchesFilters(item, f) {
        if (f.q) {
            const hay = `${item.title} ${item.city}`.toLowerCase();
            if (!hay.includes(f.q)) return false;
        }
        if (f.rodzaje.length > 0) {
            const hasAny = f.rodzaje.some(id => item.terms.rodzaj_pomocy?.includes(id));
            if (!hasAny) return false;
        }
        if (f.grupa && !item.terms.grupa_docelowa?.includes(f.grupa)) return false;
        return true;
    }

    // ── Render ────────────────────────────────────────────────────────────────
    function render() {
        const f       = getFilters();
        const visible = allItems.filter(item => matchesFilters(item, f));

        if (resetBtn) resetBtn.hidden = !hasActiveFilter(f);

        clusterGroup.clearLayers();
        visible.forEach(item => {
            const entry = markerMap[item.id];
            if (entry) clusterGroup.addLayer(entry.marker);
        });

        const n = visible.length;
        if (countEl) {
            countEl.textContent = n === 0
                ? 'Brak wyników'
                : `${n} ${n === 1 ? 'ośrodek' : n < 5 ? 'ośrodki' : 'ośrodków'}`;
        }

        renderCards(visible);
    }

    function renderCards(items) {
        if (items.length === 0) {
            gridEl.innerHTML = `
                <div class="pm-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" stroke="#ccc" stroke-width="1.5"/>
                        <path d="M21 21l-3.5-3.5" stroke="#ccc" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <p>Brak ośrodków spełniających kryteria.</p>
                    <button class="pm-btn pm-btn--outline" id="pm-empty-reset">Wyczyść filtry</button>
                </div>`;
            document.getElementById('pm-empty-reset')?.addEventListener('click', resetFilters);
            return;
        }
        gridEl.innerHTML = '';
        const frag = document.createDocumentFragment();
        items.forEach(item => frag.appendChild(buildCard(item)));
        gridEl.appendChild(frag);
    }

    function buildCard(item) {
        const rodzajNames = (item.terms.rodzaj_pomocy || []).map(id => rodzajeMap[id]).filter(Boolean);
        const grupaNazwy  = (item.terms.grupa_docelowa || []).map(id => grupyMap[id]).filter(Boolean);

        const div = document.createElement('div');
        div.className = 'pm-card';
        div.dataset.id = item.id;
        div.setAttribute('role', 'listitem');

        const logoHtml = item.logo_url
            ? `<img class="pm-card__logo" src="${esc(item.logo_url)}" alt="" loading="lazy">`
            : `<div class="pm-card__logo-placeholder" aria-hidden="true">
                   <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" fill="#d0d0d0"/></svg>
               </div>`;

        const tagsHtml = [
            ...rodzajNames.map(n => `<span class="pm-tag pm-tag--rodzaj">${esc(n)}</span>`),
            ...grupaNazwy.map(n  => `<span class="pm-tag pm-tag--grupa">${esc(n)}</span>`),
        ].join('');

        div.innerHTML = `
            <div class="pm-card__head">
                ${logoHtml}
                <div class="pm-card__info">
                    <h3 class="pm-card__name">${esc(item.title)}</h3>
                    ${item.city ? `<p class="pm-card__city">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" fill="currentColor"/></svg>
                        ${esc(item.city)}</p>` : ''}
                    ${item.phone ? `<p class="pm-card__phone">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6.6 10.8a15.06 15.06 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1L6.6 10.8z" fill="currentColor"/></svg>
                        <a href="tel:${esc(item.phone.replace(/\s/g,''))}">${esc(item.phone)}</a></p>` : ''}
                </div>
            </div>
            ${tagsHtml ? `<div class="pm-card__tags">${tagsHtml}</div>` : ''}
            <div class="pm-card__foot">
                <a class="pm-btn pm-btn--primary" href="${esc(item.url)}">
                    Zobacz szczegóły
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <button class="pm-btn pm-btn--ghost pm-card__locate" type="button" aria-label="Pokaż na mapie">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" fill="currentColor"/></svg>
                    Mapa
                </button>
            </div>`;

        div.querySelector('.pm-card__locate').addEventListener('click', e => {
            e.preventDefault();
            focusItem(item, div);
        });

        div.addEventListener('click', e => {
            if (e.target.closest('a, button')) return;
            focusItem(item, div);
        });

        return div;
    }

    function focusItem(item, cardEl) {
        document.querySelectorAll('.pm-card.is-active').forEach(el => {
            el.classList.remove('is-active');
            const e = markerMap[Number(el.dataset.id)];
            if (e) e.marker.setIcon(markerIcon);
        });
        cardEl.classList.add('is-active');
        cardEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        const entry = markerMap[item.id];
        if (entry) {
            entry.marker.setIcon(markerIconActive);
            const latlng = entry.marker.getLatLng();
            map.flyTo(latlng, Math.max(map.getZoom(), 13), { animate: true, duration: 0.8 });
            setTimeout(() => entry.marker.openPopup(), 700);
            if (window.innerWidth < 768) mapEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function resetFilters() {
        if (searchInput) searchInput.value = '';
        rodzajDropdown?.reset();
        grupaDropdown?.reset();
        render();
    }

    resetBtn?.addEventListener('click', resetFilters);

    // ── Fetch ─────────────────────────────────────────────────────────────────
    const fetchCtrl = new AbortController();
    const fetchTimeout = setTimeout(() => fetchCtrl.abort(), 15000);

    fetch(cfg.apiUrl, { signal: fetchCtrl.signal })
        .then(r => { clearTimeout(fetchTimeout); return r.json(); })
        .then(data => {
            allItems = data;

            allItems.forEach(item => {
                const marker = L.marker([item.lat, item.lng], { icon: markerIcon });

                marker.bindPopup(() => {
                    const rodzajNames = (item.terms.rodzaj_pomocy || []).slice(0, 2)
                        .map(id => rodzajeMap[id]).filter(Boolean);
                    const el = document.createElement('div');
                    el.className = 'pm-popup';
                    el.innerHTML = `
                        <p class="pm-popup__name">${esc(item.title)}</p>
                        ${item.city  ? `<p class="pm-popup__city">${esc(item.city)}</p>` : ''}
                        ${item.phone ? `<p class="pm-popup__phone"><a href="tel:${esc(item.phone.replace(/\s/g,''))}">${esc(item.phone)}</a></p>` : ''}
                        ${rodzajNames.length ? `<div class="pm-popup__tags">${rodzajNames.map(n=>`<span class="pm-tag pm-tag--sm pm-tag--rodzaj">${esc(n)}</span>`).join('')}</div>` : ''}
                        <a class="pm-popup__btn" href="${esc(item.url)}">Zobacz szczegóły →</a>`;
                    return el;
                }, { maxWidth: 240, className: 'pm-popup-wrap' });

                marker.on('click', () => {
                    const card = gridEl.querySelector(`[data-id="${item.id}"]`);
                    if (card) {
                        document.querySelectorAll('.pm-card.is-active').forEach(el => {
                            el.classList.remove('is-active');
                            const e = markerMap[Number(el.dataset.id)];
                            if (e) e.marker.setIcon(markerIcon);
                        });
                        card.classList.add('is-active');
                        marker.setIcon(markerIconActive);
                        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                });

                markerMap[item.id] = { marker, item };
            });

            if (loadingEl) loadingEl.hidden = true;

            render();

            if (allItems.length > 0) {
                try {
                    map.fitBounds(clusterGroup.getBounds(), { padding: [40, 40], maxZoom: 12 });
                } catch { /* brak markerów */ }
            }
        })
        .catch(err => {
            clearTimeout(fetchTimeout);
            if (err.name === 'AbortError') {
                console.warn('[Psychomapa] Fetch przekroczył limit czasu (15s).');
            } else {
                console.error('[Psychomapa] Błąd ładowania:', err);
            }
            if (loadingEl) loadingEl.hidden = true;
            gridEl.innerHTML = '<div class="pm-empty"><p>Błąd ładowania danych. Odśwież stronę.</p></div>';
            if (countEl) countEl.textContent = '';
        });

    // ── Eventy filtrów ────────────────────────────────────────────────────────
    let debTimer;
    searchInput?.addEventListener('input', () => {
        clearTimeout(debTimer);
        debTimer = setTimeout(render, 220);
    });
}

function esc(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPsychomapa);
} else {
    initPsychomapa();
}
