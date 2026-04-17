/**
 * Testy E2E — Wspólny Kalendarz Bookero
 *
 * Wszystkie wywołania do /wp-admin/admin-ajax.php są przechwytywane przez
 * page.route(), dzięki czemu testy NIE generują rzeczywistych rezerwacji
 * ani nie uderzają w API Bookero.
 *
 * Strategia mocków:
 *   bk_get_shared_month  — zwraca syntetyczny miesiąc z jednym dostępnym dniem
 *   bk_get_date_slots    — zwraca jednego specjalistę z 3 godzinami
 *   bk_verify_hour       — zawsze zwraca { removed: [] } (graceful degradation)
 *   bk_create_booking    — zwraca sukces bez payment_url → ekran potwierdzenia
 *
 * URL strony z kalendarzem (zmienna środowiskowa lub domyślna):
 *   PLAYWRIGHT_CALENDAR_URL=http://localhost:8000/psychologowie/
 */

import { test, expect } from '@playwright/test';

// ─── Dane pomocnicze — daty dynamiczne ────────────────────────────────────────

const MONTHS_PL = [
    '', 'Stycznia', 'Lutego', 'Marca', 'Kwietnia', 'Maja', 'Czerwca',
    'Lipca', 'Sierpnia', 'Września', 'Października', 'Listopada', 'Grudnia',
];

function todayStr() {
    return new Date().toISOString().slice(0, 10);
}

/** Data +3 dni od dziś — zawsze w przyszłości, zawsze w bieżącym lub następnym miesiącu. */
function futureDateStr() {
    const d = new Date();
    d.setDate(d.getDate() + 3);
    return d.toISOString().slice(0, 10);
}

function currentYearMonth() {
    return new Date().toISOString().slice(0, 7);
}

function firstDowOfMonth() {
    const d     = new Date();
    const first = new Date(d.getFullYear(), d.getMonth(), 1);
    const dow   = first.getDay(); // 0=Nd, 1=Pn … 6=Sb
    return dow === 0 ? 7 : dow;
}

function daysInCurrentMonth() {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
}

function currentMonthName() {
    const d = new Date();
    return `${MONTHS_PL[d.getMonth() + 1]} ${d.getFullYear()}`;
}

// ─── Mockowe dane ─────────────────────────────────────────────────────────────

/** Przykładowy specjalista używany we wszystkich scenariuszach. */
const MOCK_WORKER = {
    bookero_id:  'e2e-worker-001',
    cal_hash:    'E2E_CAL_HASH',
    service_id:  99999,
    name:        'Dr. Anna E2E-Testowa',
    avatar:      null,
    price:       '55 zł',
    rodzaj:      'Online',
    profile_url: '/psycholog/anna-e2e/',
    hours:       [],
};

/** Odpowiedź bk_get_shared_month — jeden dostępny dzień w bieżącym miesiącu. */
function buildMonthMock(availDate) {
    return {
        success: true,
        data: {
            month_name:    currentMonthName(),
            year_month:    currentYearMonth(),
            first_dow:     firstDowOfMonth(),
            days_in_month: daysInCurrentMonth(),
            today:         todayStr(),
            oldest_sync:   Math.floor(Date.now() / 1000) - 30,
            dates: {
                [availDate]: [{ ...MOCK_WORKER }],
            },
        },
    };
}

/** Odpowiedź bk_get_date_slots — ten sam specjalista, trzy godziny. */
const MOCK_DATE_SLOTS = {
    success: true,
    data: {
        workers: [{ ...MOCK_WORKER, hours: ['10:00', '11:00', '14:00'] }],
    },
};

/**
 * Odpowiedź bk_verify_hour — graceful degradation po Rate Limit.
 * removed: [] oznacza "backend wyłapał limit, nikogo nie blokujemy".
 */
const MOCK_VERIFY_HOUR_GRACEFUL = {
    success: true,
    data: { removed: [] },
};

/**
 * Odpowiedź bk_create_booking — sukces bez URL płatności.
 * payment_url = '' (falsy) → JavaScript wywołuje _showBookingConfirmed().
 */
const MOCK_CREATE_BOOKING_SUCCESS = {
    success: true,
    data: {
        payment_url:       '',
        inquiry_id:        1001,
        plugin_inquiry_id: 2001,
        status:            'confirmed',
    },
};

// ─── Helper: parsowanie action z FormData ─────────────────────────────────────

/**
 * Wyciąga wartość pola "action" z surowego ciała multipart/form-data.
 * Bookero JS wysyła FormData przez fetch — Playwright zwraca to jako string.
 */
function parseAjaxAction(postData) {
    const match = (postData ?? '').match(/name="action"\r\n\r\n([^\r\n-]+)/);
    return match ? match[1].trim() : '';
}

// ─── Helper: instalacja przechwytywania żądań AJAX ────────────────────────────

/**
 * Montuje page.route() na /wp-admin/admin-ajax.php.
 * Każda akcja AJAX dostaje własną mockową odpowiedź JSON.
 * Nieznane akcje są przepuszczane bez zmian (route.continue()).
 */
async function mockAjaxRoutes(page, availDate) {
    await page.route('**/admin-ajax.php', async (route, request) => {
        const action = parseAjaxAction(request.postData());

        switch (action) {
            case 'bk_get_shared_month':
                return route.fulfill({
                    status:      200,
                    contentType: 'application/json',
                    body:        JSON.stringify(buildMonthMock(availDate)),
                });

            case 'bk_get_date_slots':
                return route.fulfill({
                    status:      200,
                    contentType: 'application/json',
                    body:        JSON.stringify(MOCK_DATE_SLOTS),
                });

            case 'bk_verify_hour':
                return route.fulfill({
                    status:      200,
                    contentType: 'application/json',
                    body:        JSON.stringify(MOCK_VERIFY_HOUR_GRACEFUL),
                });

            case 'bk_create_booking':
                return route.fulfill({
                    status:      200,
                    contentType: 'application/json',
                    body:        JSON.stringify(MOCK_CREATE_BOOKING_SUCCESS),
                });

            default:
                return route.continue();
        }
    });
}

// ─── URL kalendarza ───────────────────────────────────────────────────────────

const CALENDAR_PATH = process.env.PLAYWRIGHT_CALENDAR_URL || '/konsultacje-niskoplatne/';

// ═════════════════════════════════════════════════════════════════════════════
// Scenariusz 1: Happy Path — pełna ścieżka rezerwacji
// ═════════════════════════════════════════════════════════════════════════════

test('Happy Path: użytkownik rezerwuje wizytę przez Wspólny Kalendarz', async ({ page }) => {
    const availDate = futureDateStr();

    // 1. Zamontuj mocki PRZED nawigacją — żadne prawdziwe żądanie Bookero nie wyjdzie
    await mockAjaxRoutes(page, availDate);

    // 2. Otwórz stronę z kalendarzem
    await page.goto(CALENDAR_PATH);

    // 3. Czekaj na wyrenderowanie siatki kalendarza
    await page.waitForSelector('.bk-sc__grid', { timeout: 15_000 });

    // 4. Kalendarz auto-wybiera pierwszy dostępny dzień i ładuje sloty.
    //    Czekamy na pojawienie się kart specjalistów (wynik auto-selekcji).
    const workerCard = page.locator('.bk-sc__slot-card').first();
    await expect(workerCard).toBeVisible({ timeout: 12_000 });

    // 5. Kliknij kartę specjalisty → otwiera formularz rezerwacji
    await workerCard.click();

    const bookingForm = page.locator('.bk-sc__booking-form');
    await expect(bookingForm).toBeVisible({ timeout: 5_000 });

    // 6. Wypełnij formularz mockowymi danymi testowymi
    await page.fill('[name=name]',      'Jan Kowalski E2E');
    await page.fill('[name=email]',     'jan.e2e@example.com');
    await page.fill('[name=phone]',     '+48 600 000 001');
    await page.fill('[name=ulica]',     'ul. Testowa');
    await page.fill('[name=nr_domu]',   '7B');
    await page.fill('[name=kod_poczt]', '00-001');
    await page.fill('[name=miasto]',    'Warszawa');

    // 7. Zaznacz obowiązkowe checkboxy (18+ oraz regulamin)
    await page.check('[name=agree_18]');
    await page.check('[name=agree_tp]');

    // 8. Wyślij formularz — bk_create_booking jest zmockowany (bez real API)
    await page.click('.bk-sc__booking-cta');

    // 9. ✅ Ekran potwierdzenia (payment_url = '' → showBookingConfirmed())
    await expect(page.locator('.bk-sc__booking-confirmed')).toBeVisible({ timeout: 8_000 });
    await expect(page.locator('.bk-sc__confirmed-title'))
        .toContainText('Rezerwacja potwierdzona');

    // 10. ✅ Brak komunikatów o błędzie
    await expect(page.locator('.bk-sc__form-error')).toBeHidden();
});

// ═════════════════════════════════════════════════════════════════════════════
// Scenariusz 2: Rate Limit Graceful Degradation
//   bk_verify_hour → { removed: [] }
//   Backend wykrył limit Bookero i zamiast blokować użytkownika,
//   zatwierdził wszystkich specjalistów na starych danych z bazy.
//   Oczekiwane zachowanie: formularz otwiera się bez błędów, żadna karta
//   specjalisty nie znika, użytkownik nie widzi komunikatów systemowych.
// ═════════════════════════════════════════════════════════════════════════════

test('Rate Limit Degradation: bk_verify_hour z removed:[] nie blokuje formularza', async ({ page }) => {
    const availDate = futureDateStr();

    // Mock: bk_verify_hour zawsze zwróci graceful degradation (removed: [])
    await mockAjaxRoutes(page, availDate);

    await page.goto(CALENDAR_PATH);
    await page.waitForSelector('.bk-sc__grid', { timeout: 15_000 });

    // Czekaj na auto-wybór dnia i załadowanie slotów
    const workerCard = page.locator('.bk-sc__slot-card').first();
    await expect(workerCard).toBeVisible({ timeout: 12_000 });

    // Poczekaj na zakończenie bk_verify_hour (debounce 250 ms + request RTT)
    // Używamy waitForTimeout zamiast polegać na żadnym wizualnym sygnale,
    // bo graceful degradation NIE modyfikuje DOM — to jest właśnie cel testu.
    await page.waitForTimeout(800);

    // ✅ Karta specjalisty NADAL widoczna — żaden ID nie był w removed[]
    await expect(workerCard).toBeVisible();

    // ✅ Brak kart oznaczonych jako usunięte (klasa --removed dodawana przez JS)
    await expect(page.locator('.bk-sc__slot-card--removed')).toHaveCount(0);

    // ✅ Brak ostrzeżenia "Termin zajęty" (wywoływanego gdy wszystkie karty znikają)
    await expect(page.locator('.bk-sc__info--warn')).toHaveCount(0);

    // Kliknij kartę — formularz powinien się otworzyć normalnie
    await workerCard.click();
    await expect(page.locator('.bk-sc__booking-inner')).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('.bk-sc__booking-form')).toBeVisible();

    // ✅ Użytkownik NIE widzi żadnych błędów systemowych
    await expect(page.locator('.bk-sc__form-error')).toBeHidden();
});
