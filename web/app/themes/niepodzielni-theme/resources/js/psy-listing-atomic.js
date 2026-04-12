/**
 * Psychologist Listing - Atomic JS Logic (Animation Optimized v8)
 */
import { filterPsychologists, esc } from './utils/listing.js';

/** Strip HTML tags — używane przed substring() na biogramach z bazy */
function stripHtml(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return tmp.textContent || tmp.innerText || '';
}

(function() {
    'use strict';

    function initListing() {
        const filterForm = document.getElementById('psy-filter-form');
        const data = window.allPsycholodzy;

        if (!filterForm || !data) {
            if (!data) setTimeout(initListing, 200);
            return;
        }

        const listTarget = document.getElementById('psy-listing-target');
        const pagTarget = document.getElementById('psy-pagination-target');
        const searchInput = document.getElementById('psy-search');

        let currentPage = 1;
        const perPage = 10;

        function refreshList() {
            const searchText = (searchInput) ? searchInput.value.toLowerCase() : '';
            const visitType = (filterForm.querySelector('input[name="wizyta"]:checked') || {value:''}).value;
            const statusType = (filterForm.querySelector('input[name="status"]:checked') || {value:'available'}).value;

            const selectedObszary = getSelected('obszar-pomocy');
            const selectedSpecs = getSelected('specjalizacja');
            const selectedLangs = getSelected('jezyk');

            let filtered = filterPsychologists(data, {
                searchText, visitType, statusType,
                selectedObszary, selectedSpecs, selectedLangs,
            });

            filtered.sort((a, b) => a.sort_date.localeCompare(b.sort_date));
            updateFacets();
            renderPage(filtered);
        }

        function updateFacets() {
            // Hoist DOM reads once, outside the dropdown loop
            const s  = searchInput ? searchInput.value.toLowerCase() : '';
            const v  = (filterForm.querySelector('input[name="wizyta"]:checked') || {value:''}).value;
            const st = (filterForm.querySelector('input[name="status"]:checked') || {value:'available'}).value;

            document.querySelectorAll('.psy-multiselect-dropdown').forEach(dropdown => {
                const tax = dropdown.dataset.tax;
                const activeSet = new Set();

                data.forEach(p => {
                    if (s && !p.title.toLowerCase().includes(s)) return;
                    if (v && !p.wizyta.includes(v)) return;
                    if (st === 'available' && !p.has_termin) return;
                    if (st === 'all' && p.has_termin) return;

                    if (tax !== 'obszar-pomocy' && getSelected('obszar-pomocy').length && !getSelected('obszar-pomocy').some(x => p.obszary.includes(x))) return;
                    if (tax !== 'specjalizacja' && getSelected('specjalizacja').length && !getSelected('specjalizacja').some(x => p.spec.includes(x))) return;
                    if (tax !== 'jezyk' && getSelected('jezyk').length && !getSelected('jezyk').some(x => p.jezyki.some(l => l.slug === x))) return;

                    const pVals = (tax === 'obszar-pomocy') ? p.obszary : (tax === 'specjalizacja' ? p.spec : p.jezyki.map(l => l.slug));
                    pVals.forEach(val => activeSet.add(val));
                });

                dropdown.querySelectorAll('.multiselect-option').forEach(opt => {
                    const input = opt.querySelector('input');
                    const isActive = activeSet.has(input.value);
                    opt.classList.toggle('opt-inactive', !isActive);
                    input.disabled = (!isActive && !input.checked);
                    
                    const nameSpan = opt.querySelector('.opt-name');
                    if (nameSpan) {
                        const orig = opt.dataset.originalText || nameSpan.textContent.replace(' (brak)', '');
                        if (!opt.dataset.originalText) opt.dataset.originalText = orig;
                        nameSpan.textContent = isActive ? orig : orig + ' (brak)';
                    }
                });

                if (dropdown.querySelectorAll('input:checked').length === 0) {
                    const list = dropdown.querySelector('.multiselect-options-list');
                    const items = Array.from(list.children);
                    const groups = []; let currentGroup = null;
                    items.forEach(el => {
                        if (el.classList.contains('multiselect-group-header')) {
                            if (currentGroup) groups.push(currentGroup);
                            currentGroup = { h: el, c: [] };
                        } else if (currentGroup) currentGroup.c.push(el);
                        else groups.push({ h: null, c: [el] });
                    });
                    if (currentGroup) groups.push(currentGroup);
                    groups.forEach(g => {
                        g.c.sort((a,b) => a.classList.contains('opt-inactive') ? 1 : -1);
                        g.active = g.c.some(c => !c.classList.contains('opt-inactive'));
                        if (g.h) g.h.classList.toggle('group-inactive', !g.active);
                    });
                    groups.sort((a,b) => a.active === b.active ? 0 : (a.active ? -1 : 1));
                    list.innerHTML = '';
                    groups.forEach(g => { if (g.h) list.appendChild(g.h); g.c.forEach(c => list.appendChild(c)); });
                }
                updateDropdownLabel(dropdown);
            });
        }

        function renderPage(filteredData) {
            const totalPages = Math.ceil(filteredData.length / perPage);
            if (currentPage > totalPages) currentPage = 1;
            const pageData = filteredData.slice((currentPage - 1) * perPage, currentPage * perPage);

            listTarget.style.opacity = '0';

            // Buduj DOM natychmiast (bez sztucznego 200ms delay) i fade-in przez double-rAF
            {
                const fragment = document.createDocumentFragment();
                const grid = document.createElement('div');
                grid.className = 'psy-list-grid';

                if (pageData.length === 0) {
                    const empty = document.createElement('div');
                    empty.style.cssText = 'text-align:center; padding:100px; width:100%;';
                    empty.textContent = 'Brak wyników spełniających kryteria.';
                    grid.appendChild(empty);
                } else {
                    pageData.forEach(p => {
                        const card = document.createElement('div');
                        card.className = 'psy-card-item';
                        
                        // Logika Show More/Less dla tagów
                        const maxVisibleTags = 6;
                        const hasMore = p.obszary_n.length > maxVisibleTags;
                        const visibleTags = p.obszary_n.slice(0, maxVisibleTags);
                        const hiddenTags = p.obszary_n.slice(maxVisibleTags);
                        
                        let tagsHtml = visibleTags.map(t => `<span class="psy-tag-small">${t}</span>`).join('');
                        if (hasMore) {
                            tagsHtml += `<div class="psy-hidden-tags-wrapper">` + hiddenTags.map(t => `<span class="psy-tag-small">${t}</span>`).join('') + `</div>`;
                            tagsHtml += `<span class="psy-tag-more" data-count="${hiddenTags.length}">+ ${hiddenTags.length} WIĘCEJ</span>`;
                        }

                        const langsHtml = p.jezyki.map(l => `<div class="psy-lang-item"><span class="fi fi-${esc(l.flag)}"></span>${esc(l.name)}</div>`).join('');
                        const bioText   = stripHtml(p.bio).substring(0, 280);

                        card.innerHTML = `
                            <div class="psy-card-photo-wrapper"><img src="${esc(p.thumb || '')}" loading="lazy"></div>
                            <div class="psy-card-content">
                                <div class="psy-card-meta-top">
                                    <div class="psy-meta-visit-type">${esc(p.wizyta)}</div>
                                    <div class="psy-meta-price">${esc(p.stawka)} / 50 min</div>
                                    <div class="psy-meta-availability">
                                        <span class="availability-label">Najbliższy termin:</span>
                                        <span class="availability-date">${esc(p.termin)}</span>
                                    </div>
                                </div>
                                <div class="psy-card-main-info">
                                    <h3 class="psy-card-name">${esc(p.title)}</h3>
                                    <div class="psy-card-role">${esc(p.rola)}</div>
                                    <div class="psy-card-tags">${tagsHtml}</div>
                                    <div class="psy-card-bio-text">${esc(bioText)}...</div>
                                    <div class="psy-card-languages">${langsHtml}</div>
                                    <a href="${esc(p.link)}" class="psy-btn psy-btn-card">Zobacz profil</a>
                                </div>
                            </div>`;
                        grid.appendChild(card);
                    });
                }
                
                fragment.appendChild(grid);
                listTarget.innerHTML = '';
                listTarget.appendChild(fragment);

                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        listTarget.style.opacity = '1';
                        listTarget.querySelectorAll('.psy-card-item').forEach((el, i) => setTimeout(() => el.classList.add('is-visible'), i * 50));
                    });
                });
                renderPagination(filteredData.length);
            }
        }

        function getSelected(tax) {
            return Array.from(filterForm.querySelectorAll(`input[name="${tax}"]:checked`)).map(i => i.value);
        }

        function updateDropdownLabel(dropdown) {
            const checked = dropdown.querySelectorAll('input:checked');
            const label = dropdown.querySelector('.multiselect-label');
            label.textContent = checked.length === 0 ? dropdown.dataset.label : (checked.length === 1 ? checked[0].parentElement.textContent.trim() : `Wybrano: ${checked.length}`);
        }

        function renderPagination(total) {
            const totalPages = Math.ceil(total / perPage);
            pagTarget.innerHTML = '';
            if (totalPages > 1) {
                const pag = document.createElement('div');
                pag.className = 'psy-pagination';
                for(let i=1; i<=totalPages; i++) {
                    const btn = document.createElement('button');
                    btn.className = `psy-page-link ${i===currentPage?'active':''}`;
                    btn.dataset.page = i;
                    btn.textContent = i;
                    pag.appendChild(btn);
                }
                pagTarget.appendChild(pag);
            }
        }

        // --- EVENTS ---

        document.addEventListener('click', (e) => {
            const label = e.target.closest('.multiselect-label');
            if (label) {
                const dropdown = label.parentElement;
                const content = dropdown.querySelector('.multiselect-content');
                const isOpen = content.classList.contains('is-open');
                document.querySelectorAll('.multiselect-content').forEach(c => c.classList.remove('is-open'));
                if (!isOpen) content.classList.add('is-open');
            } else if (!e.target.closest('.psy-multiselect-dropdown')) {
                document.querySelectorAll('.multiselect-content').forEach(c => c.classList.remove('is-open'));
            }
        });

        // Toggle tagów (Show More / Less) z animacją
        listTarget.addEventListener('click', (e) => {
            const moreBtn = e.target.closest('.psy-tag-more');
            if (moreBtn) {
                const container = moreBtn.parentElement;
                const isExpanded = container.classList.toggle('is-expanded');
                moreBtn.classList.toggle('is-expanded', isExpanded);
                
                if (isExpanded) {
                    moreBtn.textContent = 'Zwiń';
                } else {
                    moreBtn.textContent = '+ ' + moreBtn.dataset.count + ' WIĘCEJ';
                }
            }
        });

        filterForm.addEventListener('change', () => { currentPage = 1; refreshList(); });
        if (searchInput) {
            searchInput.addEventListener('input', () => { currentPage = 1; refreshList(); });
        }

        document.querySelectorAll('.multiselect-inner-search').forEach(input => {
            input.addEventListener('input', (e) => {
                const val = e.target.value.toLowerCase();
                const list = e.target.closest('.multiselect-content').querySelector('.multiselect-options-list');
                list.querySelectorAll('.multiselect-option').forEach(opt => {
                    opt.classList.toggle('opt-hidden', !opt.textContent.toLowerCase().includes(val));
                });
            });
        });

        pagTarget.addEventListener('click', (e) => {
            const btn = e.target.closest('.psy-page-link');
            if (btn) {
                currentPage = parseInt(btn.dataset.page); refreshList();
                window.scrollTo({ top: document.getElementById('listing').offsetTop - 20, behavior: 'smooth' });
            }
        });

        filterForm.addEventListener('reset', () => setTimeout(() => { 
            currentPage = 1; document.querySelectorAll('.multiselect-label').forEach(l => l.textContent = l.parentElement.dataset.label); refreshList(); 
        }, 10));

        // IntersectionObserver for Sticky detection
        const filterBox = document.getElementById('psy-filter-form');
        if (filterBox) {
            const observer = new IntersectionObserver(
                ([e]) => filterBox.classList.toggle('is-stuck', e.intersectionRatio < 1),
                { threshold: [1], rootMargin: '-1px 0px 0px 0px' }
            );
            observer.observe(filterBox);
        }

        // Mobile Filter Toggle
        const mobileToggleBtn = document.getElementById('psy-mobile-toggle-btn');
        const secondaryFilters = document.getElementById('psy-secondary-filters');
        if (mobileToggleBtn && secondaryFilters) {
            mobileToggleBtn.addEventListener('click', () => {
                const isOpen = secondaryFilters.classList.toggle('is-open');
                mobileToggleBtn.classList.toggle('is-active', isOpen);
                // Close any open dropdowns when toggling the panel
                document.querySelectorAll('.multiselect-content').forEach(c => c.classList.remove('is-open'));
            });
        }

        // Close dropdowns on scroll (for mobile horizontal bar)
        const filtersRow = document.querySelector('.psy-filters-row');
        if (filtersRow) {
            filtersRow.addEventListener('scroll', () => {
                document.querySelectorAll('.multiselect-content').forEach(c => c.classList.remove('is-open'));
            });
        }

        refreshList();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initListing);
    } else {
        initListing();
    }
})();
