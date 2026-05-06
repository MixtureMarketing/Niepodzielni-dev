/**
 * k6 load test — symulacja realistycznego ruchu na niepodzielni.pl
 *
 * Dane: ~30 000 użytkowników/miesiąc → ~1 000/dzień → ~100/h w szczycie
 * Matematyka:
 *   - 80% ruchu między 9:00–17:00 (8h) → 800 sesji/8h → ~100/h
 *   - Średnia sesja: 3–5 min, 4–6 odsłon
 *   - Równoległe VU w szczycie: ~10–12
 *   - Spike (kampania social media / artykuł wirusowy): 2–3× = ~25–35 VU
 *
 * Użycie:
 *   k6 run audit/k6/load-test.js
 *   k6 run -e BASE_URL=https://niepodzielni.pl audit/k6/load-test.js \
 *       --out json=audit/results/k6-$(date +%Y%m%d-%H%M).json
 *
 * Interpretacja wyników:
 *   - p(95) < 1000ms na listing/single → dobry wynik dla VPS bez full-page cache
 *   - p(95) < 100ms po włączeniu Cloudflare Cache Rules → cel docelowy
 *   - TTFB < 200ms → dobry; < 50ms → świetny (CF cache hit)
 */

import http from 'k6/http';
import { sleep, check, group } from 'k6';

const BASE = __ENV.BASE_URL || 'https://niepodzielni-dev-01.mixturemarketing.pl';

export const options = {
    scenarios: {
        // Typowy dzień roboczy — szczyt między 10:00–16:00
        normal: {
            executor: 'constant-vus',
            vus: 12,
            duration: '5m',
            tags: { scenario: 'normal' },
        },
        // Spike — post na Facebooku/Instagramie lub artykuł w mediach
        spike: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '30s', target: 35 },  // gwałtowny wzrost
                { duration: '3m',  target: 35 },  // plateau
                { duration: '1m',  target: 12 },  // opadanie
            ],
            startTime: '6m',
            tags: { scenario: 'spike' },
        },
        // Scenariusz nocny — minimalny ruch (2–3 VU)
        // night: { executor: 'constant-vus', vus: 2, duration: '2m', tags: { scenario: 'night' } },
    },
    thresholds: {
        http_req_duration:                        ['p(95)<2000'],  // absolutne maksimum
        http_req_failed:                          ['rate<0.01'],   // < 1% błędów
        'http_req_duration{page:front}':          ['p(95)<1000', 'p(50)<400'],
        'http_req_duration{page:listing}':        ['p(95)<1500', 'p(50)<600'],
        'http_req_duration{page:single}':         ['p(95)<1500', 'p(50)<600'],
        'http_req_duration{scenario:spike}':      ['p(95)<3000'],  // tolerancja na spike
    },
};

// Rozkład ruchu wg typowego profilu fundacji NGO/zdrowie psychiczne
// Uzupełnij SINGLES o prawdziwe slugi z bazy
const PAGES = [
    { url: `${BASE}/`,             weight: 28, tag: 'front'   },
    { url: `${BASE}/psycholodzy/`, weight: 22, tag: 'listing' },
    { url: `${BASE}/o-nas/`,       weight: 8,  tag: 'about'   },
    { url: `${BASE}/kontakt/`,     weight: 8,  tag: 'contact' },
    // Docelowo: dodaj ~5 slugów psychologów (razem ~25% ruchu)
    // { url: `${BASE}/psycholog/jan-kowalski/`, weight: 5, tag: 'single' },
    // { url: `${BASE}/psycholog/anna-nowak/`,   weight: 5, tag: 'single' },
];

const TOTAL_WEIGHT = PAGES.reduce((s, p) => s + p.weight, 0);

function pickPage() {
    const rand = Math.random() * TOTAL_WEIGHT;
    let cumulative = 0;
    for (const p of PAGES) {
        cumulative += p.weight;
        if (rand <= cumulative) return p;
    }
    return PAGES[0];
}

export default function () {
    const page = pickPage();

    group(page.tag, () => {
        const res = http.get(page.url, {
            headers: {
                'Accept-Encoding': 'gzip, deflate, br',
                'Accept-Language': 'pl-PL,pl;q=0.9,en;q=0.8',
                'Accept':          'text/html,application/xhtml+xml',
            },
            tags: { page: page.tag },
        });

        check(res, {
            'status 200':        r => r.status === 200,
            'TTFB <800ms':       r => r.timings.waiting < 800,
            'body not empty':    r => (r.body?.length ?? 0) > 500,
        });
    });

    // Realistyczny czas między kliknięciami (użytkownik czyta treść)
    sleep(Math.random() * 4 + 2); // 2–6 sekund
}

// ── Opcjonalny summary handler (wyświetla po zakończeniu testu) ──────────────

export function handleSummary(data) {
    const metrics = data.metrics;
    const p95Front   = metrics['http_req_duration{page:front}']?.values?.['p(95)']?.toFixed(0);
    const p95Listing = metrics['http_req_duration{page:listing}']?.values?.['p(95)']?.toFixed(0);
    const p50Front   = metrics['http_req_duration{page:front}']?.values?.['p(50)']?.toFixed(0);
    const errorRate  = (metrics.http_req_failed?.values?.rate * 100)?.toFixed(2);

    const summary = [
        '═══════════════════════════════════════════',
        ' Niepodzielni — Load Test Summary',
        '═══════════════════════════════════════════',
        ` Front page    p50: ${p50Front}ms  p95: ${p95Front}ms`,
        ` Listing       p95: ${p95Listing}ms`,
        ` Error rate:   ${errorRate}%`,
        '───────────────────────────────────────────',
        ' Targets:',
        '   p95 < 1000ms (front)   p95 < 1500ms (listing)',
        '   Error rate < 1%',
        ' After CF Cache Rules: p50 < 50ms expected',
        '═══════════════════════════════════════════',
    ].join('\n');

    return {
        stdout: summary,
        [`audit/results/k6-summary-${new Date().toISOString().slice(0,16).replace('T','-')}.txt`]: summary,
    };
}
