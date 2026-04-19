# Refactoring Baseline — Niepodzielni-dev

> Wygenerowany: 2026-04-19  
> Narzędzie: Claude Code (Principal Auditor mode)  
> Zakres: analiza statyczna bez modyfikacji kodu źródłowego

---

## 1. Baseline Metrics

| Katalog | Typ | Pliki | LOC |
|---|---|---|---|
| `web/app/mu-plugins/niepodzielni-core/` | PHP | 21 | **4 452** |
| `web/app/themes/niepodzielni-theme/app/` | PHP | 20 | **1 842** |
| `web/app/themes/niepodzielni-theme/resources/js/` | JS (łącznie) | 20 | **4 159** |
| `web/app/themes/niepodzielni-theme/resources/js/` | JS (bez testów) | 16 | **3 514** |
| **SUMA produkcyjna** | PHP + JS | **57** | **9 808** |

### Podział per-plik (top 10 wg LOC)

| Plik | LOC | Rola |
|---|---|---|
| `api/10-ajax-handlers.php` | 615 | AJAX: 8 handlerów + singleton factory |
| `resources/js/bk-shared-calendar.js` | 726 | Shared Calendar frontend |
| `resources/js/matchmaker.js` | 503 | Matchmaker orkiestrator |
| `resources/js/matchmaker/Templates.js` | 427 | Szablony HTML matchmakera |
| `src/Bookero/PsychologistRepository.php` | 495 | Repozytorium DB/cache |
| `cpt/21-carbon-fields.php` | 411 | Definicje pól CF |
| `resources/js/bookero-init.js` | 353 | Fetch/XHR interceptor |
| `misc/1-helpers.php` | 357 | Funkcje pomocnicze |
| `app/Services/EventsListingService.php` | 342 | Serwis listingów wydarzeń |
| `app/setup.php` | 323 | Rejestracja assetów WP |

---

## 2. Executive Summary

Codebase jest **ogólnie zdrowy**. Architektura OOP (Repository, Service, Client, DTO) jest prawidłowo zastosowana w warstwie Bookero. Testy pokrywają kluczową logikę biznesową. Brak długów technicznych o krytycznym priorytecie.

**Mocne strony:**
- Czyste rozdzielenie HTTP (`BookeroApiClient`) / DB (`PsychologistRepository`) / logika (`BookeroSyncService`)
- DI przez konstruktor w całej warstwie OOP — testowalność bez WP
- Eliminacja N+1 przez `update_post_meta_cache` + Redis Object Cache w `getAllWorkersWithMeta()`
- Circuit breaker z `BookeroRateLimitException` propagowaną przez całą warstwę
- View Composers z DI (Acorn container) — czyste oddzielenie danych od widoków

**Obszary do poprawy (bez ryzyka regresji):**

| Kategoria | Skala | Priorytet |
|---|---|---|
| Duplikacja cache boilerplate w `EventsListingService` | ~60 LOC | Wysoki |
| Ręczne pobieranie AccountConfig zamiast `$sync->getAccountConfig()` | ~40 LOC | Wysoki |
| Zduplikowany wzorzec logowania błędów w `BookeroSyncService` | ~20 LOC | Średni |
| Dwie strukturalnie identyczne funkcje w `1-helpers.php` | ~20 LOC | Średni |
| Martwy kod `sync-example.php` w katalogu produkcyjnym | 158 LOC | Niski (usunąć) |

Szacunkowe oszczędności po refaktorze: **~340 LOC (3,5% całości)** przy zerowej zmianie funkcjonalności.

---

## 3. Top 5 Refactoring Targets

---

### Target 1 — `EventsListingService.php`: 5× identyczny boilerplate L0+L1 cache

**Lokalizacja:** [app/Services/EventsListingService.php](../web/app/themes/niepodzielni-theme/app/Services/EventsListingService.php), linie 40–50, 107–117, 167–177, 225–235, 280–290

**Problem:** Każda z 5 metod (`getWorkshopsData`, `getWydarzeniaData`, `getAktualnosciData`, `getPsychoedukacjaData`, `getPsychoedukacjaTags`) zawiera identyczny blok 12-liniowy:

```php
$cached = wp_cache_get($cache_key, self::CACHE_GROUP);
if (is_array($cached)) {
    return $cached;
}
$cached = get_transient($transient_key);
if (is_array($cached)) {
    wp_cache_set($cache_key, $cached, self::CACHE_GROUP, HOUR_IN_SECONDS);
    return $cached;
}
// ... build $data ...
set_transient($transient_key, $data, HOUR_IN_SECONDS);
wp_cache_set($cache_key, $data, self::CACHE_GROUP, HOUR_IN_SECONDS);
```

**Rozwiązanie:** Prywatna metoda `withCache()`:

```php
/**
 * @param  callable(): array<mixed> $builder
 * @return array<mixed>
 */
private function withCache(string $cacheKey, string $transientKey, callable $builder): array
{
    $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
    if (is_array($cached)) {
        return $cached;
    }

    $cached = get_transient($transientKey);
    if (is_array($cached)) {
        wp_cache_set($cacheKey, $cached, self::CACHE_GROUP, HOUR_IN_SECONDS);
        return $cached;
    }

    $data = $builder();

    set_transient($transientKey, $data, HOUR_IN_SECONDS);
    wp_cache_set($cacheKey, $data, self::CACHE_GROUP, HOUR_IN_SECONDS);

    return $data;
}
```

Każda metoda staje się:

```php
public function getWorkshopsData(): array
{
    return $this->withCache('workshops', 'np_workshops_listing', function () {
        // ... tylko WP_Query + pętla ...
    });
}
```

**Szacunkowy zysk:** eliminacja ~60 LOC duplikacji; `EventsListingService` spada z 342 → ~270 LOC.

---

### Target 2 — `10-ajax-handlers.php`: ręczne pobieranie AccountConfig 3×

**Lokalizacja:** [api/10-ajax-handlers.php](../web/app/mu-plugins/niepodzielni-core/api/10-ajax-handlers.php)
- `np_ajax_get_terminy()`: linie 139–150
- `np_ajax_bk_verify_hour()`: linie 411–422
- `np_ajax_bk_create_booking()`: linie 509–521

**Problem:** Wszystkie trzy handlery implementują lokalnie logikę "pobierz config z transientu lub z API":

```php
$cfg_arr = $repo->getAccountConfigTransient($typ);
if ($cfg_arr !== false) {
    $service_id = $cfg_arr['service_id'];
} else {
    try {
        $cfg = $client->getAccountConfig($cal_hash);
        $repo->setAccountConfigTransient($typ, $cfg);
        $service_id = $cfg->serviceId;
    } catch (\Niepodzielni\Bookero\BookeroApiException $e) {
        np_bookero_log_error('...', [...]);
    }
}
```

Ta logika **już istnieje** w `BookeroSyncService::getAccountConfig()` (linie 139–155 w `BookeroSyncService.php`). Handlery powinny używać serwisu.

**Rozwiązanie:** W każdym handlerze zainicjalizować `$sync = new BookeroSyncService($client, $repo)` i zastąpić blok try/catch jedną linią:

```php
// ZAMIAST 12 linii:
$account_cfg = $sync->getAccountConfig($typ); // już cachuje w transient
$service_id  = $account_cfg->serviceId;
```

Dla `np_ajax_bk_create_booking()` (linia 512) factory serwisu jest już w `np_bookero_shared_calendar_service()`, ale nie jest używana przez ten handler.

**Szacunkowy zysk:** ~36 LOC; uproszczenie logiki w 3 handlerach.

---

### Target 3 — `1-helpers.php`: dwie strukturalnie identyczne funkcje

**Lokalizacja:** [misc/1-helpers.php](../web/app/mu-plugins/niepodzielni-core/misc/1-helpers.php), linie 19–50

**Problem:** `np_bookero_api_key_for()` (linie 19–30) i `np_bookero_cal_id_for()` (linie 39–50) są identyczne pod względem struktury — różnią się tylko nazwami stałych i opcji WP:

```php
// np_bookero_api_key_for — linie 21–29:
$is_nisko = in_array($typ, ['nisko','niskoplatny','niskoplatne'], true);
if ($is_nisko) {
    return defined('NP_BOOKERO_API_KEY_NISKO') && NP_BOOKERO_API_KEY_NISKO
        ? NP_BOOKERO_API_KEY_NISKO
        : get_option('np_bookero_api_key_nisko', '');
}
return defined('NP_BOOKERO_API_KEY_PELNY') && NP_BOOKERO_API_KEY_PELNY
    ? NP_BOOKERO_API_KEY_PELNY
    : get_option('np_bookero_api_key_pelny', '');

// np_bookero_cal_id_for — IDENTYCZNA STRUKTURA, inne stałe/opcje
```

**Rozwiązanie:** Prywatna helper-funkcja (lub closure) wyciągająca wspólny wzorzec:

```php
function np_bookero_config_for(
    string $typ,
    string $const_nisko,
    string $option_nisko,
    string $const_pelny,
    string $option_pelny,
): string {
    $is_nisko = in_array($typ, ['nisko', 'niskoplatny', 'niskoplatne'], true);
    [$const, $option] = $is_nisko
        ? [$const_nisko, $option_nisko]
        : [$const_pelny, $option_pelny];
    return defined($const) && constant($const) ? constant($const) : get_option($option, '');
}

function np_bookero_api_key_for(string $typ): string {
    return np_bookero_config_for($typ, 'NP_BOOKERO_API_KEY_NISKO', 'np_bookero_api_key_nisko', 'NP_BOOKERO_API_KEY_PELNY', 'np_bookero_api_key_pelny');
}

function np_bookero_cal_id_for(string $typ): string {
    return np_bookero_config_for($typ, 'NP_BOOKERO_CAL_ID_NISKO', 'np_bookero_cal_nisko', 'NP_BOOKERO_CAL_ID_PELNY', 'np_bookero_cal_pelny');
}
```

**Szacunkowy zysk:** ~18 LOC; DRY obu funkcji w jednym miejscu.

---

### Target 4 — `BookeroSyncService.php`: zduplikowany wzorzec logowania błędów

**Lokalizacja:** [src/Bookero/BookeroSyncService.php](../web/app/mu-plugins/niepodzielni-core/src/Bookero/BookeroSyncService.php)
- `getMonthSlots()`: linie 183–193
- `prewarmHours()`: linie 225–231

**Problem:** Oba metody używają identycznego wzorca dla `BookeroApiException` — dwa logi (raz przez `np_bookero_log_error()`, raz przez `error_log()` ze stack trace):

```php
// W getMonthSlots():
np_bookero_log_error('getMonth', "worker={$workerId} typ={$typ}: " . $e->getMessage());
error_log('[Bookero] ApiException getMonth worker=' . $workerId . ' typ=' . $typ . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

// W prewarmHours() — ten sam wzorzec, inne kontekstowe zmienne:
np_bookero_log_error('getMonthDay', "worker={$workerId} date={$nearestDate}: " . $e->getMessage());
error_log('[Bookero] ApiException prewarm worker=' . $workerId . ' date=' . $nearestDate . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
```

**Rozwiązanie:** Prywatna metoda z rozszerzoną signaturą `np_bookero_log_error()` lub:

```php
private function logApiError(string $context, string $details, BookeroApiException $e): void
{
    np_bookero_log_error($context, "{$details}: " . $e->getMessage());
    error_log("[Bookero] ApiException {$context} {$details}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
}
```

Wywołania:
```php
// getMonthSlots:
$this->logApiError('getMonth', "worker={$workerId} typ={$typ}", $e);

// prewarmHours:
$this->logApiError('getMonthDay', "worker={$workerId} date={$nearestDate}", $e);
```

Alternatywnie: rozszerzyć `np_bookero_log_error()` o opcjonalny parametr `Throwable $e = null` z warunkowym `error_log($e->getTraceAsString())`.

**Szacunkowy zysk:** ~14 LOC; spójność logowania w jednym miejscu.

---

### Target 5 — `src/Bookero/sync-example.php`: martwy kod w katalogu produkcyjnym

**Lokalizacja:** [src/Bookero/sync-example.php](../web/app/mu-plugins/niepodzielni-core/src/Bookero/sync-example.php), 158 LOC

**Problem:** Plik zawiera przykładowy/demonstracyjny kod synchronizacji (`$sync->syncSingleWorker()`, `$repo->getAllWorkersWithMeta()`, przykładowe mocki) bez żadnych produkcyjnych wywołań. Jest w katalogu `src/Bookero/` obok klas produkcyjnych, przez co:

- Wprowadza w błąd nowych deweloperów (wygląda jak produkcja)
- PHPStan może analizować przykładowe klasy anonimowe
- Marnuje 158 LOC na dokumentację która powinna być w `docs/`

**Rozwiązanie:** Przenieść do `docs/bookero-sync-example.php` lub usunąć. Jeśli ma wartość dokumentacyjną — zamienić na kod Markdown w `ARCHITECTURE_AND_ONBOARDING.md` (przykłady są już opisane w dokumentacji).

**Szacunkowy zysk:** eliminacja 158 LOC z katalogu produkcyjnego.

---

## 4. Proponowane Zmiany Architektoniczne

### 4.1 Statyczna metoda `isNisko()` jako wspólna utility

**Problem:** Logika `in_array($typ, ['nisko','niskoplatny','niskoplatne'], true)` pojawia się w **4 miejscach**:
1. `PsychologistRepository::isNisko()` — private, linia 484
2. `np_bookero_is_nisko_typ()` — `10-ajax-handlers.php`, linia 30
3. `np_bookero_api_key_for()` — `1-helpers.php`, linia 21 (inline)
4. `np_bookero_cal_id_for()` — `1-helpers.php`, linia 41 (inline)

**Propozycja:** Przenieść do statycznej metody w `PsychologistRepository` lub dedykowanej klasie `BookeroTyp`:

```php
final class BookeroTyp
{
    public static function isNisko(string $typ): bool
    {
        return in_array($typ, ['nisko', 'niskoplatny', 'niskoplatne'], true);
    }

    public static function isValid(string $typ): bool
    {
        return in_array($typ, ['pelno','pelnoplatny','pelnoplatne','nisko','niskoplatny','niskoplatne'], true);
    }
}
```

Wszystkie inne miejsca delegują do `BookeroTyp::isNisko($typ)`. Zmiana listy aliasów w jednym miejscu zamiast 4.

---

### 4.2 Ujednolicenie wzorca cache w `PsychologistRepository`

**Obserwacja:** `getSharedMonthTransient()`, `getMonthTransient()`, `getAccountConfigTransient()` mają identyczny wzorzec odczytu:

```php
$cached = get_transient($key);
return is_array($cached) ? $cached : false;
```

Można wyekstrahować wewnętrzny prywatny helper:

```php
private function getArrayTransient(string $key): array|false
{
    $cached = get_transient($key);
    return is_array($cached) ? $cached : false;
}
```

Niski priorytet — wzorzec jest krótki, ale trzy miejsca kodu byłyby spójniejsze.

---

### 4.3 `bk-shared-calendar.js` — separacja odpowiedzialności

**Obserwacja:** `bk-shared-calendar.js` (726 LOC) jest jedną klasą łączącą:
- Zarządzanie stanem (step, selectedDate, selectedHour)
- Renderowanie HTML (metody `_renderCalendar()`, `_renderHours()`, `_renderWorkers()` — inline template strings)
- Wywołania AJAX (`_fetchMonth()`, `_fetchSlots()`, `_verifyHour()`)
- Logikę formularza i walidację
- Logikę rezerwacji (`_submitBooking()`)

Analogicznie do przeprowadzonego refaktoru `matchmaker.js → ScoringEngine.js + Templates.js + State.js`, można podzielić:

```
resources/js/bk-shared-calendar/
├── Calendar.js       — renderowanie kalendarza
├── Slots.js          — renderowanie godzin i psychologów
├── BookingForm.js    — formularz rezerwacji
├── api.js            — wrappers fetch (fetchMonth, fetchSlots, verifyHour, createBooking)
└── index.js          — orkiestrator (obecne BkSharedCalendar)
```

**Priorytet:** niski (JS działa, refaktor nie eliminuje LOC, tylko poprawia separację).

---

### 4.4 `setup.php` — mapowanie skryptów przez tablicę zamiast if/elseif

**Lokalizacja:** [app/setup.php](../web/app/themes/niepodzielni-theme/app/setup.php), linie 78–200

Logika warunkowego ładowania skryptów (Bookero, SharedCalendar, Matchmaker itd.) to kaskada `if (is_singular(...) || is_page_template(...))` z `wp_enqueue_script()` wewnątrz każdej gałęzi.

Alternatywa — deklaratywna tablica konfiguracji:

```php
$script_map = [
    'bookero-init'     => fn() => is_singular(['psycholog','warsztaty']) || $has_bookero_shortcode,
    'bk-shared-cal'   => fn() => $has_bookero_shortcode,
    'matchmaker'       => fn() => is_page_template('template-matchmaker.blade.php'),
    'psy-listing'      => fn() => is_page_template(['template-psy-listing-pelno.blade.php','template-psy-listing-nisko.blade.php']),
];

foreach ($script_map as $handle => $condition) {
    if ($condition()) {
        Vite::enqueueScript("resources/js/{$handle}.js");
    }
}
```

Niski priorytet — aktualny kod jest czytelny mimo objętości.

---

## 5. Podsumowanie potencjalnych oszczędności

| Target | Obecne LOC w bloku | Szacunkowy zysk |
|---|---|---|
| `EventsListingService`: `withCache()` helper | 60 LOC × 5 | −60 LOC |
| `10-ajax-handlers.php`: AccountConfig przez serwis | 12 LOC × 3 | −36 LOC |
| `sync-example.php`: usunięcie martwego kodu | 158 LOC | −158 LOC |
| `1-helpers.php`: wspólna helper-funkcja | 20 LOC | −18 LOC |
| `BookeroSyncService`: `logApiError()` helper | 8 LOC × 2 | −14 LOC |
| **Łącznie** | | **−286 LOC (−2,9%)** |

Oszczędności są konserwatywne — nie uwzględniają drobniejszych uproszczeń. Żadna zmiana nie modyfikuje interfejsów publicznych ani zachowania w runtime.

---

*Raport wygenerowany automatycznie — nie modyfikowano żadnych plików źródłowych.*
