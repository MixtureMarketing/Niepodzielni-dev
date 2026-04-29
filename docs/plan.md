# Plan Poprawek i Optymalizacji - Niepodzielni-dev

Lista konkretnych miejsc wymagających poprawki, ujednolicenia lub optymalizacji.

## 🛠️ Infrastruktura i Konfiguracja
- [ ] **Secrets w Dockerze**: W `docker-compose.yml` zahardkodowano `MYSQL_ROOT_PASSWORD` (l. 70) oraz `MYSQL_PASSWORD` (l. 73). Przenieść do `.env`.
- [ ] **Token Cloudflare Tunnel**: W `docker-compose.yml:94` token jest zahardkodowany. Przenieść do `.env`.
- [ ] **Wyciek Kluczy i PII w SQL**: `database-template.sql` zawiera wrażliwe dane:
    - Klucze Google Service Account (l. 302383).
    - Klucze API Bookero (l. 789751).
    - Hasła serwera pocztowego (l. 16).
    - Lokalne ścieżki dewelopera `D:\kod\...` (l. 39).
    - **Akcja**: Zanonimizować plik SQL, usunąć klucze i zastąpić je placeholderami.
- [ ] **Autoload Bloat**: W `wp_options` znajduje się wiele rekordów z `autoload='yes'` o dużym rozmiarze (WP Rocket, SEOPress). Przejrzeć i wyłączyć autoload dla danych, które nie są potrzebne przy każdym żądaniu.
- [ ] **WP_DEBUG_LOG**: W `config/application.php:123` włączyć logowanie do pliku dla dev.
- [ ] **Niespójność Domeny**: `kontakt@niepodzielni.com` (główny email w header/footer i `contact-form.php:32`) vs `kontakt@niepodzielni.pl` (nadal obecny w `setup.php:74` oraz template'ach `psy-listing`).

## 🧠 Core Logic (MU-Plugins)

### 🧩 Duplikacja Logiki i Danych
- [ ] **Migracja Kluczy Meta**: Po zmianie klucza `cena_-_rodzaj` na `cena_rodzaj` (21-carbon-fields.php:218) należy wykonać migrację starych danych w DB, aby uniknąć pustych pól na listingu.
- [ ] **Logika Walidacji Typu**: Niespójne sprawdzanie typów (pelno/nisko):
    - `api/10-ajax-handlers.php:24` (`np_bookero_is_valid_typ`)
    - `src/Bookero/PsychologistRepository.php:507` (`isNisko`)
    - Przenieść do wspólnej klasy narzędziowej (np. `BookeroTyp`).
- [ ] **Nazwy Miesięcy** (KRYTYCZNA DUPLIKACJA):
    - PHP: `src/Bookero/SharedCalendarService.php:23`, `app/shortcodes/shortcodes-ui.php:215`, `misc/1-helpers.php:165`
    - JS: `resources/js/bk-shared-calendar.js:16`
    - AI Worker: `workers/ai-agent/src/routes/chat.ts:375`
    - Testy: `tests/Pest.php:126` i `204`, `tests/e2e/booking.spec.js:23`
- [ ] **Zahardkodowane stawki**: `55 zł` i `145 zł` rozproszone w:
    - `shortcodes-profile.php:111`
    - `api/15-matchmaker-shortcode.php:69-70`
    - `src/Bookero/PsychologistRepository.php:382`
    - `resources/js/matchmaker/Templates.js:126-127`

### ⚡ Optymalizacja i Stabilność
- [ ] **Zahardkodowane ID pól Bookero**: `api/10-ajax-handlers.php:336-355` (mapowanie ID pól formularza np. `15663`, `16483`).
- [ ] **User-Agent**: `admin/5-admin-dashboard.php:57` — usunąć hardkodowany UA Chrome 124.
- [ ] **Wydajność Dashboardu**: `admin/5-admin-dashboard.php` — przeliczać statystyki synchronizacji rzadziej (cache).
- [ ] **Wydajność Bot-API**: Endpoint `/bot-availability` (api/19-ai-endpoints.php:73) — wprowadzić transient cache.
- [ ] **Matchmaker Data Cache**: Implementacja cache'owania po stronie serwera dla danych psychologów używanych przez Matchmakera, aby uniknąć przeliczania wszystkiego w JS przy każdym ładowaniu.
- [ ] **Wydajność Renderowania JS**: W `psy-listing-atomic.js:132` karty są budowane przez `innerHTML`. Rozważyć przejście na `document.createElement` lub `template literals` z bezpiecznym parsowaniem.
- [ ] **Stabilność Interceptora API**: W `bookero-init.js` dodać walidację struktury odpowiedzi Bookero przed próbą jej parsowania.

## 🎨 Motyw (Sage 11)

### 🖼️ Blade i Prezentacja
- [ ] **Zahardkodowane Stawki w Blade**: W `template-psy-listing-nisko.blade.php:15` i `29` cena "55 zł" jest wpisana na sztywno. Powinna pochodzić z globalnych ustawień.
- [ ] **Zahardkodowane URL-e Obrazów**: W `hero.blade.php:25` użyto pełnego adresu `https://niepodzielni.com/...`. Zmienić na ścieżkę relatywną lub pole CF.
- [ ] **SEO & JS-only Rendering**: Główny listing psychologów (`#psy-listing-target`) jest renderowany wyłącznie przez JS. Rozważyć pre-rendering pierwszych kilku kart w PHP dla lepszego SEO i LCP.
- [ ] **A11y w Filtrach**: Sprawdzić i uzupełnić brakujące `label` oraz `aria-attributes` w komponentach `filter-toggle` i `filter-dropdown`.
- [ ] **Redundancja CSS**: Usunąć zduplikowane definicje `cubic-bezier` w `variables.css`.

### 🌐 Frontend & JavaScript
- [ ] **Stabilność Matchmakera**: Zmienić `Math.random()` w `ScoringEngine.js:154` na stały fallback (np. sortowanie po ID).
- [ ] **Obsługa błędów AJAX**: W `bookero-init.js:77` (funkcja `postToWP`) dodać logowanie błędów do konsoli (tryb dev/debug).
- [ ] **Kolizja Interceptora**: Upewnić się, że nadpisanie `window.fetch` w `bookero-init.js` nie psuje działania natywnych narzędzi WordPressa (np. Heartbeat API).
- [ ] **Zahardkodowane URL-e Bookero**:
    - `resources/js/bookero-init.js:192` (CDN)
    - `resources/js/matchmaker.js:347` (CDN)
    - `api/11-bookero-shortcodes.php:70`
    - `api/12-bookero-enqueue.php:23`

## 🤖 AI Worker & Endpoints
- [ ] **Inwalidacja Promptu**: W `workers/ai-agent/src/routes/chat.ts` filtr pożegnań/frustracji i filtr kryzysowy — rozważyć KV Store.
- [ ] **Ujednolicenie Auth**: Token `NP_AI_BOT_TOKEN` (api/19-ai-endpoints.php:40) — zarządzać wyłącznie przez `.env` / `wp-config.php`.
- [ ] **Monitoring Context Window**: System prompt rośnie — monitorować limity tokenów GPT-4o-mini.
- [ ] **Walidacja Mediów**: Obsługa błędów PHP `upload_max_filesize`.

---
*Plan uaktualniony o wszystkie znaleziska z etapów: Infra, Logic, AI, DB.*
