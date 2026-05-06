/**
 * k6 load test — symulacja realistycznego ruchu na niepodzielni.pl
 *
 * Użycie:
 *   k6 run audit/k6/load-test.js
 *   k6 run audit/k6/load-test.js --out json=audit/results/k6-$(date +%Y%m%d-%H%M).json
 *
 * Scenariusz "normal" — 20 VU przez 5 min (szacowany ruch dzienny)
 * Scenariusz "spike"  — do 80 VU przez 2 min (symulacja kampanii social media)
 */

import http from 'k6/http';
import { sleep, check, group } from 'k6';

const BASE = __ENV.BASE_URL || 'https://niepodzielni-dev-01.mixturemarketing.pl';

export const options = {
    scenarios: {
        normal: {
            executor: 'constant-vus',
            vus: 20,
            duration: '5m',
            tags: { scenario: 'normal' },
        },
        spike: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '30s', target: 80 },
                { duration: '2m',  target: 80 },
                { duration: '30s', target: 0 },
            ],
            startTime: '6m',
            tags: { scenario: 'spike' },
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<2000'],
        http_req_failed:   ['rate<0.01'],
        'http_req_duration{page:front}':    ['p(95)<1000'],
        'http_req_duration{page:listing}':  ['p(95)<1500'],
        'http_req_duration{page:single}':   ['p(95)<1500'],
    },
};

// Rozkład ruchu (%)
const PAGES = [
    { url: `${BASE}/`,             weight: 30, tag: 'front'   },
    { url: `${BASE}/psycholodzy/`, weight: 25, tag: 'listing' },
    { url: `${BASE}/o-nas/`,       weight: 10, tag: 'about'   },
    { url: `${BASE}/kontakt/`,     weight: 10, tag: 'contact' },
];
// single psycholog — uzupełnij slugami z bazy
const SINGLES = [
    // { url: `${BASE}/psycholog/jan-kowalski/`, weight: 5, tag: 'single' },
];

const ALL_PAGES = [...PAGES, ...SINGLES];
const TOTAL_WEIGHT = ALL_PAGES.reduce((s, p) => s + p.weight, 0);

function pickPage() {
    const rand = Math.random() * TOTAL_WEIGHT;
    let cumulative = 0;
    for (const p of ALL_PAGES) {
        cumulative += p.weight;
        if (rand <= cumulative) return p;
    }
    return ALL_PAGES[0];
}

export default function () {
    const page = pickPage();

    group(page.tag, () => {
        const res = http.get(page.url, {
            headers: {
                'Accept-Encoding': 'gzip, deflate, br',
                'Accept-Language': 'pl-PL,pl;q=0.9',
            },
            tags: { page: page.tag },
        });

        check(res, {
            'status 200':   r => r.status === 200,
            'TTFB <800ms':  r => r.timings.waiting < 800,
            'body not empty': r => (r.body?.length ?? 0) > 100,
        });
    });

    // Realistyczny czas między akcjami (1-4 sekundy)
    sleep(Math.random() * 3 + 1);
}
