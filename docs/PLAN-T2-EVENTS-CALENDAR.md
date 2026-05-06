# Plan implementacji #10 — Wydarzenia & warsztaty: kalendarz + iCal

> Funkcja z Tier 2 planu rozbudowy. Estymacja: M (1-2 tyg).
> Skupiamy się na 4 deliverable'ach wokół CPT `wydarzenia`, `warsztaty`, `grupy-wsparcia`.

## Kontekst

Dziś użytkownik widzi listę wydarzeń (`/wydarzenia`) i listę warsztatów+grup (`/warsztaty-grupy`). Brakuje:
- ujednoliconego widoku **kalendarza miesięcznego** (wszystkie 3 CPT razem),
- **mechanizmu zachowania w prywatnym kalendarzu** użytkownika (download `.ics` per wydarzenie, subskrypcja całego cyklu przez `webcal://`),
- **przypomnień email** dla zainteresowanych — bez tworzenia konta.

Cel biznesowy: większa frekwencja na wydarzeniach + powracający users (ich kalendarz przypomina im o wydarzeniu w naszej fundacji).

## Stan zastany (zweryfikowany w kodzie)

- **CPT** `wydarzenia` (`cpt/17`), `warsztaty` (`cpt/18`), `grupy-wsparcia` (`cpt/19`) — wszystkie `show_in_rest: true`.
- **Carbon Fields** (`cpt/21-carbon-fields.php`):
  - Wydarzenia: `data` (Y-m-d), `godzina_rozpoczecia`, `godzina_zakonczenia` (HH:MM), `miasto`, `lokalizacja`, `koszt`, `opis`.
  - Warsztaty + grupy (wspólne): `data`, `godzina`, `godzina_zakonczenia`, `lokalizacja`, `status`, `cena`, `cena_rodzaj`, `temat`, `prowadzacy_id`.
  - **Brak pola online/offline** ani link Zoom — pomijamy w MVP, zostaje na potem.
- **EventsListingService** (`theme/app/Services/`) ma już `getWorkshopsData()` i `getWydarzeniaData()` z transient cache (1h) — **reuse 100%**.
- **REST API** w namespace `niepodzielni/v1`, Turnstile helper `np_verify_turnstile()` w `api/50-forms-api.php`.
- **Cron pattern** w `api/9-bookero-sync.php` (custom interval `np_every_minute` przez `cron_schedules` filter).
- **Email** przez `wp_mail()` z `wp_mail_content_type` filter (wzorzec w `BaseFormHandler::sendEmails`).
- **Brak SMTP plugina** — natywny `wp_mail` (na produkcji konfiguracja SMTP po stronie hostingu lub plugin do dorzucenia później).

## Decyzje produktowe

| Decyzja | Wybór | Uzasadnienie |
|---|---|---|
| Co pokazuje kalendarz | Wszystkie 3 CPT razem (wydarzenia + warsztaty + grupy) | Unified view którego dziś nie ma |
| Lokalizacja kalendarza | **Nowa strona `/kalendarz`** (template Sage) | Czystsze niż toggle na istniejącym listingu; nie psuje obecnego flow |
| Strefa czasowa | **Europe/Warsaw** w iCal `VTIMEZONE` | Czas wpisany przez admina jest w PL — klient kalendarza interpretuje poprawnie |
| Email confirmation | **Bez magic-link**, tylko Turnstile + walidacja `is_email()` | Opt-in lekki, bez frykcji; spam blokowany przez Turnstile |
| Cron interval | **Hourly** (WP `hourly` schedule) | Wydarzenia wieczorowe wymagają precyzji; daily zostawiał ryzyko 23h opóźnienia |
| iCal endpoint location | `/wp-json/niepodzielni/v1/calendar/...` | Spójne z resztą REST; uniknięcie kolizji z natywnym WP `/feed/` |
| Reminder window | T-24h (dzień wcześniej) | Zgodne z planem |

## Architektura

### Backend (mu-plugin)

**Nowy namespace PSR-4** `Niepodzielni\\Calendar\\` → `web/app/mu-plugins/niepodzielni-core/src/Calendar/` (composer.json autoload + `composer dump-autoload`).

**Klasy:**

1. **`src/Calendar/IcalGenerator.php`** — generator RFC 5545:
   - `generateEvent(array $event): string` — pojedyncze VEVENT
   - `generateFeed(array $events): string` — pełny VCALENDAR z VTIMEZONE Europe/Warsaw + N×VEVENT
   - Helper `escape()` (zgodnie z RFC: `\` → `\\`, `,` → `\,`, `;` → `\;`, `\n` → `\n`)
   - UID: `event-{cpt}-{post_id}@niepodzielni.com` (stabilny — kluczowe dla update'ów po stronie klienta kalendarza)
   - DTSTAMP: `gmdate('Ymd\THis\Z')` (UTC, czas generacji feedu)
   - DTSTART/DTEND: `TZID=Europe/Warsaw:YYYYMMDDTHHMMSS` (Warsaw local)
   - Fallback gdy brak `godzina_zakonczenia`: DTEND = DTSTART + 1h
   - Fallback gdy brak `godzina_rozpoczecia` w ogóle: ALL-DAY event (`DTSTART;VALUE=DATE:YYYYMMDD`)
   - LOCATION: `"$lokalizacja"` (wydarzenia: prefiks `$miasto, ` jeśli oba)
   - SUMMARY: post_title (lub `temat` dla warsztatów)
   - DESCRIPTION: `opis`/`excerpt` + permalink na końcu + (dla warsztatów) cena
   - URL: permalink

2. **`src/Calendar/EventReminderService.php`** — wysyłka przypomnień:
   - `sendDueReminders(): int` — wywoływane przez cron, zwraca liczbę wysłanych emaili
   - Logika: SELECT z `wp_np_event_reminders` JOIN `wp_postmeta` WHERE `data = tomorrow` AND `sent_at IS NULL`; loop → `wp_mail` z HTML template → UPDATE `sent_at = NOW()`
   - Idempotency: `sent_at IS NULL` — drugi run tego samego dnia nie wyśle ponownie
   - Email template: `src/Calendar/templates/reminder-email.php` (HTML, escapowane przez `esc_html`)

3. **`api/72-events-calendar-api.php`** — bootstrap + REST routes:
   - `dbDelta` dla `wp_np_event_reminders (id PK, email, event_post_id, sent_at NULL, created_at, UNIQUE (email, event_post_id))`
   - REST routes:
     - `GET /niepodzielni/v1/calendar/event/{id}.ics` — single download (Content-Type: text/calendar, attachment filename)
     - `GET /niepodzielni/v1/calendar/feed.ics` — pełny feed (max 200 najbliższych nadchodzących wydarzeń, cache 30 min jak listing)
     - `POST /niepodzielni/v1/calendar/reminder` — opt-in (body: `email`, `event_post_id`, `cf-turnstile-response`)
   - Helper `np_calendar_collect_events(string $from, string $to): array` — łączy `getWorkshopsData()` + `getWydarzeniaData()`, filtruje po dacie (lub default: nadchodzące 6 miesięcy)
   - Permalink override dla iCal: trzymamy ścieżkę REST, `webcal://` URL = `webcal://niepodzielni.com/wp-json/niepodzielni/v1/calendar/feed.ics`

4. **`api/73-events-reminders-cron.php`** — cron registration:
   - Użycie wbudowanego `hourly` (nie tworzymy nowego interval'u)
   - Hook `np_event_reminders_cron` schedule on `wp` action (jeśli `! wp_next_scheduled`)
   - Callback: `(new EventReminderService())->sendDueReminders()`

### Frontend (theme)

5. **`app/View/Composers/TemplateKalendarz.php`** — Composer dla widoku kalendarza:
   - Konstruktor: `EventsListingService` (singleton z ServiceProvider — już zarejestrowany)
   - `with()`: zwraca `['events' => ..., 'currentMonth' => ..., 'cpt_filters' => ...]`
   - Parsuje `?month=YYYY-MM` z URL (lub default = bieżący miesiąc)
   - Grupuje eventy po dacie: `[Y-m-d => [event, event, ...]]`

6. **`resources/views/template-kalendarz.blade.php`** + **`partials/calendar-month.blade.php`**:
   - Header: nawigacja prev/next month + filter chip'y (wszystko / wydarzenia / warsztaty / grupy)
   - Grid 7×N (poniedziałek-niedziela), komórki dni z eventami
   - Każdy event w komórce: kolor-kodowany dot (CPT) + tytuł (truncate) → link do single
   - "Subskrybuj kalendarz" CTA: button z `webcal://...` URL + tooltip "skopiuj link"
   - Mobile: collapsed list view (wszystkie wydarzenia w danym miesiącu jako pionowa lista pogrupowana po dniach)
   - Print-friendly CSS (`@media print`)

7. **`resources/js/components/event-calendar.js`**:
   - Filter chips: pokazuje/ukrywa `[data-cpt-filter]` poprzez CSS class toggle
   - Prev/next month: navigation z `?month=YYYY-MM` (server-rendered z Composera, JS robi tylko progressive enhancement — link działa bez JS)
   - "Subskrybuj kalendarz": copy webcal URL do schowka via Clipboard API + toast

8. **`resources/js/components/event-reminder.js`** — opt-in form na single page:
   - Form na `single-wydarzenia.blade.php` / `single-warsztaty.blade.php` / `single-grupy-wsparcia.blade.php`:
     `<form data-event-reminder data-event-id="{post_id}">`
   - Input email + Turnstile widget + submit
   - POST `/niepodzielni/v1/calendar/reminder` → success state "Przypomnienie ustawione" / error toast

9. **Single page templates** — dorzucamy 2 elementy:
   - **Przycisk „Dodaj do mojego kalendarza"** → link do `/wp-json/niepodzielni/v1/calendar/event/{id}.ics` (download)
   - **Form opt-in** „Przypomnij mi 1 dzień wcześniej" (3 single templates: wydarzenia, warsztaty, grupy-wsparcia)

10. **CSS** — `resources/css/templates/kalendarz.css` (organisms-level, ~250 linii):
    - Grid kalendarza, kolor-kody CPT, hover, today highlight
    - Mobile breakpoint (≤640px) → list view
    - Form CSS spójny z `partials/donate-block`

### Schema DB

```sql
CREATE TABLE wp_np_event_reminders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  event_post_id BIGINT UNSIGNED NOT NULL,
  sent_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_email_event (email, event_post_id),
  KEY idx_sent_at (sent_at),
  KEY idx_event (event_post_id)
);
```

UNIQUE blokuje multi-opt-in tego samego maila. `sent_at NULL` = pending; po wysłaniu cron wpisuje datę.

## Pliki — pełna lista

| Plik | Akcja |
|---|---|
| `composer.json` | Add autoload `Niepodzielni\\Calendar\\` |
| `web/app/mu-plugins/niepodzielni-core.php` | Register 2 nowe pliki API |
| `web/app/mu-plugins/niepodzielni-core/src/Calendar/IcalGenerator.php` | Nowy |
| `web/app/mu-plugins/niepodzielni-core/src/Calendar/EventReminderService.php` | Nowy |
| `web/app/mu-plugins/niepodzielni-core/src/Calendar/templates/reminder-email.php` | Nowy template HTML |
| `web/app/mu-plugins/niepodzielni-core/api/72-events-calendar-api.php` | Nowy: DB + REST routes |
| `web/app/mu-plugins/niepodzielni-core/api/73-events-reminders-cron.php` | Nowy: cron schedule + handler |
| `web/app/themes/niepodzielni-theme/app/View/Composers/TemplateKalendarz.php` | Nowy |
| `web/app/themes/niepodzielni-theme/resources/views/template-kalendarz.blade.php` | Nowy template |
| `web/app/themes/niepodzielni-theme/resources/views/partials/calendar-month.blade.php` | Nowy partial |
| `web/app/themes/niepodzielni-theme/resources/views/partials/event-reminder-form.blade.php` | Nowy partial (form opt-in) |
| `web/app/themes/niepodzielni-theme/resources/js/components/event-calendar.js` | Nowy |
| `web/app/themes/niepodzielni-theme/resources/js/components/event-reminder.js` | Nowy |
| `web/app/themes/niepodzielni-theme/resources/css/templates/kalendarz.css` | Nowy |
| `web/app/themes/niepodzielni-theme/resources/css/app.css` | Import nowego CSS |
| `web/app/themes/niepodzielni-theme/vite.config.js` | 2 nowe entry points |
| `web/app/themes/niepodzielni-theme/app/setup.php` | Conditional enqueue |
| `web/app/themes/niepodzielni-theme/resources/views/single-wydarzenia.blade.php` | Dodaj 2 sekcje (download + opt-in) |
| `web/app/themes/niepodzielni-theme/resources/views/single-warsztaty.blade.php` | jw. |
| `web/app/themes/niepodzielni-theme/resources/views/single-grupy-wsparcia.blade.php` | jw. |

## Bezpieczeństwo

- **Turnstile** na endpoint `/calendar/reminder` (reuse `np_verify_turnstile`).
- **Email validation**: `is_email()` server-side przed INSERT.
- **Rate limit**: UNIQUE constraint w DB blokuje wielokrotne opt-in dla tego samego email + event.
- **iCal escape**: starannie escape'owane wszystkie pole user-generated (RFC 5545: `,;\` + folding linii ≥75 znaków).
- **REST permission**: `__return_true` dla GET endpointów (publiczne); POST z Turnstile.
- **Email content**: HTML escape przez `esc_html`/`wp_kses_post`, link unsubscribe (token w URL → DELETE wpis z `wp_np_event_reminders`).
- **Cron idempotency**: `sent_at IS NULL` filter + UPDATE w transakcji.

## Edge cases

- Wydarzenie odwołane (status="Odwołane") → wyłącz z feedu i kalendarza, anuluj pending reminders.
- Wydarzenie przesunięte (admin zmienił datę) → invalidate transient cache (już istnieje hook); reminder cron sprawdza `data` w trakcie, więc nowa data automatycznie używana.
- Brak `godzina_rozpoczecia` → ALL-DAY event w iCal (RFC 5545: `DTSTART;VALUE=DATE:YYYYMMDD`).
- Wydarzenie dziś za 30 min → reminder T-24h już dawno minął (cron pomija); nie próbujemy "lepszego niż nic".
- User opt-in 5 minut przed crone'em → reminder cron znajdzie go w następnym tick'u; opt-in dla wydarzenia za <24h jest pomijany (też zwracamy ten komunikat w response).
- Polskie znaki w SUMMARY/LOCATION → iCal MIME type i charset UTF-8 (`Content-Type: text/calendar; charset=utf-8`).

## Testy

- **Pest** `tests/Unit/Calendar/IcalGeneratorTest.php`:
  - VEVENT z poprawnym DTSTART/DTEND/SUMMARY/UID
  - Escape przecinka, średnika, backslasha
  - ALL-DAY fallback
  - Folding długich linii
- **Pest** `tests/Feature/EventReminderTest.php`:
  - Opt-in tworzy wpis w DB
  - Drugi opt-in tego samego email+event nie tworzy duplikatu
  - `sendDueReminders()` znajduje wydarzenia z datą tomorrow i wysyła email (mock `wp_mail`)
  - Po wysyłce `sent_at` jest ustawione, drugi run tego samego dnia → 0 emails
- **Vitest** `event-calendar.test.js`:
  - Filter chip toggle ukrywa/pokazuje eventy z odpowiednim `data-cpt`
  - Webcal copy zaalrt'uje (mock Clipboard API)

## Smoke test E2E

1. `composer dump-autoload` + uruchom Docker stack.
2. WP Admin → Strony → utwórz „Kalendarz" → Slug `kalendarz` → szablon „Kalendarz wydarzeń".
3. WP Admin → Wydarzenia → dodaj 3 testowe wydarzenia (jutro, za tydzień, za miesiąc) z `data`, `godzina_rozpoczecia`, `lokalizacja`.
4. Dodaj 2 warsztaty (cpt warsztaty) i 1 grupę (cpt grupy-wsparcia).
5. Otwórz `/kalendarz/`:
   - Widać kalendarz miesięczny z wszystkimi 6 wydarzeniami w odpowiednich dniach.
   - Filter chip'y działają (toggle CPT).
   - Prev/next month nawiguje (URL zmienia się na `?month=YYYY-MM`).
   - Click na event → `/wydarzenie/.../`.
6. Na `single-wydarzenia` przycisk „Dodaj do mojego kalendarza" → pobiera `event-{id}.ics` → otwórz w Apple Calendar / Google Calendar import → event poprawnie zaimportowany z czasem Europe/Warsaw.
7. „Subskrybuj kalendarz" → kopiuje `webcal://localhost:8000/wp-json/niepodzielni/v1/calendar/feed.ics`.
   - W kliencie kalendarza (np. Apple Calendar → File → New Calendar Subscription) wklej URL → wszystkie 6 events się pojawia.
8. Form „Przypomnij mi" → wpisz `test@example.com` + checkbox Turnstile (lub bypass w dev) → POST → response success → wpis w `wp_np_event_reminders`.
9. Ręcznie ustaw datę wydarzenia na "tomorrow" w admin, w Docker shell:
   ```bash
   docker exec niepodzielni-php wp cron event run np_event_reminders_cron
   ```
   → email do `test@example.com` (sprawdź MailHog/log).
10. Drugi run cron'a → `sent_at` blokuje powtórną wysyłkę.

## Sekwencja implementacji

| Dzień | Zadanie |
|---|---|
| 1 | composer.json + namespace + IcalGenerator + Pest unit testy |
| 2 | api/72 (DB + REST single + REST feed) + smoke test downloadu .ics |
| 3 | TemplateKalendarz Composer + template-kalendarz.blade.php + calendar-month.blade.php + CSS |
| 4 | event-calendar.js + nawigacja miesięcy + smoke test widoku |
| 5 | EventReminderService + REST opt-in + DB tabela |
| 6 | event-reminder.js + form na single pages + Pest feature test |
| 7 | api/73 cron + email template + smoke test wysyłki |
| 8 | Polish: print CSS, prefers-reduced-motion, error states, single-{cpt} integration |

**Łącznie ~7-8 dni roboczych** (1.5 sprintowego tygodnia).

## Co po akcepcie

1. Aktualizuję todo list, zaznaczam plan jako completed.
2. Zaczynam Day 1 (composer + IcalGenerator + Pest unit) — to czyste, bez zewnętrznych zależności.
3. Każda sekwencja days 1-7 → osobny commit z `feat(calendar):` prefix.
4. Po days 1-4 (kalendarz + iCal) — rozważam interim commit + push, żeby zobaczyć rendering w PR.
5. Po days 5-7 → finalny commit + update PR description.
