/**
 * k6 API test — benchmark REST endpointów
 *
 * Użycie:
 *   k6 run -e BOT_TOKEN=twoj_token audit/k6/api-test.js
 *
 * Testuje:
 *   - /bot-availability (główny endpoint AI chata)
 *   - /bookero-status   (diagnostyczny — tylko admin)
 */

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE      = __ENV.BASE_URL  || 'https://niepodzielni-dev-01.mixturemarketing.pl';
const BOT_TOKEN = __ENV.BOT_TOKEN || '';

export const options = {
    scenarios: {
        availability_pelno: {
            executor: 'constant-vus',
            vus: 10,
            duration: '2m',
            tags: { endpoint: 'bot-availability-pelno' },
        },
        availability_nisko: {
            executor: 'constant-vus',
            vus: 5,
            duration: '2m',
            startTime: '2m30s',
            tags: { endpoint: 'bot-availability-nisko' },
        },
    },
    thresholds: {
        'http_req_duration{endpoint:bot-availability-pelno}': ['p(95)<300', 'p(50)<50'],
        'http_req_duration{endpoint:bot-availability-nisko}': ['p(95)<300', 'p(50)<50'],
        http_req_failed: ['rate<0.01'],
    },
};

const HEADERS = {
    'X-API-Key':      BOT_TOKEN,
    'Accept':         'application/json',
    'User-Agent':     'k6-load-test/1.0',
};

export default function () {
    const type = __VU % 2 === 0 ? 'pelno' : 'nisko';
    const url  = `${BASE}/wp-json/niepodzielni/v1/bot-availability?consult_type=${type}&days=14`;

    const res = http.get(url, {
        headers: HEADERS,
        tags:    { endpoint: `bot-availability-${type}` },
    });

    check(res, {
        'status 200':        r => r.status === 200,
        'has slots':         r => JSON.parse(r.body ?? '{}')?.slots !== undefined,
        'TTFB <200ms':       r => r.timings.waiting < 200,
        // Po 1 min cache powinien działać — pierwsze żądanie może być wolniejsze
        'cached <50ms':      r => r.timings.waiting < 50,
    });

    sleep(1);
}
