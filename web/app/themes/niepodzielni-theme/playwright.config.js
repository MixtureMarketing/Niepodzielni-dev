import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E — konfiguracja
 *
 * Zmienne środowiskowe:
 *   PLAYWRIGHT_BASE_URL       — baza URL (domyślnie: http://localhost:8000)
 *   PLAYWRIGHT_CALENDAR_URL   — ścieżka strony z Wspólnym Kalendarzem
 *                               (domyślnie: /psychologowie/)
 *
 * Uruchamianie:
 *   npm run test:e2e           — wszystkie testy, headless
 *   npm run test:e2e:ui        — tryb interaktywny UI Playwright
 *   npx playwright test --debug  — tryb krokowy
 */
export default defineConfig({
    testDir: './tests/e2e',

    // Testy Bookero dotykają stanu formularza — bezpieczniej sekwencyjnie
    fullyParallel: false,
    workers: 1,

    // W CI ponów 2 razy; lokalnie nie powtarzaj nieudanych
    retries: process.env.CI ? 2 : 0,

    reporter: process.env.CI ? 'github' : 'list',

    use: {
        baseURL:     process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000',
        trace:       'on-first-retry',
        screenshot:  'only-on-failure',
        video:       'retain-on-failure',
        actionTimeout:      10_000,
        navigationTimeout:  20_000,
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'firefox',
            use: { ...devices['Desktop Firefox'] },
        },
    ],
});
