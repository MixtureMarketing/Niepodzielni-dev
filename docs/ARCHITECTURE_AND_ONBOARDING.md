# Architektura Systemu i Onboarding Deweloperski

> Dokument dla nowych członków zespołu. Opisuje architekturę, sposób uruchomienia projektu lokalnie
> oraz kluczowe mechanizmy integracji z Bookero i silnikiem dopasowania psychologów (Matchmaker).

---

## Spis treści

1. [Stos technologiczny](#1-stos-technologiczny)
2. [Uruchomienie lokalne](#2-uruchomienie-lokalne)
3. [Architektura kontenera Docker](#3-architektura-kontenera-docker)
4. [Struktura repozytorium](#4-struktura-repozytorium)
5. [Architektura integracji Bookero](#5-architektura-integracji-bookero)
6. [Frontend — Matchmaker i SharedCalendar](#6-frontend--matchmaker-i-sharedcalendar)
7. [Proponowany Backlog Techniczny](#7-proponowany-backlog-techniczny)

---

## 1. Stos technologiczny

| Warstwa         | Technologia                                    |
|-----------------|------------------------------------------------|
| CMS             | WordPress 6.x w wersji Bedrock (nie standardowy wp/)  |
| Motyw           | Sage 11 (Laravel Blade + Vite build)           |
| PHP             | 8.4 (obraz `php:8.4-apache`)                   |
| Baza danych     | MySQL 8.0                                      |
| Cache obiektów  | Redis 7-alpine (allkeys-lru, 128 MB)           |
| Serwer WWW      | Apache 2 z mod_rewrite, mod_headers, mod_expires |
| Zarządzanie WP  | WP-CLI (binarka kopiowana z `wordpress:cli`)   |
| Cron            | Systemowy demon `cron` wewnątrz kontenera (NIE WP-Cron) |
| Build front     | Vite (w katalogu motywu)                       |
| Zależności PHP  | Composer 2 (uruchamiany w entrypoint)          |

---

## 2. Uruchomienie lokalne

### Wymagania wstępne

- Docker Desktop (Windows/macOS) lub Docker Engine + Compose v2 (Linux)
- Git
- Node.js 20+ i npm (tylko do buildu frontendu)

### Krok po kroku

```bash
# 1. Sklonuj repozytorium
git clone <repo-url> Niepodzielni-dev
cd Niepodzielni-dev

# 2. Skopiuj plik środowiskowy i uzupełnij klucze Bookero
cp .env.example .env
#    Uzupełnij: NP_BOOKERO_CAL_ID_PELNY, NP_BOOKERO_CAL_ID_NISKO
#    Uzupełnij: DB_NAME, DB_USER, DB_PASSWORD, DB_HOST, WP_HOME

# 3. Zbuduj i uruchom kontenery
docker compose up --build -d

# 4. Poczekaj na healthcheck bazy (~30s), potem sprawdź logi
docker compose logs -f app

# 5. (opcjonalnie) Zbuduj frontend w trybie watch
cd web/app/themes/niepodzielni-theme
npm install && npm run dev
```

Po uruchomieniu serwisy są dostępne pod:

| Serwis       | URL                        |
|--------------|---------------------------|
| WordPress    | http://localhost:8000      |
| phpMyAdmin   | http://localhost:8080      |
| Redis        | localhost:6379             |

> **Baza danych**: przy pierwszym `docker compose up` MySQL automatycznie importuje
> `database-template.sql` (dump produkcyjny/stagingowy), a następnie `docker/db-init/02-update-urls.sql`
> nadpisuje wszystkie URLe na `http://localhost:8000`. Nie musisz nic uruchamiać ręcznie.

### Wymuszone przebudowanie

```bash
# Usuń kontenery I wolumeny (czysta instalacja Composera, świeże wp/)
docker compose down -v
docker compose up --build -d
```

---

## 3. Architektura kontenera Docker

### Proces startowy (`docker/entrypoint.sh`)

Kontener uruchamia **dwa procesy równolegle** i nie zatrzymuje się dopóki Apache żyje:

```
entrypoint.sh
├── composer install (root + motyw)
├── mkdir web/app/uploads, web/app/cache/...
├── cp object-cache.php           ← Redis drop-in
├── php wp-load → flush_rules()   ← permalink rewrite
├── service cron start            ← demon systemowy (Bookero)
└── exec apache2-foreground       ← proces główny kontenera
```

### Wolumeny Dockera — strategia

Kod źródłowy montowany jest jako **bind mount** (`.:/var/www/html`), co pozwala
edytować pliki bez przebudowywania obrazu. Jednak katalogi z artefaktami Composera
oraz WordPress core są montowane jako **named volumes** (native Linux I/O):

| Named volume    | Ścieżka w kontenerze                             | Powód                              |
|-----------------|--------------------------------------------------|-------------------------------------|
| `vendor_root`   | `/var/www/html/vendor`                           | Composer: brak narzutu bind-mount   |
| `vendor_theme`  | `.../niepodzielni-theme/vendor`                  | j.w.                                |
| `webwp`         | `/var/www/html/web/wp`                           | WordPress core — tylko odczyt       |
| `plugins`       | `/var/www/html/web/app/plugins`                  | Pluginy instalowane przez Composera |
| `acorn_cache`   | `/var/www/html/web/app/cache`                    | Blade cache — szybki I/O            |

> **Ważne**: zmiany w `web/wp/` na hoście nie mają efektu — WordPress core żyje
> wyłącznie w wolumenie `webwp`. Do aktualizacji WP używaj `composer update` wewnątrz kontenera.

### WP-Cron vs. systemowy demon cron

Projekt **wyłącza WP-Cron** (`DISABLE_WP_CRON=true` w `docker-compose.yml`).

| Cecha              | WP-Cron (domyślny)                         | Systemowy cron (ten projekt)             |
|--------------------|---------------------------------------------|------------------------------------------|
| Trigger            | Każde żądanie HTTP do WordPressa            | Niezależny demon, co minutę              |
| Problem            | Brak ruchu = brak wywołań crona             | Zawsze odpala, nawet bez żądań HTTP      |
| Konfiguracja       | `wp-config.php`                             | `/etc/cron.d/bookero`                    |
| Polecenie          | `wp-cron.php`                               | `php /usr/local/bin/wp cron event run --due-now` |

Plik cronta (`docker/cron/bookero`) definiuje pełną zmienną `$PATH`, bo środowisko demona cron
jest puste i nie znajdzie binarki `php` bez jawnej ścieżki:

```crontab
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * root /usr/local/bin/php /usr/local/bin/wp cron event run \
          --due-now --path=/var/www/html/web/wp --url=http://localhost:8000 --allow-root \
          >> /proc/1/fd/1 2>/proc/1/fd/2
```

Logi (`/proc/1/fd/1`) trafiają do standardowego wyjścia procesu głównego (Apache),
dzięki czemu `docker compose logs app` pokazuje logi obu procesów jednocześnie.

### Redis — cache obiektów

Redis działa jako **WordPress Object Cache drop-in** (`object-cache.php` kopiowany z pluginu
`redis-cache` przy starcie kontenera). Wpływa na:

- Wszystkie `get_transient` / `set_transient` — w środowisku z Redis transient nie trafia do MySQL
- `wp_cache_get` / `wp_cache_set` — cache zapytań, opcji, postmeta

Konfiguracja: `maxmemory 128mb`, polityka eksmisji `allkeys-lru`, **bez persystencji** (`--save ""`).
Po restarcie kontenera Redis jest czysty — cron odbuduje transiencie przy pierwszym przebiegu.

> Przy debugowaniu "dlaczego stare dane wciąż się wyświetlają" — sprawdź Redis zanim sprawdzisz MySQL:
> ```bash
> docker exec niepodzielni-redis redis-cli FLUSHALL
> ```

---

## 4. Struktura repozytorium

```
Niepodzielni-dev/
├── web/
│   ├── wp/                          # WordPress core (managed volume, nie edytuj)
│   ├── app/
│   │   ├── mu-plugins/
│   │   │   ├── niepodzielni-core.php          # Plugin loader + stałe globalne
│   │   │   └── niepodzielni-core/
│   │   │       ├── api/                       # Integracja Bookero + AJAX
│   │   │       │   ├── 8-bookero-api.php      # Klient HTTP, cache godzin
│   │   │       │   ├── 9-bookero-sync.php     # Harmonogram crona + sync_all()
│   │   │       │   ├── 10-ajax-handlers.php   # Wszystkie wp_ajax_* handlery
│   │   │       │   ├── 11-bookero-shortcodes.php
│   │   │       │   ├── 12-bookero-enqueue.php
│   │   │       │   ├── 13-bookero-worker-sync.php  # Cron: 3 workery/minutę
│   │   │       │   ├── 14-bk-shared-calendar.php   # Shortcode [bookero_wspolny_kalendarz]
│   │   │       │   └── 15-matchmaker-shortcode.php # Shortcode [np_matchmaker]
│   │   │       ├── cpt/                       # Custom Post Types i metaboxy
│   │   │       ├── admin/                     # Dashboard, kolumny, ustawienia
│   │   │       └── misc/                      # Helpers, sprzątanie
│   │   ├── themes/niepodzielni-theme/
│   │   │   ├── resources/
│   │   │   │   ├── js/
│   │   │   │   │   ├── matchmaker.js          # Silnik dopasowania psychologa
│   │   │   │   │   ├── bk-shared-calendar.js  # Wspólny kalendarz 3-kolumnowy
│   │   │   │   │   └── bookero-init.js        # Interceptor fetch/XHR dla Bookero
│   │   │   │   └── css/
│   │   │   └── app/
│   │   │       └── setup.php                  # Rejestracja skryptów Vite
│   │   └── plugins/                           # (managed volume)
│   └── index.php
├── config/
│   └── application.php              # Stałe WP, w tym NP_BOOKERO_CAL_ID_*
├── docker/
│   ├── cron/bookero                 # Crontab dla Bookero
│   ├── apache/000-default.conf
│   ├── php/php.ini
│   └── entrypoint.sh
├── docker-compose.yml
├── Dockerfile
└── database-template.sql            # Dump bazy (nie commituj danych osobowych!)
```

---

## 5. Architektura integracji Bookero

### Dwa konta Bookero

Fundacja Niepodzielni obsługuje dwa odrębne konta w systemie Bookero:

| Konto        | Stała PHP                  | Konfiguracja                             |
|--------------|---------------------------|------------------------------------------|
| Pełnopłatny  | `NP_BOOKERO_CAL_ID_PELNY`  | `5tu8AC22Akna`, service_id: 58370        |
| Niskopłatny  | `NP_BOOKERO_CAL_ID_NISKO`  | `hxRnUexTsSvc`, service_id: 50604 (online) |

Stałe są ładowane z `.env` przez `config/application.php`. Fallback — opcje WP
(`np_bookero_cal_pelny`, `np_bookero_cal_nisko`) ustawiane w panelu admina.

Funkcja `np_bookero_cal_id_for(string $typ)` w [misc/1-helpers.php](../web/app/mu-plugins/niepodzielni-core/misc/1-helpers.php)
zwraca właściwy hash na podstawie `$typ` (`'pelnoplatny'`, `'nisko'` i ich aliasów).

### Używane endpointy API Bookero

Wszystkie zapytania trafiają do `https://plugin.bookero.pl/plugin-api/v2/`.
Nie wymagają Bearer tokena — to ten sam publiczny endpoint co widget JS na stronie.

| Endpoint      | Metoda | Przeznaczenie                                    | Cache              |
|---------------|--------|--------------------------------------------------|--------------------|
| `init`        | GET    | Konfiguracja konta (service_id, payment_id)      | Transient 24h      |
| `getMonth`    | GET    | Dostępne dni dla workera w danym miesiącu        | Transient 5 min    |
| `getMonthDay` | GET    | Godziny dla workera w konkretnym dniu            | Transient 5 min + DB |
| `add`         | POST   | Stworzenie rezerwacji                            | Brak               |

> **Ważne**: `getMonth` zwraca pole `valid_day` (>0 = prawdziwy wolny slot) oraz `open`
> (=1 = pracownik w grafiku, ale może nie mieć wolnych miejsc). Nasza logika zlicza
> **wyłącznie** dni z `valid_day > 0`. Ignorowanie `open` zapobiega wyświetlaniu fałszywych terminów.

### Cykl synchronizacji — schemat przepływu danych

```
┌─────────────────────────────────────────────────────────────────┐
│  System cron (co 60s)  →  np_bookero_worker_sync()             │
│                                                                  │
│  offset = get_option('terminy_cron_offset', 0)                 │
│  Pobierz 3 psychologów od offsetu (get_posts)                  │
│  Zapisz offset + 3 PRZED przetwarzaniem (zabezp. przed timeout) │
│                                                                  │
│  Dla każdego psychologa (usleep 300ms między):                  │
│    ├─ np_bookero_get_availability(worker_id, 'pelnoplatny')     │
│    │   └─ getMonth × 3 miesiące → nearest + all_dates[]        │
│    │   ├─ update_post_meta: najblizszy_termin_pelnoplatny       │
│    │   ├─ update_post_meta: bookero_slots_pelno (JSON dates[])  │
│    │   └─ pre-warm: getMonthDay dla nearest → bookero_hours_pelno│
│    └─ (identycznie dla 'nisko')                                 │
│                                                                  │
│  Koniec listy → offset = 0 → nowy cykl                         │
│  128 psychologów / 3 = ~43 minuty na pełny cykl                │
└─────────────────────────────────────────────────────────────────┘
```

### Cache godzin — dwupoziomowy

Godziny dla konkretnego dnia cachowane są w **dwóch miejscach**:

```
┌──────────────────────────────────────────────────────────────┐
│ Żądanie: "jakie godziny ma psycholog X w dniu Y?"            │
│                                                              │
│ 1. np_bookero_get_cached_hours(post_id, typ, date)           │
│    → postmeta 'bookero_hours_pelno' (JSON map: date → [h])   │
│    → null  = brak w cache → idź do kroku 2                   │
│    → []    = zsynchronizowano, pusty dzień → zwróć []        │
│    → [h..] = hit → zwróć natychmiast                         │
│                                                              │
│ 2. np_bookero_get_month_day(worker_id, typ, date)            │
│    → sprawdź transient (5 min)                               │
│    → GET /getMonthDay (max 10s timeout)                      │
│    → np_bookero_cache_hours(post_id, typ, date, hours)       │
│       └─ zapisuje do postmeta, czyści daty < dziś            │
└──────────────────────────────────────────────────────────────┘
```

Klucze postmeta: `bookero_hours_pelno` / `bookero_hours_nisko`.
Format JSON: `{"2026-04-22": ["10:00", "11:00"], "2026-04-24": ["17:00"]}`.

### Kluczowe postmeta psychologa

| Klucz postmeta                      | Zawartość                                   | Ustawiany przez           |
|-------------------------------------|---------------------------------------------|---------------------------|
| `bookero_id_pelny`                  | Worker ID w koncie pelno (np. `"30604"`)    | Ręcznie w adminie         |
| `bookero_id_niski`                  | Worker ID w koncie nisko                    | Ręcznie w adminie         |
| `najblizszy_termin_pelnoplatny`     | Sformatowana data "15 maja 2026"            | Cron / bookero-init.js    |
| `najblizszy_termin_niskoplatny`     | j.w. dla nisko                              | Cron / bookero-init.js    |
| `bookero_slots_pelno`               | JSON: `["2026-04-22","2026-04-24",...]`     | Cron                      |
| `bookero_slots_nisko`               | j.w. dla nisko                              | Cron                      |
| `bookero_hours_pelno`               | JSON map: data → lista godzin               | Cron (pre-warm) + AJAX    |
| `bookero_hours_nisko`               | j.w. dla nisko                              | Cron (pre-warm) + AJAX    |
| `np_termin_updated_at`              | Unix timestamp ostatniej synchronizacji     | Cron                      |

### AJAX handlery (`10-ajax-handlers.php`)

Wszystkie handlery są zabezpieczone przez `check_ajax_referer('np_bookero_nonce', 'nonce')`.

| Akcja WordPress                 | Funkcja PHP                        | Kto wywołuje                     |
|---------------------------------|------------------------------------|----------------------------------|
| `bk_ingest_month`               | `np_ajax_bk_ingest_month()`        | bookero-init.js (interceptor)    |
| `np_get_terminy`                | `np_ajax_get_terminy()`            | Shortcody na stronie psychologa  |
| `np_refresh_terminy`            | `np_ajax_refresh_terminy()`        | Panel admina (wymaga cap)        |
| `np_refresh_termin_single`      | `np_ajax_refresh_termin_single()`  | Kolumna admina przy psychologu   |
| `bk_get_shared_month`           | `np_ajax_bk_get_shared_month()`    | bk-shared-calendar.js            |
| `bk_get_date_slots`             | `np_ajax_bk_get_date_slots()`      | bk-shared-calendar.js (klik dnia)|
| `bk_verify_hour`                | `np_ajax_bk_verify_hour()`         | bk-shared-calendar.js (modal)    |
| `bk_create_booking`             | `np_ajax_bk_create_booking()`      | bk-shared-calendar.js (formularz)|

### Real-time ingest — bookero-init.js

Plik [bookero-init.js](../web/app/themes/niepodzielni-theme/resources/js/bookero-init.js)
wstrzykuje się globalnie w każdą stronę i monkey-patchuje `window.fetch` oraz `XMLHttpRequest`.

Gdy oryginalny widget Bookero (załadowany na stronie psychologa) wywołuje `getMonth`,
interceptor przechwytuje odpowiedź i w tle wysyła `POST wp-admin/admin-ajax.php?action=bk_ingest_month`
z datą najbliższego terminu. PHP zapisuje ją do `najblizszy_termin_*` — bez dodatkowego
requesta do Bookero API, zero obciążenia crona.

```
Użytkownik otwiera stronę psychologa
    ↓
Widget Bookero JS wywołuje getMonth
    ↓ (przechwycone przez interceptor)
extractNearestDate(response)
    ↓
POST /admin-ajax.php action=bk_ingest_month
    ↓
update_post_meta(post_id, 'najblizszy_termin_pelnoplatny', $label)
```

### Tworzenie rezerwacji — endpoint `/add`

Booking flow po wyborze godziny przez użytkownika:

```
JS: _submitBooking(worker, date, hour, formData)
    ↓
POST /admin-ajax.php action=bk_create_booking
    ↓
PHP: bk_create_booking()
    ├─ Walidacja pól
    ├─ Budowa plugin_comment (JSON z parametrami formularza)
    │   IDs parametrów: 15663 (powód), 16483 (oświadcz. 18+),
    │   16488-16492 (adres), 20215 (zaimki)
    └─ POST https://plugin.bookero.pl/plugin-api/v2/add
        ↓
    Odpowiedź: { result:1, data: { payment_url, inquiries[0].id } }
        ↓
    payment_url → redirect przeglądarki do bramki płatności
    brak payment_url → potwierdzenie bez płatności (np. nisko free)
```

### Panel admina — Dashboard

`admin/5-admin-dashboard.php` dodaje widget do głównego ekranu WP z:
- Licznikami psychologów / zsynchronizowanych / z terminem / jeszcze nie sprawdzonych
- Statusem połączenia obu kont Bookero (zielona/czerwona kropka)
- Paskiem postępu crona z odliczaniem w czasie rzeczywistym (JS `setInterval`)
- Logiem ostatnich błędów API (24h, max 30 wpisów, przycisk "wyczyść")

---

## 6. Frontend — Matchmaker i SharedCalendar

### Matchmaker (`matchmaker.js`)

Plik [matchmaker.js](../web/app/themes/niepodzielni-theme/resources/js/matchmaker.js)
implementuje klasę `NpMatchmaker`. Mount point: `<div id="np-matchmaker">`.

Dane wejściowe są dostarczane przez PHP jako `window.NP_MATCHMAKER` (inline `<script>`
generowany przez shortcode `[np_matchmaker]`). Zawierają pre-renderowaną tablicę
wszystkich psychologów z ich metadanymi:
- `obszary[]`, `nurty[]`, `spec[]`, `jezyki[]`, `wizyta[]`
- `sort_date` — data najbliższego terminu w formacie `Ymd` (do sortowania)
- `has_pelno`, `has_nisko` — flagi dostępności cenowej

#### Fuzzy Scoring Engine — 3 fazy

**Faza 1: Hard filters** — eliminuje psychologów niespełniających twardych kryteriów.
Żaden wynik poniżej tych warunków nie trafi do dalszych faz:

| Filtr          | Warunek wykluczenia                               |
|----------------|---------------------------------------------------|
| `who=couple`   | Psycholog nie ma specjalizacji `terapia-par`      |
| `visitType`    | Rodzaj wizyty (Online/Stacjonarnie) nie pasuje    |
| `pricing`      | Brak wybranego cennika (`has_pelno` / `has_nisko`)|
| `lang`         | Psycholog nie mówi wybranym językiem              |
| `onlyAvailable`| Brak `sort_date` (nie ma terminu w kalendarzu)    |

**Faza 2: Scoring** — każdy psycholog w puli dostaje wynik `matchScore`:

```
Wagi (obiekt W w kodzie):
┌─────────────────────────────────────────────────────────────────┐
│ AREA_EXACT   = 1.0   (obszar dokładnie pasuje)                  │
│ AREA_CLUSTER = 0.5   (obszar w tej samej "rodzinie" co wybrany) │
│ PRIMARY_MULT = 2.0   (mnożnik dla obszaru oznaczonego jako główny)│
│ PRECISION_W  = 0.8   (bonus za specjalizację: exactMatches / totalAreas) │
│ NURT_EXACT   = 1.0   (nurt dokładnie pasuje)                    │
│ NURT_FAMILY  = 0.5   (pokrewny nurt)                            │
│ AVAIL_7      = 0.5   (termin w ciągu 7 dni)                     │
│ AVAIL_14     = 0.25  (termin w ciągu 14 dni)                    │
│ AVAIL_30     = 0.1   (termin w ciągu 30 dni)                    │
│ SPEC_BONUS   = 0.5   (max bonus za jedną specjalizację)         │
└─────────────────────────────────────────────────────────────────┘
```

Klastry obszarów (`MM_CLUSTERS`) i rodziny nurtów (`MM_FAMILIES`) są dostarczane przez
PHP — pozwalają na fuzzy matching między pokrewnymi kategoriami bez identycznego dopasowania.

**Faza 3: Sort** — malejąco po `matchScore`, remisy rozstrzygane przez `sort_date`
(wcześniejszy termin = wyżej), w przypadku braku terminów — losowo (`Math.random()`).

Wyniki: domyślnie 5 (`MAX_RESULTS`), przycisk "pokaż więcej" do 10 (`MAX_FULL`).

**Relaxed suggestions** — gdy wynik 0 psychologów, system automatycznie proponuje
poluzowanie każdego z hard filtrów z informacją ile psychologów odblokuje.

#### Persistencja stanu

Stan formularza persystuje między przeładowaniami w **dwóch miejscach jednocześnie**:

```
Stan (this.state)
    ├─ sessionStorage['np_mm_state']  (JSON całego stanu)
    │   └─ Przywracany przy każdym renderze
    └─ URL query string (?areas=depresja&pricing=nisko&...)
        └─ Przywracany gdy URL zawiera ?areas= lub ?pricing= lub ?who=
           Priorytet: URL > sessionStorage > DEFAULT_STATE
```

URL jest aktualizowany przez `history.replaceState` — bez przeładowania strony —
gdy użytkownik przechodzi do etapu wyników (krok 4). Pozwala na udostępnianie linku
z pre-wypełnionymi filtrami.

### Shared Calendar (`bk-shared-calendar.js`)

Klasa `BkSharedCalendar` implementuje trójkolumnowy interfejs rezerwacji.
Mount point: `<div class="bk-sc__layout" data-typ="nisko|pelno">`.

```
Kolumna 1: Kalendarz miesięczny
    ↓ klik dnia (data z bookero_slots_*)
Kolumna 2: Godziny (AJAX: bk_get_date_slots)
    ↓ klik godziny → weryfikacja (AJAX: bk_verify_hour)
Kolumna 3: Specjaliści dostępni o tej godzinie + formularz rezerwacji
    ↓ submit (AJAX: bk_create_booking)
Redirect → bramka płatności Bookero (lub potwierdzenie gdy brak payment_url)
```

**Wskaźniki dostępności** na kafelkach kalendarza:
- Zielony: ≥6 psychologów dostępnych w danym dniu
- Żółty: 2–5 psychologów
- Czerwony: 1 psycholog

**Weryfikacja godzin** (`bk_verify_hour`): przed wyświetleniem modalu rezerwacji
JS wysyła zapytanie ze wszystkimi worker ID dostępnymi o danej godzinie. PHP dla
każdego workera usuwa transient i pobiera świeże dane z `getMonthDay`, po czym
zwraca listę ID, dla których godzina jest już niedostępna. JS usuwa te karty z UI.

---

## 7. Proponowany Backlog Techniczny

---

### Ticket 1: Refaktor `matchmaker.js` — dekompozycja monolitycznej klasy na moduły ES6

**Co jest problemem**

Plik [matchmaker.js](../web/app/themes/niepodzielni-theme/resources/js/matchmaker.js)
zawiera ~600 LOC w jednej klasie `NpMatchmaker`, która łączy w sobie:
- logikę scoringową (silnik),
- zarządzanie stanem (store),
- całość renderowania HTML (generacja stringów przez template literals),
- obsługę eventów DOM,
- persystencję (sessionStorage + URL).

Skutki: każda zmiana w szablonie HTML wymaga zrozumienia całego silnika scoringowego.
Dodanie nowego filtra zmusza do modyfikacji `_set()`, `_runMatchmakerWith()`,
`_saveToUrl()`, `_restoreFromUrl()`, `_countWith()` i `_tplStep2()` jednocześnie.
Brak testowalności — nie da się przetestować logiki scoringowej bez DOM.

**Dlaczego należy to zmienić**

Każda nowa funkcjonalność matchmakera zwiększa rozmiar pliku proporcjonalnie do liczby
miejsc, które trzeba dotknąć. Aktualny `Math.random()` jako fallback sortowania sugeruje,
że wyniki bywają nieprzewidywalne — niemożliwe do zweryfikowania bez izolacji logiki.

**Jak to technicznie wykonać**

Podziel plik na moduły ES6 (Vite przetworzy je automatycznie):

```
resources/js/matchmaker/
├── data.js          # Stałe: MM_PSY, MM_AREAS, MM_CLUSTERS, MM_FAMILIES, W (wagi)
├── state.js         # Klasa Store: get/set state, save/restore session+URL
├── filters.js       # hardFilter(psy[], state) → psy[] (czysta funkcja)
├── scorer.js        # score(psycholog, state) → { matchScore, matchedAreas, ... }
├── sort.js          # sort(scored[]) → sorted[]
├── renderer.js      # Klasa: step→HTML, _tplProgress, _tplStep1, _tplResults
├── events.js        # Podpinanie event listenerów
└── index.js         # Orkiestrator — montuje wszystko, inicjalizuje
```

`scorer.js` i `filters.js` będą czystymi funkcjami bez zależności od DOM — można
je przetestować jednostkowo (np. Vitest) bez przeglądarki. Dodanie nowego filtru
będzie wymagało zmiany tylko `filters.js` i `state.js`.

---

### Ticket 2: Wzorzec Repository dla operacji bazodanowych Bookero

**Co jest problemem**

Logika dostępu do danych Bookero jest rozproszona między czterema plikami PHP:
`8-bookero-api.php`, `9-bookero-sync.php`, `10-ajax-handlers.php`,
`13-bookero-worker-sync.php`. W `10-ajax-handlers.php` handler
`np_ajax_bk_get_date_slots()` i `np_ajax_bk_get_shared_month()` zawierają
identyczny blok budowania listy psychologów z meta query (~30 LOC powielone).
Handler `np_ajax_bk_verify_hour()` wykonuje raw SQL bezpośrednio (`$wpdb->get_results`)
z dynamicznie składaną klauzulą `IN`, co jest potencjalnym wektorem injection
(mimo sanitizacji przez `$wpdb->prepare`, konstrukcja `implode(',', ...)` jest nieintuicyjna).

**Dlaczego należy to zmienić**

Powielanie kodu prowadzi do sytuacji, w której poprawka w jednym miejscu nie trafia
do drugiego (przykład: fallback `najblizszy_termin_*` jest implementowany dwukrotnie
z identyczną logiką). Brak warstwy abstrakcji uniemożliwia podmianę backendu cache
(np. Redis zamiast postmeta) bez zmiany kodu biznesowego.

**Jak to technicznie wykonać**

Utwórz klasę `BookeroPsychologRepository` w nowym pliku
`api/BookeroPsychologRepository.php`:

```php
class BookeroPsychologRepository {
    // Zwraca psychologów z danym typem konta (z worker ID)
    public function findWithAccount(string $typ): array;

    // Zwraca psychologów mających $date w swoich slotach
    public function findByAvailableDate(string $typ, string $date): array;

    // Zwraca worker_id → post_id map dla listy worker ID (zastępuje raw SQL)
    public function mapWorkerIdToPostId(array $workerIds, string $typ): array;

    // Aktualizuje slots + nearest term + timestamp
    public function saveAvailability(int $postId, string $typ, array $avail): void;
}
```

Handlery AJAX stają się cienkie — tylko walidacja, wywołanie repozytorium, `wp_send_json_*`.
Metoda `mapWorkerIdToPostId()` zastąpi raw SQL użyciem `WP_Query` z `meta_query`,
eliminując potrzebę ręcznego escapowania.

---

### Ticket 3: Odporność na błędy HTTP w `np_bookero_get_terminy()` — retry + circuit breaker

**Co jest problemem**

Funkcja `np_bookero_get_terminy()` w [8-bookero-api.php](../web/app/mu-plugins/niepodzielni-core/api/8-bookero-api.php)
przy błędzie (`is_wp_error` lub HTTP != 200) zapisuje **pusty wynik** do transientu
na 2 minuty i zwraca `[]`. Powoduje to:

1. **Fałszywe "brak terminów"** na stronie psychologa przez 2 minuty po jednorazowym
   błędzie sieci lub chwilowym przeciążeniu Bookero (HTTP 429 jest logowany regularnie).
2. **Brak retry** — przy timeoucie (28 cURL) kolejne żądanie w ciągu 2 minut dostanie
   odpowiedź z cache zamiast ponowić próbę po kilku sekundach.
3. **Brak circuit breakera** — gdy Bookero zwraca 429 dla wszystkich requestów,
   cron dalej generuje 18 żądań co minutę, pogłębiając problem rate-limitingu.

Logi Bookero regularnie notują timeouty (cURL 28) i HTTP 429, co potwierdza, że
system nie radzi sobie z chwilowymi problemami API.

**Dlaczego należy to zmienić**

Przy 128 psychologach i 43-minutowym cyklu każdy restart transientów (np. po
`redis flush`) generuje burst ~760 requestów w krótkim czasie. Bez circuit breakera
każdy taki event kończy się falą 429 i 2-minutowymi pustymi transientami dla części
psychologów, tymczasowo ukrywając dostępne terminy.

**Jak to technicznie wykonać**

**Krok 1: Zróżnicuj TTL transientu** w zależności od kodu błędu:

```php
if ( is_wp_error( $response ) ) {
    // Timeout / błąd sieci — krótki TTL, szybki retry
    set_transient( $cache_key, [], 30 ); // 30 sekund
    return [];
}
if ( $code === 429 ) {
    // Rate limit — dłuższy TTL + log ostrzeżenia
    set_transient( $cache_key, [], 5 * MINUTE_IN_SECONDS );
    return [];
}
if ( $code !== 200 ) {
    set_transient( $cache_key, [], 2 * MINUTE_IN_SECONDS );
    return [];
}
```

**Krok 2: Circuit breaker** — opcja WP jako licznik błędów:

```php
// Przed wysłaniem requestu:
$fail_count = (int) get_option( 'np_bk_fail_' . $typ, 0 );
if ( $fail_count >= 5 ) {
    // 5+ błędów z rzędu — poczekaj 5 minut zanim spróbujesz
    // (sprawdź timestamp ostatniego błędu)
    return [];
}
// Po błędzie: increment; po sukcesie: reset do 0
```

**Krok 3: Backoff w cronie** — gdy cron wykryje serię timeoutów dla konkretnego
workera, pomijaj go przez kolejne N cykli zamiast próbować co minutę:

Dodaj `bookero_skip_until_<worker_id>` (Unix timestamp) do postmeta — cron sprawdza
przed wywołaniem API i pomija jeśli timestamp jest w przyszłości.

---

### Ticket 4: Eliminacja `get_posts(-1)` w handlerach AJAX — stronicowanie i lazy load

**Co jest problemem**

Handlery `np_ajax_bk_get_shared_month()` i `np_ajax_bk_get_date_slots()` wywołują:

```php
$psycholodzy = get_posts([
    'post_type'      => 'psycholog',
    'posts_per_page' => -1,    // ← pobiera WSZYSTKICH
    'post_status'    => 'publish',
    ...
]);
```

Przy 128+ psychologach każde kliknięcie dnia w kalendarzu ładuje wszystkie obiekty
`WP_Post` do pamięci, wykonuje N `get_post_meta()` dla każdego, a następnie filtruje
po `bookero_slots_*`. Dla ~130 psychologów × 3 pola meta = ~390 dodatkowych zapytań
SQL na jedno kliknięcie użytkownika.

Transient `np_bk_month_*` (5 min) częściowo łagodzi problem dla widoku miesięcznego,
ale `bk_get_date_slots` nie jest cachowany — każde kliknięcie dnia to nowe zapytanie.

**Dlaczego należy to zmienić**

Przy dalszym wzroście liczby psychologów powyżej 200 czas odpowiedzi AJAX na kliknięcie
dnia przekroczy 1 sekundę nawet na szybkim serwerze. Na współdzielonym hostingu
produkcyjnym może to powodować limity `max_execution_time`.

**Jak to technicznie wykonać**

**Krok 1: Zastąp `get_posts(-1)` bezpośrednim zapytaniem SQL** w `findByAvailableDate()`
(patrz Ticket 2):

```sql
-- Pobierz IDs postów, które mają $date w JSON bookero_slots_*
SELECT post_id
FROM wp_postmeta
WHERE meta_key = 'bookero_slots_pelno'
  AND JSON_CONTAINS(meta_value, '"2026-04-22"')
```

MySQL 8 obsługuje `JSON_CONTAINS` — eliminuje ładowanie wszystkich postów do PHP.

**Krok 2: Cache per-date** dla `bk_get_date_slots`:

```php
$cache_key = 'np_bk_slots_' . $typ . '_' . $date;
$cached = get_transient($cache_key);
if ($cached !== false) { wp_send_json_success($cached); }
// ... build data ...
set_transient($cache_key, $data, 3 * MINUTE_IN_SECONDS);
```

**Krok 3: Invalidacja cache** przy każdym `np_bookero_cache_hours()` — gdy DB aktualizuje
godziny dla danej daty, usuń `np_bk_slots_*` dla tej daty. Gwarantuje aktualność
przy zachowaniu korzyści cachowania.
