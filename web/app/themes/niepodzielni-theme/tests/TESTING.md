# Testy — niepodzielni-theme

## Przegląd

Motyw posiada dwa rodzaje testów:

| Rodzaj | Narzędzie | Katalog | Uruchomienie |
|--------|-----------|---------|--------------|
| Jednostkowe (PHP) | Pest (PHPUnit) | `tests/Unit/` | `vendor/bin/pest` |
| End-to-End (JS) | Playwright | `tests/e2e/` | `npm run test:e2e` |

---

## Testy jednostkowe PHP (Pest)

### Wymagania

- PHP ≥ 8.2
- Composer (`vendor/` zainstalowany)

### Uruchomienie

```bash
# Z katalogu głównego projektu (Niepodzielni-dev/)
vendor/bin/pest tests/Unit
```

### Pokrycie

| Plik testu | Co testuje |
|------------|------------|
| `BookeroSyncServiceTest.php` | Logika synchronizacji terminów: propagacja `BookeroRateLimitException`, pochłanianie `BookeroApiException`, `SyncResult` contract, Circuit Breaker |

---

## Testy E2E Playwright

Testy E2E pokrywają przepływ rezerwacji w **Wspólnym Kalendarzu Bookero** (`/psychologowie/`).

Wszystkie żądania AJAX (`/wp-admin/admin-ajax.php`) są przechwytywane przez `page.route()` — **żadne prawdziwe rezerwacje nie są tworzone**, API Bookero nie jest wywoływane.

### Wymagania

- Node.js ≥ 20
- Lokalne środowisko WordPress dostępne pod `http://localhost:8000`
- Strona `/psychologowie/` z wyrenderowanym elementem `.bk-sc__grid`

---

### Krok 1 — Pierwsze uruchomienie: instalacja przeglądarek

Wykonaj **jednorazowo** po sklonowaniu repozytorium:

```bash
cd web/app/themes/niepodzielni-theme
npm install
npm run test:e2e:install
```

Pobierze ~300 MB binariów Chromium i Firefox do `~/.cache/ms-playwright/`. Kolejne uruchomienia tego kroku nie są konieczne, chyba że zaktualizujesz wersję `@playwright/test` w `package.json`.

---

### Krok 2 — Uruchom lokalne środowisko WordPress

Testy wymagają wyrenderowanego HTML z motywem (CSS, JS kalendarza):

```bash
docker-compose up -d
```

Sprawdź, czy `http://localhost:8000/psychologowie/` zwraca stronę z elementem `.bk-sc__grid`.

---

### Krok 3 — Zmienne środowiskowe (opcjonalne)

Domyślne wartości działają z lokalnym Dockerem na porcie 8000. Jeśli Twoje środowisko używa innych adresów, ustaw zmienne przed uruchomieniem testów.

**Linux / macOS / Git Bash:**
```bash
export PLAYWRIGHT_BASE_URL=http://localhost:8000
export PLAYWRIGHT_CALENDAR_URL=/psychologowie/
```

**Windows PowerShell:**
```powershell
$env:PLAYWRIGHT_BASE_URL = "http://localhost:8000"
$env:PLAYWRIGHT_CALENDAR_URL = "/psychologowie/"
```

---

### Uruchamianie testów

```bash
# Wszystkie testy, obie przeglądarki (headless)
npm run test:e2e

# Tryb UI — podgląd na żywo, hot-reload po zmianie pliku
npm run test:e2e:ui

# Tylko Chromium
npx playwright test --project=chromium

# Tylko Firefox
npx playwright test --project=firefox

# Jeden konkretny test (po fragmencie nazwy)
npx playwright test --grep "Happy Path"
npx playwright test --grep "Rate Limit"

# Tryb krokowy z DevTools (debug)
npx playwright test --debug

# Verbose — pełne logi każdego kroku
npx playwright test --reporter=list
```

---

### Scenariusze testowe

#### Scenariusz 1 — Happy Path (`booking.spec.js`)

Pełna ścieżka rezerwacji:

1. Strona `/psychologowie/` ładuje się, siatka kalendarza jest widoczna
2. Kalendarz auto-wybiera pierwszy dostępny dzień i ładuje karty specjalistów
3. Kliknięcie karty otwiera formularz rezerwacji
4. Formularz jest wypełniany danymi testowymi
5. Po wysłaniu formularza pojawia się ekran potwierdzenia `.bk-sc__booking-confirmed`
6. Brak komunikatów o błędach `.bk-sc__form-error`

#### Scenariusz 2 — Rate Limit Graceful Degradation (`booking.spec.js`)

Weryfikacja, że `bk_verify_hour` z odpowiedzią `{ removed: [] }` **nie blokuje** użytkownika:

1. Po załadowaniu kart specjalistów i upływie debounce (800 ms)
2. Żadna karta nie znika (`bk-sc__slot-card--removed` = 0)
3. Brak ostrzeżenia "Termin zajęty" (`.bk-sc__info--warn` = 0)
4. Formularz otwiera się normalnie po kliknięciu karty

---

### Artefakty po nieudanym teście

Playwright zapisuje automatycznie:

| Artefakt | Lokalizacja |
|----------|-------------|
| Zrzut ekranu | `test-results/<test-name>/test-failed-*.png` |
| Nagranie wideo | `test-results/<test-name>/video.webm` |
| Trace | `test-results/<test-name>/trace.zip` |

Przeglądanie trace w przeglądarce:

```bash
npx playwright show-trace test-results/<test-name>/trace.zip
```

---

### Konfiguracja (`playwright.config.js`)

| Opcja | Wartość |
|-------|---------|
| `baseURL` | `$PLAYWRIGHT_BASE_URL` lub `http://localhost:8000` |
| `testDir` | `./tests/e2e` |
| `fullyParallel` | `false` (testy sekwencyjne — stan formularza) |
| `workers` | `1` |
| `retries` | `2` w CI, `0` lokalnie |
| Przeglądarki | Chromium, Firefox |
| `trace` | przy pierwszym ponowieniu |
| `screenshot` | tylko przy błędzie |
| `video` | zachowane przy błędzie |

---

### Uruchamianie w CI (GitHub Actions)

Testy są uruchamiane automatycznie przez workflow `.github/workflows/ci.yml`.

Zmienne środowiskowe w CI:

```yaml
env:
  PLAYWRIGHT_BASE_URL: http://localhost:8000
  PLAYWRIGHT_CALENDAR_URL: /psychologowie/
```

W CI reporter jest ustawiony na `github` (adnotacje bezpośrednio w PR).
