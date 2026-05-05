/**
 * Psychomapa — interaktywna mapa ośrodków pomocy
 * Używa Leaflet.js (CDN) + REST endpoint /wp-json/niepodzielni/v1/psychomapa
 */

document.addEventListener('DOMContentLoaded', () => {
    // ── Single ośrodek: mini-mapa ─────────────────────────────────────────────
    const singleMapEl = document.getElementById('osrodek-map');
    if (singleMapEl && window.L) {
        const lat   = parseFloat(singleMapEl.dataset.lat);
        const lng   = parseFloat(singleMapEl.dataset.lng);
        const title = singleMapEl.dataset.title ?? '';
        if (lat && lng) {
            const map = L.map(singleMapEl, { scrollWheelZoom: false }).setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
            }).addTo(map);
            L.marker([lat, lng]).addTo(map).bindPopup(`<strong>${title}</strong>`).openPopup();
        }
    }

    // ── Template Psychomapa ───────────────────────────────────────────────────
    const mapEl   = document.getElementById('psychomapa-map');
    const listEl  = document.getElementById('psychomapa-list');
    const countEl = document.getElementById('psychomapa-count');
    if (!mapEl || !listEl || !window.L || !window.npPsychomapa) return;

    const cfg = window.npPsychomapa;

    // Leaflet map init (centrowane na Polskę)
    const map = L.map(mapEl).setView([52.0, 19.5], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(map);

    // Custom ikona markera
    const markerIcon = L.divIcon({
        className: '',
        html: '<div style="width:12px;height:12px;border-radius:50%;background:#01BE4A;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>',
        iconSize: [12, 12],
        iconAnchor: [6, 6],
    });

    let allItems = [];
    let markers  = [];
    let activeItem = null;

    // ── Filtry ────────────────────────────────────────────────────────────────
    const searchInput    = document.getElementById('psychomapa-search');
    const rodzajSelect   = document.getElementById('psychomapa-filter-rodzaj');
    const grupaSelect    = document.getElementById('psychomapa-filter-grupa');

    function getFilters() {
        return {
            q:      (searchInput?.value ?? '').toLowerCase().trim(),
            rodzaj: rodzajSelect ? parseInt(rodzajSelect.value, 10) || 0 : 0,
            grupa:  grupaSelect  ? parseInt(grupaSelect.value,  10) || 0 : 0,
        };
    }

    function matchesFilters(item, filters) {
        if (filters.q) {
            const haystack = `${item.title} ${item.city}`.toLowerCase();
            if (!haystack.includes(filters.q)) return false;
        }
        if (filters.rodzaj && !item.terms.rodzaj_pomocy?.includes(filters.rodzaj)) return false;
        if (filters.grupa  && !item.terms.grupa_docelowa?.includes(filters.grupa))  return false;
        return true;
    }

    // ── Render ────────────────────────────────────────────────────────────────
    function render() {
        const filters = getFilters();
        const visible = allItems.filter(item => matchesFilters(item, filters));

        // Aktualizacja markera
        markers.forEach(({ marker, item }) => {
            const show = matchesFilters(item, filters);
            if (show && !map.hasLayer(marker)) marker.addTo(map);
            if (!show && map.hasLayer(marker))  map.removeLayer(marker);
        });

        // Count
        if (countEl) {
            countEl.textContent = `${visible.length} ${visible.length === 1 ? 'ośrodek' : visible.length < 5 ? 'ośrodki' : 'ośrodków'}`;
        }

        // Lista
        listEl.innerHTML = '';
        if (visible.length === 0) {
            listEl.innerHTML = '<li class="psychomapa-list__empty">Brak wyników. Zmień kryteria wyszukiwania.</li>';
            return;
        }

        visible.forEach(item => {
            const li = document.createElement('li');
            li.className = 'psychomapa-item';
            li.dataset.id = item.id;
            li.innerHTML = `
                ${item.logo_url ? `<img class="psychomapa-item__logo" src="${item.logo_url}" alt="" loading="lazy">` : ''}
                <div class="psychomapa-item__body">
                    <p class="psychomapa-item__name">${item.title}</p>
                    ${item.city ? `<p class="psychomapa-item__city">${item.city}</p>` : ''}
                </div>
            `;
            li.addEventListener('click', () => focusItem(item, li));
            listEl.appendChild(li);
        });
    }

    function focusItem(item, liEl) {
        // Deaktywuj poprzedni
        document.querySelectorAll('.psychomapa-item.is-active').forEach(el => el.classList.remove('is-active'));
        liEl.classList.add('is-active');

        const markerEntry = markers.find(m => m.item.id === item.id);
        if (markerEntry) {
            map.setView(markerEntry.marker.getLatLng(), 14, { animate: true });
            markerEntry.marker.openPopup();
        }
    }

    // ── Fetch danych ──────────────────────────────────────────────────────────
    fetch(cfg.apiUrl)
        .then(r => r.json())
        .then(data => {
            allItems = data;

            // Tworzenie markerów
            allItems.forEach(item => {
                const marker = L.marker([item.lat, item.lng], { icon: markerIcon });
                marker.bindPopup(`
                    <div class="np-popup">
                        <p class="np-popup__name">${item.title}</p>
                        ${item.city ? `<p class="np-popup__city">${item.city}</p>` : ''}
                        <a class="np-popup__link" href="${item.url}">Zobacz szczegóły</a>
                    </div>
                `, { maxWidth: 220 });

                marker.on('click', () => {
                    const liEl = listEl.querySelector(`[data-id="${item.id}"]`);
                    if (liEl) {
                        document.querySelectorAll('.psychomapa-item.is-active').forEach(el => el.classList.remove('is-active'));
                        liEl.classList.add('is-active');
                        liEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                });

                markers.push({ marker, item });
            });

            render();
        })
        .catch(err => {
            console.error('[Psychomapa] Błąd ładowania danych:', err);
            if (listEl) listEl.innerHTML = '<li class="psychomapa-list__empty">Błąd ładowania danych. Odśwież stronę.</li>';
            if (countEl) countEl.textContent = '';
        });

    // ── Event listenery filtrów ───────────────────────────────────────────────
    let debounceTimer;
    const debounce = fn => { clearTimeout(debounceTimer); debounceTimer = setTimeout(fn, 250); };

    searchInput?.addEventListener('input',  () => debounce(render));
    rodzajSelect?.addEventListener('change', render);
    grupaSelect?.addEventListener('change',  render);
});
