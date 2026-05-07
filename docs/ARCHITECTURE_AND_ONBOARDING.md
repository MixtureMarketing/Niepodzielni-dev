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
7. [Jakość kodu — testy i analiza statyczna](#7-jakość-kodu--testy-i-analiza-statyczna)
8. [Analytics — Cloudflare Zaraz](#8-analytics--cloudflare-zaraz)
9. [Proponowany Backlog Techniczny](#9-proponowany-backlog-techniczny)

---

## 1. Stos technologiczny

| Warstwa         | Technologia                                    |
|-----------------|------------------------------------------------|
| CMS             | WordPress 6.x w wersji Bedrock (nie standardowy wp/)  |
| Motyw           | Sage 11 (Laravel Blade + Vite build)           |
| PHP             | 8.4 (obraz `php:8.4-fpm`)                      |
| Baza danych     | MySQL 8.0                                      |
| Cache obiektów  | Redis 7-alpine (allkeys-lru, 128 MB)           |
| Serwer WWW      | nginx 1.26 (reverse proxy) + PHP-FPM 8.4       |
| FastCGI cache   | nginx fastcgi_cache — BYPASS dla admin/POST, MISS→HIT dla gości |
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
docker compose logs -f nginx php

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

### Serwisy Docker Compose

Projekt używa **dwóch oddzielnych serwisów** zamiast jednego monolitycznego kontenera:

| Serwis  | Obraz                  | Port          | Rola                                                  |
|---------|------------------------|---------------|-------------------------------------------------------|
| `nginx` | nginx:1.26-alpine      | 8000:80       | Serwer HTTP: statyczne pliki, FastCGI cache, rewrite  |
| `php`   | (budowany z Dockerfile)| 9000 (wewnętrz)| PHP-FPM: wykonywanie PHP, cron Bookero                |

nginx przekazuje żądania `.php` do `php:9000` przez FastCGI. Serwis `php` nie jest
dostępny bezpośrednio z hosta.

**FastCGI cache** (`nginx_cache` volume): nginx buforuje odpowiedzi PHP dla anonimowych
użytkowników. Cache jest pomijany (`$skip_cache=1`) dla POST, query string, wp-admin,
wp-login oraz zalogowanych użytkowników (cookie `wordpress_logged_in`).
Header `X-FastCGI-Cache: HIT/MISS/BYPASS` widoczny w DevTools.

**Lazy DNS** (`resolver 127.0.0.11`): nginx używa zmiennej `$php_upstream` (nie literału
`php:9000`) — resolwuje nazwę przy każdym requeście, nie przy starcie. Zapobiega błędowi
"host not found" gdy kontener `php` jeszcze się nie uruchomił.

**Ścieżka Bedrock vs. wp-content**: Vite bake'uje absolutne ścieżki `/wp-content/themes/…`
w CSS (fonty, obrazki). Bedrock używa `/app/` zamiast `/wp-content/`. nginx przepisuje
lokalnie: `^/wp-content/(.*)$ /app/$1 last` — identycznie z Trellis na produkcji.

### Proces startowy (`docker/entrypoint.sh`)

Kontener `php` uruchamia **dwa procesy równolegle** i nie zatrzymuje się dopóki PHP-FPM żyje:

```
entrypoint.sh
├── composer install (root + motyw)
├── mkdir web/app/uploads, web/app/cache/...
├── cp object-cache.php           ← Redis drop-in
├── php wp-load → flush_rules()   ← permalink rewrite
├── service cron start            ← demon systemowy (Bookero)
└── exec php-fpm                  ← proces główny kontenera
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

Logi (`/proc/1/fd/1`) trafiają do standardowego wyjścia procesu głównego (PHP-FPM),
dzięki czemu `docker compose logs php` pokazuje logi obu procesów jednocześnie.

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
│   ├── nginx/default.conf           # nginx: vhost, FastCGI cache, rewrite wp-content→app
│   ├── php-fpm/www.conf             # PHP-FPM pool (pm=dynamic, max_children=10)
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

### Cykl synchronizacji — OOP z Circuit Breaker i Smart Polling

Cron uruchamia się co 60 sekund. Każdy przebieg przetwarza **5 psychologów**
(`BOOKERO_SYNC_PER_RUN`) wybranych przez mechanizm Smart Polling.

```
┌─────────────────────────────────────────────────────────────────┐
│  System cron (co 60s)  →  np_bookero_worker_sync_oop()         │
│                                                                  │
│  ① Circuit Breaker check                                        │
│     get_transient('bookero_api_lockout') → jeśli true: STOP    │
│                                                                  │
│  ② Smart Polling — priorytet absolutny (nigdy nie synced)      │
│     get_posts(meta_query: np_termin_updated_at NOT EXISTS)      │
│     → max 5 psychologów bez timestampu (pierwsze w kolejce)    │
│                                                                  │
│  ③ Smart Polling — uzupełnij pozostałe sloty                   │
│     get_posts(orderby: np_termin_updated_at ASC)                │
│     → najdłużej nieodświeżani jako pierwsi                     │
│                                                                  │
│  ④ Pętla synchronizacji (usleep 300ms między psychologami)     │
│     BookeroSyncService::syncSingleWorker(postId)                │
│       ├─ getAvailability(worker, typ) × 2 konta                 │
│       │   └─ getMonthSlots × 3 miesiące → nearest + dates[]    │
│       ├─ saveNearestDate / clearNearestDate                     │
│       ├─ saveAvailableDates                                     │
│       ├─ prewarmHours (getMonthDay dla daty[0])                 │
│       └─ touchSyncTimestamp(postId)                             │
│              ↑ powoduje "opuszczenie" kolejki (timestamp rośnie)│
│                                                                  │
│     BookeroRateLimitException (HTTP 429 / timeout):            │
│       set_transient('bookero_api_lockout', 1, 15min)           │
│       STOP — kolejne crony wstrzymane na 15 minut              │
│                                                                  │
│  ⑤ System samobalansujący                                      │
│     Każdy sync → touchSyncTimestamp → worker spada na koniec   │
│     kolejki → brak potrzeby ręcznego zarządzania offsetem      │
│     ~200 psychologów / 5 per run × 1min = ~40min pełny cykl   │
└─────────────────────────────────────────────────────────────────┘
```

#### Circuit Breaker — szczegóły

| Zdarzenie                     | Akcja                                     | TTL     |
|-------------------------------|-------------------------------------------|---------|
| HTTP 429 Too Many Requests    | `set_transient(BOOKERO_LOCKOUT_KEY, 1)`  | 15 min  |
| cURL timeout / error 28       | j.w.                                      | 15 min  |
| Inny błąd HTTP (503, 502...)  | Per-worker backoff transient              | 2 min   |
| Lockout aktywny               | Cron kończy się natychmiast, loguje błąd | —       |

`BookeroRateLimitException` jest osobną klasą dziedziczącą po `BookeroApiException`.
Rzucana przez `BookeroApiClient::parseResponse()`, re-throwowana przez
`BookeroSyncService::getMonthSlots()` i `prewarmHours()`, łapana przez cron.

#### Warstwa OOP (`src/Bookero/`)

```
BookeroApiClient         — transport HTTP (GET/POST), parsowanie odpowiedzi
BookeroApiException      — błąd komunikacji z API
BookeroRateLimitException — podklasa dla HTTP 429 i timeout (circuit breaker)
AccountConfig            — DTO: serviceId, serviceName, paymentId (readonly)
SyncResult               — DTO: postId, hasPelny, hasNisko, nearestPelny, nearestNisko
WorkerRecord             — DTO: dane workera z postmeta (read-only)
PsychologistRepository   — warstwa DB/cache (postmeta + transienty)
BookeroSyncService       — logika biznesowa synchronizacji (DI: client + repo)
SharedCalendarService    — logika kalendarza współdzielonego (DI: client + repo)
```

Wszystkie klasy obsługują wstrzykiwanie zależności przez konstruktor — pozwala
na testy jednostkowe bez uruchamiania WordPress (anonymous class mocks).

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

## 7. Jakość kodu — testy i analiza statyczna

### Testy PHP (Pest 4)

Testy jednostkowe uruchamiamy przez:

```bash
vendor/bin/pest
```

Katalog testów: `tests/Unit/`. Stubs funkcji WP w `tests/Pest.php`.

#### Pokryte scenariusze

| Plik testowy                    | Co testuje                                                       |
|---------------------------------|------------------------------------------------------------------|
| `BookeroSyncServiceTest.php`    | Propagacja `BookeroRateLimitException` przez `syncSingleWorker` |
|                                 | Pochłanianie `BookeroApiException` (503) → pusty `SyncResult`  |
|                                 | Semantyka `SyncResult::hasSynced()` i `hasAnyAvailability()`   |
| `SharedCalendarServiceTest.php` | Negative cache: `worker-A` error nie blokuje `worker-B`         |
|                                 | Izolacja per-datę i per-typ konta                               |
|                                 | `setMonthTransientBackoff` → pusta tablica (nie `false`)        |
|                                 | `clearMonthTransients` czyści N miesięcy do przodu              |

#### Strategia mockowania

Brak Mockery w projekcie — mocking przez **anonimowe klasy** rozszerzające produkcyjne klasy:

```php
$client = new class extends BookeroApiClient {
    public function getMonth(string $calHash, string $workerId, int $serviceId, int $plusMonths): array {
        throw new BookeroRateLimitException('getMonth', 'HTTP 429 Too Many Requests');
    }
};
```

Stan WP (transienty, postmeta) zarządzany przez in-memory `$GLOBALS['_wp_transients']`
i `$GLOBALS['_wp_postmeta']` — resetowane w `beforeEach` dla każdego testu.

### Testy JavaScript (Vitest)

Testy silnika matchmakera uruchamiamy z katalogu motywu:

```bash
cd web/app/themes/niepodzielni-theme
npm run test   # lub: npx vitest run
```

Plik testowy: `resources/js/__tests__/ScoringEngine.test.js`

#### Pokryte scenariusze

| Zestaw testów            | Co testuje                                                     |
|--------------------------|----------------------------------------------------------------|
| `PRIMARY_MULT`           | Obszar główny podwaja wynik (2.0× vs 1.0×)                    |
| `AREA_CLUSTER`           | Fuzzy matching — `ptsd` + `trauma` w tej samej grupie → 0.5 pkt |
| Hard filter `visitType`  | `Stacjonarnie` wykluczone gdy `visitType=Online`               |
| `PRECISION_W`            | Specjalista (1/1 obszarów) > generalista (1/5 obszarów)        |
| `countWith`              | Liczenie po filtrach (who, visitType, par)                     |
| `getRelaxedSuggestions`  | Propozycje poluzowania + struktura `{label, patch}`            |
| Sortowanie               | Wyższy `matchScore` → wyżej; max `W.MAX_FULL` wyników         |

### Analiza statyczna (PHPStan, level 8)

```bash
vendor/bin/phpstan analyse --no-progress
```

Konfiguracja: `phpstan.neon` (korzeń projektu).

- **Zakres**: `web/app/mu-plugins/niepodzielni-core/src/Bookero/`
- **Poziom 8**: generics, dead code, mixed types, type narrowing
- **Stubs WP**: `stubs/wordpress.php` — załadowane przez `bootstrapFiles`
  bez uruchamiania WordPress. Pokrywa `WP_Query`, `WP_Post`, `WP_Error`,
  wszystkie funkcje używane przez `src/Bookero/`.

### Debounce w frontend

Plik `utils/debounce.js` (eksportowany jako ES6 moduł) chroni drogie operacje
DOM przed zalewem eventów przy szybkim pisaniu:

| Punkt zastosowania                      | Opóźnienie | Co chroni                                      |
|-----------------------------------------|------------|------------------------------------------------|
| `#psy-search` (psy-listing-atomic.js)  | 300 ms     | Pełny re-render listy + `filterPsychologists`  |
| `.multiselect-inner-search` (j.w.)     | 150 ms     | `classList.toggle` na opcjach dropdownu        |
| `.np-mm__search` (matchmaker.js)       | 200 ms     | `display:none` na kafelkach obszarów           |

Scoring matchmakera i zmiana checkboxów NIE są debounce-owane — to zdarzenia
dyskretne (kliknięcia), nie ciągłe strumienie wejścia.

---

## 7.5 API patterns — `np_ajax_endpoint()`

Wszystkie nowe AJAX endpointy rejestruj przez wrapper z
`web/app/mu-plugins/niepodzielni-core/api/0-ajax-endpoint-wrapper.php`.

Wrapper auto-obsługuje: nonce, capability check, JSON envelope, rate limit per IP.
Handler skupia się tylko na logice biznesowej.

### Sygnatura

```php
np_ajax_endpoint(string $action, array $config, callable $handler): void
```

Konfiguracja:

| Klucz           | Typ           | Default  | Opis                                                                |
|-----------------|---------------|----------|---------------------------------------------------------------------|
| `public`        | bool          | `false`  | `true` rejestruje też `wp_ajax_nopriv_*` (niezalogowani)            |
| `nonce_action`  | ?string       | `null`   | Nazwa akcji nonce; `null` = brak weryfikacji (read-only public)     |
| `nonce_field`   | string        | `nonce`  | Nazwa pola w `$_POST`/`$_REQUEST` z nonce                            |
| `capability`    | ?string       | `null`   | `current_user_can()` cap (np. `manage_options`)                     |
| `auth_callback` | ?callable     | `null`   | Custom guard `fn(array $request): bool` (np. ownership po `post_author`) |
| `rate_limit`    | ?int          | `null`   | Max wywołań/min/IP (transient `np_rl_<action>_<ip>`)                |

### Handler — kontrakt

```php
function (array $request): mixed {
    // ...logika...
    return ['key' => 'value'];   // → wrapper wyśle wp_send_json_success
    // throw new \Exception(...) // → wrapper wyśle wp_send_json_error 500
    // wp_send_json_*()          // dozwolone — wrapper to akceptuje (legacy ścieżki z różnymi kodami HTTP)
}
```

### Output JSON

| Sytuacja           | HTTP | Body                                                       |
|--------------------|------|------------------------------------------------------------|
| Success (return)   | 200  | `{ "success": true, "data": ... }`                         |
| `invalid_nonce`    | 403  | `{ "success": false, "data": { "error": "invalid_nonce" } }` |
| `forbidden` (cap)  | 403  | `{ "success": false, "data": { "error": "forbidden" } }`     |
| `rate_limited`     | 429  | `{ "success": false, "data": { "error": "rate_limited", "retry_after": 60 } }` |
| Throwable handlera | 500  | `{ "success": false, "data": { "error": "<msg>" } }`        |

### Przykład — prosty endpoint

```php
np_ajax_endpoint('np_my_action', [
    'public'       => false,
    'nonce_action' => 'np_my_nonce',
    'capability'   => 'edit_posts',
    'rate_limit'   => 30,
], function (array $req): array {
    $id = (int) ($req['post_id'] ?? 0);
    return ['title' => get_the_title($id)];
});
```

### Migrowane endpointy (Etap 1, 2026-05)

13 AJAX endpointów zmigrowanych z manualnego boilerplate (`add_action` +
`check_ajax_referer` + `wp_send_json_*`) na wrapper:

| # | Action                       | Plik                                                                                       | Auth                              |
|---|------------------------------|--------------------------------------------------------------------------------------------|-----------------------------------|
| 1 | `bk_ingest_month`            | `web/app/mu-plugins/niepodzielni-core/api/10-ajax-handlers.php`                            | nonce `np_bookero_nonce`, public  |
| 2 | `np_get_terminy`             | `web/app/mu-plugins/niepodzielni-core/api/10-ajax-handlers.php`                            | bez nonce (read-only, page-cache) |
| 3 | `np_refresh_terminy`         | `web/app/mu-plugins/niepodzielni-core/api/10-ajax-handlers.php`                            | nonce + `manage_options`          |
| 4 | `np_refresh_termin_single`   | `web/app/mu-plugins/niepodzielni-core/api/10-ajax-handlers.php`                            | nonce + `manage_options`          |
| 5 | `bk_get_shared_month`        | `web/app/mu-plugins/niepodzielni-core/api/10-ajax-handlers.php`                            | bez nonce (read-only, public)     |
| 6 | `bk_get_date_slots`          | `web/app/mu-plugins/niepodzielni-core/api/10-ajax-handlers.php`                            | bez nonce (read-only, public)     |
| 7 | `bk_verify_hour`             | `web/app/mu-plugins/niepodzielni-core/api/10-ajax-handlers.php`                            | nonce, public                     |
| 8 | `bk_create_booking`          | `web/app/mu-plugins/niepodzielni-core/api/10-ajax-handlers.php`                            | nonce, public                     |
| 9 | `np_panel_save_profile`      | `web/app/mu-plugins/niepodzielni-core/api/30-panel-psycholog.php`                          | nonce + auth_callback (ownership) |
| 10| `np_panel_save_taxonomies`   | `web/app/mu-plugins/niepodzielni-core/api/30-panel-psycholog.php`                          | nonce + auth_callback             |
| 11| `np_panel_upload_photo`      | `web/app/mu-plugins/niepodzielni-core/api/30-panel-psycholog.php`                          | nonce + auth_callback             |
| 12| `np_panel_get_reviews`       | `web/app/mu-plugins/niepodzielni-core/api/30-panel-psycholog.php`                          | nonce + auth_callback             |
| 13| `np_panel_reply_review`      | `web/app/mu-plugins/niepodzielni-core/api/30-panel-psycholog.php`                          | nonce + auth_callback             |

REST-y w `19-ai-endpoints.php` i `40-opinie-api.php` używają innego modelu
auth (X-API-Key bot token, Cloudflare Turnstile, magic_token HMAC) i pozostają
poza tym wrapperem.

### Jak dodać nowy endpoint

1. Wybierz nazwę akcji `np_<context>_<verb>` (snake_case).
2. Wybierz nonce action — utwórz w JS przez `wp_create_nonce('np_xxx_nonce')`.
3. Zarejestruj przez `np_ajax_endpoint()` w odpowiednim pliku `api/`.
4. Logika handlera: zwraca array (success) lub throw \Exception (error 500).
5. Test smoke: DevTools Network → POST z nonce → 200 + body z `success:true`.
6. Test bez nonce → 403 `invalid_nonce`.

---

## 7.6 Forms framework — `BaseFormHandler`

REST-owe formularze publiczne (Turnstile + opcjonalny OTP) idą przez
`web/app/mu-plugins/niepodzielni-core/src/Forms/`:

| Plik                          | LOC | Rola                                                       |
|-------------------------------|-----|------------------------------------------------------------|
| `BaseFormHandler.php`         | 366 | Abstract: `validate()`, `saveSubmission()`, `sendEmails()`, OTP (`generateAndSendOTP` + `verifyOTP`) |
| `ContactForm.php`             |  62 | Konkretny handler (`form_id=contact`)                      |
| `Helpers/CommonFields.php`    | 215 | Predefiniowane konfiguracje pól (imie, email, telefon, kod_pocztowy, …) |
| `Helpers/PhonePrefixes.php`   | 107 | Tabela kierunkowych + min/max długość (walidacja relacyjna) |

REST endpointy w `api/50-forms-api.php`:

- `POST /wp-json/niepodzielni/v1/forms/{form_id}/submit` — walidacja, zapis CPT `zgloszenie`, mail/OTP.
- `POST /wp-json/niepodzielni/v1/forms/{form_id}/verify` — weryfikacja kodu OTP (rate limit 5/15 min per submission).

Rejestr handlerów rozszerzalny przez filtr `np_form_handlers` (mapa
`form_id => FQCN extends BaseFormHandler`).

### Right-sizing — decyzja (Etap 5, 2026-05)

Audyt: 1 konkretny handler (`ContactForm`) + abstract base + helpery = ~750 LOC.
Powierzchowna ocena = overengineering, ale:

- `BaseFormHandler` jest jednowarstwowy (1 abstract → 1 konkretna), nie 5-poziomową hierarchią.
- `CommonFields` to płaski słownik konfiguracji (DRY), nie fabryka fabryk.
- `PLAN-NOWE-FUNKCJE.md` i `PLAN-T1-IMPLEMENTACJA.md` planują reuse w **co najmniej 4 nadchodzących formularzach** (matchmaker email, AI feedback, T1/T2).
- Ścieżka OTP (~80 LOC) jest dziś martwa w `ContactForm` (`requireVerification=false`), ale to jedyne realne źródło "zbędnego" kodu — usunięcie wymusiłoby rekonstrukcję w T1.

**Decyzja: framework jest right-sized dla aktywnie planowanej ekspansji.** Etap 5
zostaje no-op poza tą sekcją. Jeśli za 3 mies. nadal będzie tylko 1 handler i
plany się posypią — wrócić do tematu i zwinąć abstrakcję do prostej funkcji
`np_handle_form_submit()` per form.

### Jak dodać nowy formularz

1. Stwórz `src/Forms/MojForm.php extends BaseFormHandler`.
2. Implementuj `getFormId(): string` i `getFields(): array` (skorzystaj z `CommonFields::*`).
3. Opcjonalnie nadpisz `protected bool $userConfirmation`, `$requireVerification`.
4. Zarejestruj przez filtr (lub dopisz do tablicy w `np_get_form_handlers()`).
5. JS frontu woła `POST /wp-json/niepodzielni/v1/forms/<form_id>/submit` z `cf-turnstile-response`.

---

## 8. Analytics — Cloudflare Zaraz

### Architektura śledzenia

Projekt używa **Cloudflare Zaraz** jako serwer-side analytics runner (zastępuje GTM w przeglądarce).

```
Przeglądarka (bookero-init.js)
    ↓  zaraz.track('purchase', { ... })
Cloudflare Zaraz (edge worker na Cloudflare)
    ├── GA4 → https://www.google-analytics.com/mp/collect
    └── Meta Pixel → https://graph.facebook.com/v18.0/.../events
```

**Zalety vs. GTM**: zero JS w przeglądarce (Zaraz wykonuje się po stronie Cloudflare),
wyższy consent rate, zdarzenia nie blokują głównego wątku.

**Fallback**: gdy `window.zaraz` jest niedostępny (np. Cloudflare nie skonfigurowany),
`npTrack()` automatycznie przekierowuje do `window.dataLayer.push()` (GTM).

### Konfiguracja Zaraz — dane

| Parametr           | Wartość                              |
|--------------------|--------------------------------------|
| GA4 Measurement ID | `G-4RNZ5C9SRJ`                      |
| GA4 API Secret     | Ustawiony w Cloudflare Dashboard     |
| Meta Pixel ID      | Ustawiony w Cloudflare Dashboard     |
| Trigger            | Zaraz Custom Event (każde `zaraz.track()`) |

### Wspólny helper trackingu — `lib/track.js`

Wszystkie eventy (Bookero, AI chat, formularze) idą przez `npTrack(name, props)`
z [resources/js/lib/track.js](../web/app/themes/niepodzielni-theme/resources/js/lib/track.js).
Kanały (priorytet malejąco):

1. `window.zaraz.track(name, props)` — primary, server-side przez Cloudflare Zaraz.
2. `window.dataLayer.push({ event: name, ...props })` — GTM-compat fallback.
3. `navigator.sendBeacon('/__np_track', payload)` — tylko dla eventów krytycznych
   (`purchase`, `donation`, `generate_lead`) gdy oba kanały powyżej zawiodą.

Helper automatycznie dodaje:
- `event_id` (UUID v4) — wymagany do deduplikacji Meta CAPI ↔ Pixel.
- `timestamp` (epoch ms).

### Zdarzenia — mapowanie JS → GA4 → Meta

Zdarzenia Bookero są emitowane przez [bookero-init.js](../web/app/themes/niepodzielni-theme/resources/js/bookero-init.js)
(po standaryzacji na GA4 Ecommerce nazwy). AI chat — [components/ai-chat.js](../web/app/themes/niepodzielni-theme/resources/js/components/ai-chat.js).

| Event JS (Zaraz)              | GA4 (CF dashboard)   | Meta Pixel (CF dashboard) | Trigger                              |
|-------------------------------|----------------------|---------------------------|--------------------------------------|
| `view_item` (`source=bookero`) | `view_item`          | `ViewContent`             | bookero `form-loaded`                |
| `add_to_cart`                 | `add_to_cart`        | `AddToCart`               | bookero `add-to-cart`                |
| `begin_checkout`              | `begin_checkout`     | `InitiateCheckout`        | bookero `start-checkout`             |
| `purchase`                    | `purchase`           | `Purchase`                | bookero `purchase` (potwierdzona)    |
| `purchase_failed`             | (custom)             | (custom)                  | bookero `failed-purchase`            |
| `purchase_pending`            | (custom)             | (custom)                  | bookero `waiting-purchase`           |
| `chat_opened`                 | (custom)             | (none)                    | ai-chat otwarty                      |
| `message_sent`                | (custom)             | (none)                    | ai-chat user submit                  |
| `psychologist_card_clicked`   | `select_item`        | (custom)                  | klik kafelka psychologa w czacie     |
| `booking_intent`              | (custom)             | (custom)                  | klik CTA „umów" z karty psychologa   |
| `conversation_rated`          | (custom)             | (none)                    | thumbs up/down w czacie              |

Mapa Bookero browser event → nazwa GA4 jest w `BOOKERO_EVENT_MAP` w
[bookero-init.js:179](../web/app/themes/niepodzielni-theme/resources/js/bookero-init.js).

### Consent Mode v2 — sygnalizacja

`lib/track.js` eksportuje `setConsentDefault(signals?)` i `updateConsent(signals)`,
gdzie `signals` to podzbiór czterech kluczy Consent Mode v2:

| Klucz                | GA4 / Google Consent Mode v2 mapping |
| -------------------- | ------------------------------------ |
| `analytics`          | `analytics_storage`                  |
| `ads`                | `ad_storage`                         |
| `ad_user_data`       | `ad_user_data`                       |
| `ad_personalization` | `ad_personalization`                 |

Te klucze są **purposeIds** w Cloudflare Zaraz → Consent → Purposes — muszą być
skonfigurowane w dashboardzie z dokładnie takimi nazwami (case-sensitive).
Wszystkie cztery są **default `denied`** (GDPR compliance).

**Default state (denied)** — `app.js` wywołuje `setConsentDefault({...all denied})`
od razu po starcie, ZANIM jakikolwiek `npTrack()` zostanie odpalony. Jeśli user
ma już decyzję w `localStorage.np_consent` (TTL 6 miesięcy), `app.js` re-stosuje
ją zamiast pełnego deny — Zaraz nie musi kolejkować eventów dla powracających
gości.

**Banner CMP** — `resources/js/consent-banner.js` (entry vite, ~1KB gzip),
renderowany przez `partials/consent-banner.blade.php`. Banner jest w layoucie
`layouts/app.blade.php` z wyjątkiem `template-pomoc-kryzys.blade.php` (Crisis Hub
nie tracukje, więc CMP byłby tylko hałasem). Trzy CTA:

- **„Akceptuję wszystkie"** → `updateConsent({analytics:true, ads:true, ad_user_data:true, ad_personalization:true})`
- **„Tylko niezbędne"** → wszystko `false` (eventy `purchase`, `donation`, `generate_lead`
  i tak idą przez Zaraz „anonymous ping" — patrz niżej).
- **„Zarządzaj zgodami"** → rozwija fieldset z 4 checkboxami; **„Zapisz wybór"**
  zapisuje wybrany podzbiór.

**Re-show** — link `[data-np-consent-open]` w stopce („Zmień zgody") otwiera
banner ponownie z prefillem aktualnych wyborów.

**Persistence** — `localStorage.np_consent` JSON: `{ ts, analytics, ads, ad_user_data,
ad_personalization }`. TTL 6 miesięcy (po wygaśnięciu banner pojawia się
automatycznie przy następnej wizycie).

**Eventy krytyczne** (`purchase`, `donation`, `generate_lead`) mają zachowanie
„anonymous ping" przez Zaraz Consent Mode v2 — Cloudflare wysyła je nawet bez
zgody (zgodnie z GA4/Meta conversion modeling). Lokalnie nie blokujemy.

#### Co Zaraz dashboard musi mieć skonfigurowane

1. **Consent Manager → Enabled**.
2. **Purposes**: cztery wpisy z `id` dokładnie takimi: `analytics`, `ads`,
   `ad_user_data`, `ad_personalization`. Wszystkie `default = false`.
3. **GA4 Tool → Consent**: nasłuch na purpose `analytics` (i `ad_user_data`,
   `ad_personalization` dla CM v2 signals). Mapuje na `analytics_storage`,
   `ad_user_data`, `ad_personalization` w GA4 config command.
4. **Meta Pixel Tool → Consent**: nasłuch na purpose `ads`.
5. **Conversion modeling**: GA4 → włącz „Consent Mode (advanced)" w GA4
   Property → Admin → Data Streams.

#### Test plan — GA4 DebugView (consent_state)

Cel: zweryfikować że Consent Mode v2 sygnały trafiają do GA4 zgodnie z UX bannera.

Setup:
- Otwórz GA4 → Admin → DebugView (włącz `?_dbg=1` w URL lub rozszerzenie GA Debugger).
- Otwórz stronę w trybie inkognito (czyste localStorage).

| Scenariusz                                       | Oczekiwany `consent_state` w GA4 DebugView |
| ------------------------------------------------ | ------------------------------------------ |
| Page load, banner widoczny, user nic nie kliknął | `G100` (wszystko denied)                   |
| Klik „Akceptuję wszystkie"                       | `G111` (analytics + ad_storage + ad_user_data + ad_personalization granted) |
| Klik „Tylko niezbędne"                           | `G100` (wszystko denied)                   |
| „Zarządzaj zgodami" → tylko Analityka → Zapisz   | `G110` (analytics granted, ads denied)     |
| Reload po decyzji „Akceptuję wszystkie"          | `G111` od razu (z localStorage), banner ukryty |
| Klik „Zmień zgody" w stopce                      | Banner pojawia się ponownie z prefillem    |
| Strona `/pomoc-w-kryzysie/`                      | Banner NIE pojawia się, `setConsentDefault({...denied})` jeden raz, brak `npTrack` |

Smoke checklist:
- [ ] Po wyczyszczeniu localStorage banner pojawia się przy kolejnym page load.
- [ ] Po `localStorage.removeItem('np_consent')` + reload banner też się pojawia.
- [ ] Klawisz Tab krąży wewnątrz dialogu (focus trap), Esc zamyka tylko gdy
      decyzja już istnieje (ponowne otwarcie z stopki).
- [ ] `aria-labelledby` / `aria-describedby` poprawnie wskazują na tytuł i opis.

### Payload zdarzenia `purchase`

```javascript
zaraz.track('purchase', {
    transaction_id:       'BKR-1234567890',   // data.transaction_id || 'BKR-'+Date.now()
    value:                135,                 // data.value (PLN)
    currency:             'PLN',               // data.currency
    items: [{
        item_id:           'service-50604',   // cartItem.id || cartItem.sku
        item_name:         'Jan Kowalski',    // cartItem.itemName || psychName z DOM
        item_category:     'Konsultacja pełnopłatna', // lub 'Konsultacja niskopłatna'
        price:             135,
        discount:          0,                 // cartItem.discount (jeśli istnieje)
        quantity:          1,
    }],
    bookero_consult_type: 'pelno',            // 'pelno' | 'nisko'
    bookero_psychologist: 'Jan Kowalski',     // z .psy-name-h1 w DOM
});
```

Struktura `cartItems[]` pochodzi bezpośrednio z payloadu Bookero:
`{ id, sku, itemName, price, discount }`.

### Jak skonfigurować nowe zdarzenie w Zaraz

1. Zaloguj do **Cloudflare Dashboard** → Twoja domena → **Zaraz**
2. Przejdź do **Tools** → GA4 → **Edit** → **Events**
3. Dodaj **Custom Event**: name = `purchase` (lub `bookero_add-to-cart` itp.)
4. Mapuj pola: `value`, `currency`, `transaction_id`, `items` → odpowiednie parametry GA4
5. Analogicznie dla Meta Pixel: Zaraz → Tools → Meta Pixel → Events

### Lokalne testowanie (dev mock)

`web/app/mu-plugins/zaraz-dev-mock.php` (plik w `.gitignore` — **nie trafia na produkcję**)
wstrzykuje `window.zaraz` mock gdy `WP_DEBUG=true`. Mock loguje wszystkie `zaraz.track()`
wywołania do konsoli przeglądarki ze stylizowanym formatowaniem.

```php
// Automatycznie aktywny lokalnie gdy WP_DEBUG=true w .env
// Sprawdź konsolę przeglądarki — szukaj pomarańczowych logów "[Zaraz mock aktywny]"
```

Jeśli mock **nie jest aktywny lokalnie**, sprawdź że `zaraz-dev-mock.php` istnieje w
`web/app/mu-plugins/` — plik jest w `.gitignore` i nie zostanie sklonowany z repo.
Skopiuj go z `docs/` lub utwórz ręcznie.

### S2S Conversion API — Meta CAPI + GA4 Measurement Protocol

Dla krytycznych eventów (`purchase`, `generate_lead`, `sign_up`) wysyłamy **dodatkowo**
po stronie serwera do GA4 MP + Meta CAPI. Daje lepszą atrybucję gdy klient blokuje
3rd-party JS / cookies. `event_id` jest **wspólny** z Zaraz client-side → Meta
deduplikuje (Pixel+CAPI muszą mieć ten sam `event_id`).

**Flow:**

```
[Browser]                                     [Cloudflare Edge]      [Origin / WordPress]
   │                                                  │                       │
   ├─ npTrack('purchase', {…})                        │                       │
   │   ├── window.zaraz.track() ─────────────────────►│                       │
   │   │                                             GA4 + Meta Pixel         │
   │   │                                             (client-side, edge)      │
   │   │                                                                     │
   │   └── navigator.sendBeacon('/wp-json/np/v1/track') ─────────────────────►│
   │                                                                         │
   │                                                          [mu-plugin np-conversion-api]
   │                                                          ├── verify nonce + rate limit
   │                                                          ├── hash PII (SHA-256)
   │                                                          ├── POST GA4 MP    (non-blocking)
   │                                                          └── POST Meta CAPI (non-blocking)
   │                                                                         │
   └─◄─ 200 { ok: true, ga4: 'queued', meta: 'queued' }
```

**Pliki:**

- `web/app/mu-plugins/np-conversion-api/np-conversion-api.php` — endpoint REST + senders.
- `web/app/mu-plugins/np-conversion-api.php` — loader stub (Bedrock auto-load top-level only).
- `web/app/themes/niepodzielni-theme/resources/js/lib/track.js` — funkcja `sendS2S()`.
- `config/application.php` — constants `NP_GA4_*`, `NP_META_*` z `env()`.
- `trellis/group_vars/production/wordpress_sites.yml` — env mapping z vault.

**Wymagane zmienne env (vault — operator dodaje sam):**

| Klucz vault                        | Cel                                            |
|------------------------------------|------------------------------------------------|
| `np_ga4_measurement_id`            | GA4 Measurement ID (np. `G-XXXXXXXXXX`)        |
| `np_ga4_api_secret`                | GA4 MP API Secret (Admin → Data Streams → MP)  |
| `np_meta_pixel_id`                 | Meta Pixel ID                                  |
| `np_meta_capi_token`               | Meta CAPI System User Access Token             |

Brak wartości = endpoint zwraca `200 { ga4: 'skipped_no_config', meta: 'skipped_no_config' }`
(non-fatal). Tracking client-side (Zaraz) nadal działa.

**Bezpieczeństwo / privacy:**

- PII (`email`, `phone`, `first_name`, `last_name`, `city`, `zip`, `country`, IP) hashowane
  SHA-256 (lower-case, trim) przed wysyłką do GA4 / Meta.
- `client_ip_address` + `client_user_agent` Meta przyjmuje raw (server-side standard).
- Nonce `wp_rest` + rate limit 60/min/IP (transient `np_s2s_rl_<md5(ip)>`).
- Crisis Hub (`/pomoc-w-kryzysie/*`) — białą listą JS pomija fetch S2S.
- HTTP do GA4/Meta = `wp_remote_post(blocking: false)` — fire-and-forget; <50ms p99 endpoint.

**Test plan:**

1. **Unit (Pest)**: hashing PII (lower-case + trim + SHA-256) — assertion że `email`
   `JOHN.DOE@example.com ` → znany SHA-256 hash; phone `+48 600 123 456` → `48600123456`.
2. **Integration**: POST `/wp-json/np/v1/track` bez nonce → 403; z nonce + nieobsługiwany
   event → 400 (`event_not_allowed`); z legalnym payloadem → 200 (`ga4: skipped_no_config`
   gdy ENV puste).
3. **Rate limit**: 61 requestów w <1 min → 429.
4. **Browser smoke (staging)**: w DevTools → Network filter "track" — po sukcesie
   Bookero powinien być sendBeacon do `/wp-json/np/v1/track` (status 0 lub 200).
   `event_id` w request body = ten sam co w Zaraz event w konsoli.
5. **Meta Events Manager**: zakładka "Test Events" → po wpisaniu test code w `custom_data.test_event_code`
   sprawdź że Pixel + CAPI events z tym samym `event_id` są zaznaczone jako "Deduplicated".
6. **GA4 DebugView**: ustaw `debug_mode: true` w `custom_data` → event powinien pojawić się
   w Realtime → DebugView z parametrami GA4.

---

## 9. Proponowany Backlog Techniczny

---

### Ticket 1: Refaktor `matchmaker.js` — pełna dekompozycja na moduły ES6

**Status**: ✅ Silnik scoringowy wyekstrahowany (`matchmaker/ScoringEngine.js`)

`ScoringEngine.js` to czysta funkcja bez zależności od DOM — zawiera `W` (wagi),
`runMatchmakerWith()`, `countWith()`, `getRelaxedSuggestions()` i jest objęty
testami Vitest (`__tests__/ScoringEngine.test.js`).

**Pozostało do zrobienia**

Plik [matchmaker.js](../web/app/themes/niepodzielni-theme/resources/js/matchmaker.js)
nadal zawiera ~600 LOC łączących: zarządzanie stanem, renderowanie HTML,
obsługę eventów DOM i persystencję (sessionStorage + URL).

Kolejne kroki dekompozycji:

```
resources/js/matchmaker/
├── ScoringEngine.js  ✅ gotowe — testy Vitest
├── state.js          ⬜ Klasa Store: get/set state, save/restore session+URL
├── renderer.js       ⬜ Klasa: step→HTML, _tplProgress, _tplStep1, _tplResults
├── events.js         ⬜ Podpinanie event listenerów
└── index.js          ⬜ Orkiestrator — montuje wszystko, inicjalizuje
```

Dodanie nowego filtru po refaktorze będzie wymagało zmiany tylko `ScoringEngine.js`
(logika) i `state.js` (persist), bez dotykania renderowania.

---

### Ticket 2: Wzorzec Repository dla operacji bazodanowych Bookero ✅ ZREALIZOWANE

> **Status**: Zaimplementowane jako `PsychologistRepository` + `BookeroSyncService` + `SharedCalendarService`
> w `web/app/mu-plugins/niepodzielni-core/src/Bookero/`. Opis poniżej zachowany jako dokumentacja historyczna.

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

### Ticket 3: Odporność na błędy HTTP — Circuit Breaker ✅ ZREALIZOWANE

> **Status**: Zaimplementowany Circuit Breaker w `api/13-bookero-worker-sync.php` i
> `BookeroApiClient::parseResponse()`. `BookeroRateLimitException` (HTTP 429, cURL timeout)
> → `BOOKERO_LOCKOUT_KEY` transient 15 min. Opis poniżej zachowany jako dokumentacja historyczna.
>
> Oryginalny tytuł: "Odporność na błędy HTTP w `np_bookero_get_terminy()` — retry + circuit breaker"

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
