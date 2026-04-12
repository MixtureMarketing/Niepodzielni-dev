import { describe, it, expect } from 'vitest';
import { esc, formatDate, buildBadge, filterData, filterPsychologists } from '../utils/listing.js';

// ================================================================
// esc()
// ================================================================
describe('esc()', () => {
    it('escapes & to &amp;',         () => expect(esc('a & b')).toBe('a &amp; b'));
    it('escapes < and >',            () => expect(esc('<script>')).toBe('&lt;script&gt;'));
    it('escapes double quotes',      () => expect(esc('"quoted"')).toBe('&quot;quoted&quot;'));
    it('returns empty string for null',      () => expect(esc(null)).toBe(''));
    it('returns empty string for undefined', () => expect(esc(undefined)).toBe(''));
    it('leaves plain text unchanged',        () => expect(esc('hello world')).toBe('hello world'));
    it('does not escape single quotes',      () => expect(esc("it's")).toBe("it's"));
    it('converts numbers to string',         () => expect(esc(42)).toBe('42'));
    it('escapes XSS payload fully', () => {
        expect(esc('<a href="x">test & more</a>'))
            .toBe('&lt;a href=&quot;x&quot;&gt;test &amp; more&lt;/a&gt;');
    });
    it('is idempotent for already-safe strings', () => {
        expect(esc('plain text 123')).toBe('plain text 123');
    });
});

// ================================================================
// formatDate()
// ================================================================
describe('formatDate()', () => {
    it('returns a string containing the year',                  () => expect(formatDate('2026-03-18')).toContain('2026'));
    it('returns a string containing the Polish month name',     () => expect(formatDate('2026-03-18').toLowerCase()).toContain('marca'));
    it('returns a string containing the day number',            () => expect(formatDate('2026-03-18')).toContain('18'));
    it('does not throw for an invalid date string',             () => expect(() => formatDate('invalid-date')).not.toThrow());
    it('does not throw for an empty string',                    () => expect(() => formatDate('')).not.toThrow());
    it('returns a string type in all cases',                    () => expect(typeof formatDate('not-a-date')).toBe('string'));
});

// ================================================================
// buildBadge()
// ================================================================
describe('buildBadge()', () => {
    it('returns empty string for inactive items', () => {
        expect(buildBadge({ is_active: false, status: 'Wolne' })).toBe('');
    });
    it('returns green badge when status is empty string', () => {
        const html = buildBadge({ is_active: true, status: '' });
        expect(html).toContain('badge--green');
        expect(html).toContain('Wolne zapisy');
    });
    it('returns green badge when status property is missing', () => {
        expect(buildBadge({ is_active: true })).toContain('badge--green');
    });
    it('returns green badge when status contains "wolne"', () => {
        expect(buildBadge({ is_active: true, status: 'Wolne miejsca' })).toContain('badge--green');
    });
    it('returns orange badge for "zamknięte" (with diacritics)', () => {
        expect(buildBadge({ is_active: true, status: 'Zapisy zamknięte' })).toContain('badge--orange');
    });
    it('returns orange badge for "zamkniete" (without diacritics)', () => {
        expect(buildBadge({ is_active: true, status: 'zamkniete' })).toContain('badge--orange');
    });
    it('returns grey badge for an unrecognized status', () => {
        const html = buildBadge({ is_active: true, status: 'Trwają zapisy' });
        expect(html).toContain('badge--grey');
        expect(html).toContain('Trwają zapisy');
    });
    it('escapes HTML in custom status to prevent XSS', () => {
        const html = buildBadge({ is_active: true, status: '<b>XSS</b>' });
        expect(html).toContain('&lt;b&gt;');
        expect(html).not.toContain('<b>');
    });
});

// ================================================================
// filterData() — warsztaty
// ================================================================
const WORKSHOPS = [
    { post_type: 'warsztaty',      is_active: true  },
    { post_type: 'grupy-wsparcia', is_active: true  },
    { post_type: 'warsztaty',      is_active: false },
];

describe('filterData() — warsztaty', () => {
    it('returns all items when tab=all',            () => expect(filterData(WORKSHOPS, 'warsztaty', 'all')).toHaveLength(3));
    it('filters to warsztaty only',                 () => {
        const result = filterData(WORKSHOPS, 'warsztaty', 'warsztaty');
        expect(result).toHaveLength(2);
        result.forEach(i => expect(i.post_type).toBe('warsztaty'));
    });
    it('filters to grupy-wsparcia only',            () => {
        const result = filterData(WORKSHOPS, 'warsztaty', 'grupy-wsparcia');
        expect(result).toHaveLength(1);
        expect(result[0].post_type).toBe('grupy-wsparcia');
    });
    it('returns empty array for non-existent type', () => expect(filterData(WORKSHOPS, 'warsztaty', 'nieistniejacy')).toHaveLength(0));
    it('does not mutate the original array',        () => {
        const copy = [...WORKSHOPS];
        filterData(WORKSHOPS, 'warsztaty', 'warsztaty');
        expect(WORKSHOPS).toHaveLength(copy.length);
    });
});

// ================================================================
// filterData() — wydarzenia
// ================================================================
const EVENTS = [
    { title: 'Minione',    is_upcoming: false },
    { title: 'Nadchodzące',  is_upcoming: true  },
    { title: 'Nadchodzące2', is_upcoming: true  },
];

describe('filterData() — wydarzenia', () => {
    it('returns all for tab=all',         () => expect(filterData(EVENTS, 'wydarzenia', 'all')).toHaveLength(3));
    it('returns only upcoming events',    () => {
        const result = filterData(EVENTS, 'wydarzenia', 'nadchodzace');
        expect(result).toHaveLength(2);
        result.forEach(e => expect(e.is_upcoming).toBe(true));
    });
    it('returns only archived events',    () => {
        const result = filterData(EVENTS, 'wydarzenia', 'archiwalne');
        expect(result).toHaveLength(1);
        expect(result[0].is_upcoming).toBe(false);
    });
});

// ================================================================
// filterData() — artykuly
// ================================================================
const ARTICLES = [
    { title: 'Artykuł 1', tags: ['tag-a', 'tag-b'] },
    { title: 'Artykuł 2', tags: ['tag-b'] },
    { title: 'Artykuł 3', tags: [] },
    { title: 'Artykuł 4' },            // brak właściwości tags
];

describe('filterData() — artykuly', () => {
    it('returns all for tab=all',                               () => expect(filterData(ARTICLES, 'artykuly', 'all')).toHaveLength(4));
    it('filters by tag-a (1 result)',                           () => {
        const result = filterData(ARTICLES, 'artykuly', 'tag-a');
        expect(result).toHaveLength(1);
        expect(result[0].title).toBe('Artykuł 1');
    });
    it('filters by tag-b (2 results)',                          () => expect(filterData(ARTICLES, 'artykuly', 'tag-b')).toHaveLength(2));
    it('excludes items with missing tags property',             () => {
        const result = filterData(ARTICLES, 'artykuly', 'tag-a');
        expect(result.every(i => Array.isArray(i.tags))).toBe(true);
    });
    it('returns empty for a tag that does not exist',           () => expect(filterData(ARTICLES, 'artykuly', 'nieistniejacy-tag')).toHaveLength(0));
});

// ================================================================
// filterData() — aktualnosci (no filtering)
// ================================================================
describe('filterData() — aktualnosci', () => {
    it('returns all data regardless of tab', () => {
        const news = [{ title: 'N1' }, { title: 'N2' }];
        expect(filterData(news, 'aktualnosci', 'jakikolwiek-tab')).toHaveLength(2);
    });
});

// ================================================================
// filterPsychologists()
// ================================================================
const PSY = [
    {
        title: 'Anna Kowalska',
        wizyta: 'Online',
        has_termin: true,
        obszary: ['lęk', 'depresja'],
        spec:    ['CBT'],
        jezyki:  [{ slug: 'polski' }],
    },
    {
        title: 'Jan Nowak',
        wizyta: 'Stacjonarnie',
        has_termin: false,
        obszary: ['depresja'],
        spec:    ['psychodynamika'],
        jezyki:  [{ slug: 'angielski' }],
    },
    {
        title: 'Maria Wiśniewska',
        wizyta: 'Online, Stacjonarnie',
        has_termin: true,
        obszary: ['lęk', 'trauma'],
        spec:    ['CBT', 'EFT'],
        jezyki:  [{ slug: 'polski' }, { slug: 'ukrainski' }],
    },
];

describe('filterPsychologists()', () => {
    it('default (statusType=available) — returns only those with termin', () => {
        const result = filterPsychologists(PSY);
        expect(result).toHaveLength(2);
        result.forEach(p => expect(p.has_termin).toBe(true));
    });
    it('statusType=all — returns only those WITHOUT termin', () => {
        const result = filterPsychologists(PSY, { statusType: 'all' });
        expect(result).toHaveLength(1);
        expect(result[0].title).toBe('Jan Nowak');
    });
    it('unknown statusType — no termin filtering applied', () => {
        expect(filterPsychologists(PSY, { statusType: 'inne' })).toHaveLength(3);
    });
    it('filters by searchText (case-insensitive)', () => {
        const result = filterPsychologists(PSY, { statusType: 'inne', searchText: 'kowalska' });
        expect(result).toHaveLength(1);
        expect(result[0].title).toBe('Anna Kowalska');
    });
    it('returns empty when searchText matches nothing', () => {
        expect(filterPsychologists(PSY, { statusType: 'inne', searchText: 'xyz-nieistniejący' })).toHaveLength(0);
    });
    it('filters by visitType (exact substring match)', () => {
        // 'Stacjonarnie' matches Jan Nowak + Maria Wiśniewska ("Online, Stacjonarnie")
        const result = filterPsychologists(PSY, { statusType: 'inne', visitType: 'Stacjonarnie' });
        expect(result).toHaveLength(2);
    });
    it('filters by visitType=Online only', () => {
        // Only Anna has exactly "Online"
        const result = filterPsychologists(PSY, { statusType: 'inne', visitType: 'Online' });
        expect(result).toHaveLength(2); // Anna + Maria ("Online, Stacjonarnie" includes "Online")
    });
    it('filters by obszary (OR logic within set)', () => {
        const result = filterPsychologists(PSY, { statusType: 'inne', selectedObszary: ['trauma'] });
        expect(result).toHaveLength(1);
        expect(result[0].title).toBe('Maria Wiśniewska');
    });
    it('filters by obszary matching multiple items', () => {
        const result = filterPsychologists(PSY, { statusType: 'inne', selectedObszary: ['depresja'] });
        expect(result).toHaveLength(2); // Anna + Jan
    });
    it('filters by spec (OR logic within set)', () => {
        const result = filterPsychologists(PSY, { statusType: 'inne', selectedSpecs: ['CBT'] });
        expect(result).toHaveLength(2); // Anna + Maria
    });
    it('filters by language slug', () => {
        const result = filterPsychologists(PSY, { statusType: 'inne', selectedLangs: ['ukrainski'] });
        expect(result).toHaveLength(1);
        expect(result[0].title).toBe('Maria Wiśniewska');
    });
    it('combines multiple filters (AND logic between dimensions)', () => {
        const result = filterPsychologists(PSY, {
            statusType:     'inne',
            selectedSpecs:  ['CBT'],
            selectedLangs:  ['ukrainski'],
        });
        expect(result).toHaveLength(1);
        expect(result[0].title).toBe('Maria Wiśniewska');
    });
    it('returns empty array for empty input data', () => {
        expect(filterPsychologists([], { statusType: 'inne' })).toHaveLength(0);
    });
    it('does not mutate the input array', () => {
        const copy = [...PSY];
        filterPsychologists(PSY, { statusType: 'inne', searchText: 'anna' });
        expect(PSY).toHaveLength(copy.length);
    });
});
