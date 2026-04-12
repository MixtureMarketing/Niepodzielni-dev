/**
 * listing.js — shared pure utility functions for listing pages.
 * No DOM access, no globals — safe to unit test.
 */

/**
 * HTML-escape a string value.
 * @param {*} str
 * @returns {string}
 */
export function esc(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Format a date string in Polish locale (e.g. "18 marca 2026").
 * @param {string} dateStr
 * @returns {string}
 */
export function formatDate(dateStr) {
    try {
        return new Intl.DateTimeFormat('pl-PL', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }).format(new Date(dateStr));
    } catch {
        return dateStr;
    }
}

/**
 * Build status badge HTML for a workshop/group item.
 * @param {{ is_active: boolean, status?: string }} item
 * @returns {string}
 */
export function buildBadge(item) {
    if (!item.is_active) return '';
    const s = (item.status || '').toLowerCase();
    if (s.includes('wolne') || s === '') return `<span class="nlisting-badge badge--green">Wolne zapisy</span>`;
    if (s.includes('zamknięt') || s.includes('zamkniete')) return `<span class="nlisting-badge badge--orange">Zapisy zamknięte</span>`;
    return `<span class="nlisting-badge badge--grey">${esc(item.status)}</span>`;
}

/**
 * Filter listing data by tab value — pure function.
 * @param {Array}  data
 * @param {string} type  'warsztaty'|'wydarzenia'|'artykuly'|'aktualnosci'
 * @param {string} tab
 * @returns {Array}
 */
export function filterData(data, type, tab) {
    if (type === 'warsztaty') {
        if (tab === 'all') return data;
        return data.filter(item => item.post_type === tab);
    }
    if (type === 'wydarzenia') {
        if (tab === 'all')         return data;
        if (tab === 'nadchodzace') return data.filter(item => item.is_upcoming);
        if (tab === 'archiwalne')  return data.filter(item => !item.is_upcoming);
    }
    if (type === 'artykuly') {
        if (tab === 'all') return data;
        return data.filter(item => Array.isArray(item.tags) && item.tags.includes(tab));
    }
    return data;
}

/**
 * Filter psychologist records by search/filter criteria — pure function.
 * @param {Array} data
 * @param {{ searchText?, visitType?, statusType?, selectedObszary?, selectedSpecs?, selectedLangs? }} criteria
 * @returns {Array}
 */
export function filterPsychologists(data, {
    searchText      = '',
    visitType       = '',
    statusType      = 'available',
    selectedObszary = [],
    selectedSpecs   = [],
    selectedLangs   = [],
} = {}) {
    return data.filter(p => {
        if (searchText && !p.title.toLowerCase().includes(searchText)) return false;
        if (visitType  && !p.wizyta.includes(visitType))  return false;
        if (statusType === 'available' && !p.has_termin)  return false;
        if (statusType === 'all'       &&  p.has_termin)  return false;
        if (selectedObszary.length && !selectedObszary.some(s => p.obszary.includes(s))) return false;
        if (selectedSpecs.length   && !selectedSpecs.some(s => p.spec.includes(s)))     return false;
        if (selectedLangs.length   && !selectedLangs.some(s => p.jezyki.some(l => l.slug === s))) return false;
        return true;
    });
}
