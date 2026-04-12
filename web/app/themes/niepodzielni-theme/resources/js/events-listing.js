/**
 * Events Listing — unified client-side filtering for all 4 listing pages.
 * Reads window.npListingConfig injected by PHP (wp_localize_script).
 *
 * Supported types: 'warsztaty' | 'wydarzenia' | 'aktualnosci' | 'artykuly'
 */
import { esc, formatDate, buildBadge, filterData } from './utils/listing.js';

(function () {
    'use strict';

    function initListing() {
        const cfg = window.npListingConfig;
        if (!cfg || !cfg.data) {
            setTimeout(initListing, 200);
            return;
        }

        const type        = cfg.type;
        const data        = cfg.data;
        const perPage     = cfg.perPage || 9;

        const tabsEl      = document.getElementById('nlisting-tabs-form');
        const gridEl      = document.getElementById('nlisting-grid');
        const pagEl       = document.getElementById('nlisting-pagination');
        const activeEl    = document.getElementById('nlisting-active');
        const inactiveEl  = document.getElementById('nlisting-inactive');

        if (!gridEl && !activeEl) return;

        let currentPage = 1;
        let currentTab  = 'all';

        // --------------------------------------------------------
        // Tab change
        // --------------------------------------------------------
        if (tabsEl) {
            tabsEl.addEventListener('change', function (e) {
                if (e.target.name === 'listing-tab') {
                    currentTab = e.target.value;
                    currentPage = 1;

                    // Sync active class on labels
                    tabsEl.querySelectorAll('.nlisting-tabs__tab').forEach(label => {
                        label.classList.toggle('is-active', label.querySelector('input').value === currentTab);
                    });

                    render();
                }
            });
        }

        // filterData, esc, formatDate, buildBadge — imported from ./utils/listing.js

        // --------------------------------------------------------
        // Render
        // --------------------------------------------------------
        function render() {
            const filtered = filterData(data, type, currentTab);

            if (type === 'warsztaty') {
                renderActiveSections(filtered);
            } else {
                renderSimpleGrid(filtered);
            }
        }

        // Simple paginated grid (aktualnosci, wydarzenia, artykuly)
        function renderSimpleGrid(filtered) {
            const total = filtered.length;
            const start = (currentPage - 1) * perPage;
            const page  = filtered.slice(start, start + perPage);

            if (!gridEl) return;

            if (page.length === 0) {
                gridEl.innerHTML = '<p class="nlisting-no-results">Brak wpisów spełniających kryteria.</p>';
            } else {
                gridEl.innerHTML = page.map(renderCard).join('');
            }

            renderPagination(total, gridEl.parentElement);
        }

        // Active + inactive sections (warsztaty/grupy)
        function renderActiveSections(filtered) {
            const active   = filtered.filter(item => item.is_active);
            const inactive = filtered.filter(item => !item.is_active);

            if (activeEl) {
                const activeGrid = activeEl.querySelector('.nlisting-grid');
                if (activeGrid) activeGrid.innerHTML = active.length
                    ? active.map(renderCard).join('')
                    : '<p class="nlisting-no-results">Brak aktywnych wydarzeń.</p>';
            }

            if (inactiveEl) {
                inactiveEl.style.display = inactive.length ? '' : 'none';
                const inactiveGrid = inactiveEl.querySelector('.nlisting-grid');
                if (inactiveGrid) inactiveGrid.innerHTML = inactive.map(renderCard).join('');
            }
        }

        // --------------------------------------------------------
        // Card renderers per type
        // --------------------------------------------------------
        function renderCard(item) {
            if (type === 'warsztaty')    return renderWorkshopCard(item);
            if (type === 'wydarzenia')   return renderEventCard(item);
            return renderArticleCard(item);
        }

        function renderArticleCard(item) {
            const date  = item.date ? formatDate(item.date) : '';
            const place = item.miejsce ? `<span class="nlisting-card__place">${iconPin()}${esc(item.miejsce)}</span>` : '';
            const meta  = (date || place) ? `<div class="nlisting-card__meta">${date ? `<span class="nlisting-card__date">${iconCal()}${date}</span>` : ''}${place}</div>` : '';
            const thumb = item.thumb
                ? `<img src="${esc(item.thumb)}" alt="${esc(item.title)}" loading="lazy">`
                : `<div class="nlisting-card__media-placeholder"></div>`;

            return `
<article class="nlisting-card nlisting-card--article" data-tags="${esc((item.tags || []).join(','))}">
  <a href="${esc(item.link)}" class="nlisting-card__media-link" tabindex="-1" aria-hidden="true">
    <div class="nlisting-card__media">
      ${thumb}
      <div class="nlisting-card__overlay"><h3 class="nlisting-card__overlay-title">${esc(item.title)}</h3></div>
    </div>
  </a>
  <div class="nlisting-card__body">
    ${meta}
    <h3 class="nlisting-card__title"><a href="${esc(item.link)}">${esc(item.title)}</a></h3>
    ${item.excerpt ? `<p class="nlisting-card__excerpt">${esc(item.excerpt)}</p>` : ''}
    <a href="${esc(item.link)}" class="nlisting-card__cta">Czytaj więcej</a>
  </div>
</article>`;
        }

        function renderEventCard(item) {
            const dateStr  = item.date ? formatDate(item.date) : '';
            const timeStr  = item.time_start ? ` &nbsp;${esc(item.time_start)}${item.time_end ? '–' + esc(item.time_end) : ''}` : '';
            const location = [item.miasto, item.lokalizacja].filter(Boolean).join(', ');
            const thumb = item.thumb
                ? `<img src="${esc(item.thumb)}" alt="${esc(item.title)}" loading="lazy">`
                : `<div class="nlisting-card__media-placeholder"></div>`;

            return `
<article class="nlisting-card nlisting-card--event" data-upcoming="${item.is_upcoming ? 1 : 0}">
  <a href="${esc(item.link)}" class="nlisting-card__media-link" tabindex="-1" aria-hidden="true">
    <div class="nlisting-card__media">${thumb}</div>
  </a>
  <div class="nlisting-card__body">
    <div class="nlisting-card__meta">
      ${dateStr ? `<span class="nlisting-card__date">${iconCal()}${dateStr}${timeStr}</span>` : ''}
      ${location ? `<span class="nlisting-card__place">${iconPin()}${esc(location)}</span>` : ''}
    </div>
    <h3 class="nlisting-card__title"><a href="${esc(item.link)}">${esc(item.title)}</a></h3>
    ${item.opis ? `<p class="nlisting-card__excerpt">${esc(item.opis)}</p>` : ''}
    <div class="nlisting-card__footer">
      ${item.koszt ? `<span class="nlisting-card__price">${esc(item.koszt)}</span>` : ''}
      <a href="${esc(item.link)}" class="nlisting-card__cta">Czytaj więcej</a>
    </div>
  </div>
</article>`;
        }

        function renderWorkshopCard(item) {
            const typeLabel = item.post_type === 'grupy-wsparcia' ? 'Grupa wsparcia' : 'Warsztat';
            const dateStr   = item.date ? formatDate(item.date) : '';
            const timeStr   = item.time ? ` &nbsp;${esc(item.time)}` : '';
            const badge     = buildBadge(item);
            const price     = item.cena ? `<span class="nlisting-card__price">${esc(item.cena)}${item.cena_rodzaj ? ' / ' + esc(item.cena_rodzaj) : ''}</span>` : '';
            const author    = item.prowadzacy
                ? `<div class="nlisting-card__author"><span class="nlisting-card__author-name">${esc(item.prowadzacy)}</span>${item.stanowisko ? `<span class="nlisting-card__author-role">${esc(item.stanowisko)}</span>` : ''}</div>`
                : '';
            const thumb = item.thumb
                ? `<img src="${esc(item.thumb)}" alt="${esc(item.title)}" loading="lazy">`
                : `<div class="nlisting-card__media-placeholder"></div>`;

            return `
<article class="nlisting-card nlisting-card--workshop ${item.post_type === 'grupy-wsparcia' ? 'nlisting-card--group' : ''} ${!item.is_active ? 'is-inactive' : ''}"
         data-post-type="${esc(item.post_type)}" data-active="${item.is_active ? 1 : 0}">
  <a href="${esc(item.link)}" class="nlisting-card__media-link" tabindex="-1" aria-hidden="true">
    <div class="nlisting-card__media">
      ${thumb}
      <span class="nlisting-card__type-tag">${typeLabel}</span>
    </div>
  </a>
  <div class="nlisting-card__body">
    <div class="nlisting-card__meta">
      ${dateStr ? `<span class="nlisting-card__date">${iconCal()}${dateStr}${timeStr}</span>` : ''}
      ${item.lokalizacja ? `<span class="nlisting-card__place">${iconPin()}${esc(item.lokalizacja)}</span>` : ''}
    </div>
    <h3 class="nlisting-card__title"><a href="${esc(item.link)}">${esc(item.title)}</a></h3>
    ${item.excerpt ? `<p class="nlisting-card__excerpt">${esc(item.excerpt)}</p>` : ''}
    ${author}
    <div class="nlisting-card__footer">
      ${badge}${price}
      <a href="${esc(item.link)}" class="nlisting-card__cta">Szczegóły</a>
    </div>
  </div>
</article>`;
        }

        // --------------------------------------------------------
        // Pagination
        // --------------------------------------------------------
        function renderPagination(total, container) {
            const pages = Math.ceil(total / perPage);
            if (!pagEl) return;

            if (pages <= 1) {
                pagEl.innerHTML = '';
                return;
            }

            let html = '';
            for (let i = 1; i <= pages; i++) {
                html += `<button type="button" class="nlisting-page-btn ${i === currentPage ? 'is-active' : ''}" data-page="${i}">${i}</button>`;
            }
            pagEl.innerHTML = html;

            pagEl.querySelectorAll('.nlisting-page-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    currentPage = parseInt(this.dataset.page);
                    render();
                    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });
        }

        // --------------------------------------------------------
        // Helpers
        // --------------------------------------------------------
        function iconCal() {
            return '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><rect x="1" y="2" width="10" height="9" rx="1" stroke="currentColor" stroke-width="1.2"/><path d="M1 5h10M4 1v2M8 1v2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>';
        }

        function iconPin() {
            return '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M6 1C4.34 1 3 2.34 3 4c0 2.25 3 7 3 7s3-4.75 3-7c0-1.66-1.34-3-3-3z" stroke="currentColor" stroke-width="1.2"/><circle cx="6" cy="4" r="1" fill="currentColor"/></svg>';
        }

        // --------------------------------------------------------
        // Init render
        // --------------------------------------------------------
        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initListing);
    } else {
        initListing();
    }
})();
