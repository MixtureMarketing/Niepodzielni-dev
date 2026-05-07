/**
 * Crisis Help Hub — mini-mapa ośrodków interwencji kryzysowej.
 *
 * Filtruje psychomapę po stronie klienta po termie `interwencja-kryzysowa`
 * (term_id w data-term-id). Bez clusteringu, bez UI filtrów —
 * w sytuacji kryzysu UX musi być jak najprostszy.
 *
 * Wymaga: Leaflet (CDN, enqueue w setup.php).
 */

function escHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function init() {
    const mapEl = document.querySelector('[data-np-crisis-map]');
    if (!mapEl || !window.L) return;

    const termId = parseInt(mapEl.dataset.termId, 10);
    const apiUrl = mapEl.dataset.apiUrl;
    const loadingEl = document.getElementById('np-crisis-map-loading');
    const listEl = document.getElementById('np-crisis-map-list');

    if (!termId || !apiUrl) return;

    const map = L.map(mapEl, { scrollWheelZoom: false, zoomControl: true }).setView([52.0, 19.5], 6);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/">CARTO</a>',
        subdomains: 'abcd',
        maxZoom: 19,
    }).addTo(map);

    const markerIcon = L.divIcon({
        className: '',
        html: '<div class="np-crisis__marker"></div>',
        iconSize: [16, 16],
        iconAnchor: [8, 8],
        popupAnchor: [0, -10],
    });

    const ctrl = new AbortController();
    const timeout = setTimeout(() => ctrl.abort(), 15000);

    fetch(apiUrl, { signal: ctrl.signal })
        .then((r) => {
            clearTimeout(timeout);
            return r.json();
        })
        .then((data) => {
            if (loadingEl) loadingEl.hidden = true;

            const items = (Array.isArray(data) ? data : []).filter((item) =>
                Array.isArray(item?.terms?.rodzaj_pomocy) && item.terms.rodzaj_pomocy.includes(termId),
            );

            if (items.length === 0) {
                renderEmpty(listEl);
                return;
            }

            const bounds = [];
            const frag = document.createDocumentFragment();

            items.forEach((item) => {
                const lat = parseFloat(item.lat);
                const lng = parseFloat(item.lng);
                if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

                const marker = L.marker([lat, lng], { icon: markerIcon }).addTo(map);
                marker.bindPopup(buildPopup(item), { maxWidth: 260, className: 'np-crisis__popup' });
                bounds.push([lat, lng]);

                if (listEl) frag.appendChild(buildListItem(item));
            });

            if (listEl) listEl.appendChild(frag);

            if (bounds.length > 0) {
                try {
                    map.fitBounds(bounds, { padding: [40, 40], maxZoom: 11 });
                } catch {
                    /* ignore */
                }
            }

            setTimeout(() => map.invalidateSize(), 200);
        })
        .catch((err) => {
            clearTimeout(timeout);
            if (loadingEl) loadingEl.hidden = true;
            renderError(listEl, err);
        });
}

function buildPopup(item) {
    const phone = item.phone ? `<p class="np-crisis__popup-phone"><a href="tel:${escHtml(String(item.phone).replace(/\s/g, ''))}">${escHtml(item.phone)}</a></p>` : '';
    const city = item.city ? `<p class="np-crisis__popup-city">${escHtml(item.city)}</p>` : '';
    const link = item.url ? `<a class="np-crisis__popup-link" href="${escHtml(item.url)}">Zobacz szczegóły →</a>` : '';
    return `
        <strong>${escHtml(item.title)}</strong>
        ${city}
        ${phone}
        ${link}
    `;
}

function buildListItem(item) {
    const li = document.createElement('li');
    li.className = 'np-crisis__map-list-item';
    const phoneClean = String(item.phone ?? '').replace(/\s/g, '');
    li.innerHTML = `
        <h3 class="np-crisis__map-list-title">${escHtml(item.title)}</h3>
        ${item.city ? `<p class="np-crisis__map-list-city">${escHtml(item.city)}</p>` : ''}
        ${item.phone ? `<p class="np-crisis__map-list-phone"><a href="tel:${escHtml(phoneClean)}">${escHtml(item.phone)}</a></p>` : ''}
        ${item.url ? `<a class="np-crisis__map-list-link" href="${escHtml(item.url)}">Zobacz szczegóły</a>` : ''}
    `;
    return li;
}

function renderEmpty(listEl) {
    if (!listEl) return;
    listEl.innerHTML = `
        <li class="np-crisis__map-list-item np-crisis__map-list-item--empty">
            <p>Lista ośrodków interwencji kryzysowej będzie dostępna wkrótce. Zadzwoń pod 112 lub 116 123.</p>
        </li>
    `;
}

function renderError(listEl, err) {
    // eslint-disable-next-line no-console
    console.error('[Crisis] Błąd ładowania mapy:', err);
    if (!listEl) return;
    listEl.innerHTML = `
        <li class="np-crisis__map-list-item np-crisis__map-list-item--error">
            <p>Nie udało się załadować mapy ośrodków. Skorzystaj z numerów alarmowych powyżej.</p>
        </li>
    `;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
