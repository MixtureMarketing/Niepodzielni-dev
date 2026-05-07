# Plan implementacji Tier 1 — Sprint 1 (szczegółowy)

> Skupiamy się na 3 funkcjach: **#2 Crisis Help Hub**, **#5 Public proof**, **#4 Fundraising + Stripe**. Plan jest wykonalny w sekwencji ~2-3 tygodni przy reuse istniejącej infrastruktury.

## Konwencje projektu (decyzje)

- **Blade-only**: cała zawartość stron żyje w widokach Blade (`resources/views/template-*.blade.php` + partials). Nie używamy Gutenberga, Carbon Fields Block, ani shortcode'ów. Strony WP są pustymi pojemnikami (slug + szablon), zawartość pochodzi z templatu.
- **Stripe**: klucze testowe dostarczone osobno do `.env`. Implementacja od razu z prawdziwym SDK.
- **KRS Fundacji**: placeholder `NP_FUNDACJA_KRS` (env var); finalna wartość wpisana przez admin lub w PR review.
- **Worker deploy**: kod `chat.ts` aktualizowany w PR; `wrangler deploy` wykonuje admin po merge.

## Stan zastany (zweryfikowany w kodzie)

- `composer.json` — brak `stripe/stripe-php`, brak `dompdf/dompdf`. PHP `>=8.3` OK dla obu.
- `web/app/themes/niepodzielni-theme/resources/views/sections/footer.blade.php` już ma sekcję emergency-number-col z `112` i `116 111` (l. 66, 70) — punkt zaczepienia dla Crisis Hub linka.
- `web/app/mu-plugins/niepodzielni-core/api/21-psychomapa-endpoint.php` używa cache 6h (klucz `np_psychomapa_all`, group `np_psychomapa`); taksonomie `rodzaj-pomocy` i `grupa-docelowa` zarejestrowane w `cpt/22-cpt-osrodki.php`.
- `workers/ai-agent/src/routes/chat.ts` ma `isCrisis()` (l. 47-53) zwracający SSE event `crisis: true` (l. 783-784) — frontend już otrzymuje sygnał.
- `web/app/themes/niepodzielni-theme/app/View/Composers/App.php` — wzorzec composera z `protected static $views = ['*']`.
- `web/app/mu-plugins/niepodzielni-core/api/40-opinie-api.php` — rating w `comment_meta '_rating'`, agregat w `post_meta '_average_rating'` + `_reviews_count` (funkcja `np_reviews_recalculate_rating`, l. 175-190).
- `admin/7-admin-settings.php` — WP Settings API + helper `$env_field()` (env priorytet nad opcją).
- `api/50-forms-api.php` — istnieje `np_verify_turnstile()` (do reuse).
- Brak Action Scheduler — przy potrzebie cron'u dla #4 użyjemy natywnego `wp_schedule_event` lub systemowego cron Trellis.
- `vite.config.js` w temacie — Laravel Vite plugin, dodawanie entry point przez tablicę `input`.

---

## #2 — Crisis Help Hub

**Cel**: dedykowana strona `/pomoc-w-kryzysie` z numerami alarmowymi, mapą interwencyjną (psychomapa filtrowana po nowym termie taksonomii), checklistą „co zrobić teraz" i przyciskiem „Ukryj stronę". AI chat przy detekcji kryzysu wskazuje link do tej strony.

### Kroki (sekwencja)

1. **Dodaj nowy term taksonomii `rodzaj-pomocy`**: `interwencja-kryzysowa` (slug). Implementacja: hook `init` w nowym `mu-plugins/niepodzielni-core/api/63-crisis-setup.php` z `wp_insert_term('Interwencja kryzysowa', 'rodzaj-pomocy', ['slug' => 'interwencja-kryzysowa'])` (idempotentne — `term_exists` check).

2. **Rozszerz `api/21-psychomapa-endpoint.php`** o akceptację query param `?rodzaj_pomocy=interwencja-kryzysowa` — sprawdź czy już to wspiera (z eksploracji wynika że tak, przez slug); jeśli wymaga modyfikacji, dodać filtrację po slug w istniejącej logice. Klucz cache rozszerz o hash filtrów (`np_psychomapa_<md5(filters)>`) żeby nie zatruwać cache globalnego.

3. **Utwórz template Sage**: `web/app/themes/niepodzielni-theme/resources/views/template-pomoc-kryzys.blade.php` z headerem `{{-- Template Name: Pomoc w kryzysie --}}`. Sekcje:
   - Hero (czerwony banner, h1 „Potrzebujesz pomocy teraz?"),
   - 3 numery alarmowe — duże tap-targety, `tel:` linki: **116 123** (Telefon Zaufania dla Dorosłych), **112** (alarmowy), **116 111** (TZdD),
   - Checklist „Co możesz zrobić teraz" (4-5 punktów, jasny język),
   - Mapa Leaflet — reuse partial mapy z psychomapy z filtrem `rodzaj_pomocy=interwencja-kryzysowa`,
   - Disclaimer prawny (Fundacja nie jest jednostką medyczną),
   - Stopka „Ukryj tę stronę" + kombinacja Esc.

4. **JS**: nowy `web/app/themes/niepodzielni-theme/resources/js/crisis-hide.js`:
   - Listener `keydown` na `Escape` → `history.replaceState({}, '', '/')` + `window.location.replace('https://www.google.com')`,
   - Przycisk z aria-label `<button data-crisis-hide>` wywołujący to samo,
   - Komunikat (visually-hidden) instruujący o klawiszu Esc.
   - **Edge case**: nie używamy `history.go(-1)` (cofnięcie do poprzedniej strony może być właśnie psychomapa kryzysowa); zamiast tego pełny redirect.

5. **Vite entry**: dodaj `'resources/js/crisis-hide.js'` do `input` w `vite.config.js`. Enqueue tylko na template Pomoc w kryzysie — w `app/setup.php` warunek `is_page_template('views/template-pomoc-kryzys.blade.php')` lub equivalent.

6. **AI Worker — banner kryzysowy**: w `workers/ai-agent/src/routes/chat.ts` (l. 783-784) gdzie zwracany jest event `crisis: true` — dodaj do payloadu pole `crisis_url: 'https://niepodzielni.com/pomoc-w-kryzysie/'` (lub z env var). Frontend `resources/js/components/ai-chat.js` (już słucha `crisis: true`) — dodaj rendering bannera z linkiem.
   - Domena z `wrangler.toml` → nowy env var `WP_CRISIS_URL` (production: `https://niepodzielni.com/pomoc-w-kryzysie`).

7. **Sticky banner globalny (opcjonalnie, na MVP nie)**: nie dodajemy w tym sprincie, decyzja po feedbacku.

### Zakres techniczny — pliki

| Plik | Akcja |
|---|---|
| `web/app/mu-plugins/niepodzielni-core/api/63-crisis-setup.php` | Nowy — wpis termu, autoload przez bedrock-autoloader |
| `web/app/mu-plugins/niepodzielni-core/api/21-psychomapa-endpoint.php` | Rozszerz cache key o hash filtrów |
| `web/app/themes/niepodzielni-theme/resources/views/template-pomoc-kryzys.blade.php` | Nowy template |
| `web/app/themes/niepodzielni-theme/resources/views/partials/crisis-hide-button.blade.php` | Nowy partial |
| `web/app/themes/niepodzielni-theme/resources/views/partials/crisis-numbers.blade.php` | Nowy partial (3 numery alarmowe) |
| `web/app/themes/niepodzielni-theme/resources/views/partials/crisis-checklist.blade.php` | Nowy partial |
| `web/app/themes/niepodzielni-theme/resources/js/crisis-hide.js` | Nowy JS |
| `web/app/themes/niepodzielni-theme/resources/css/templates/crisis.css` | Nowy CSS (czerwony branding, accessible kontrast) |
| `web/app/themes/niepodzielni-theme/vite.config.js` | Dodaj entry |
| `web/app/themes/niepodzielni-theme/app/setup.php` | Conditional enqueue |
| `workers/ai-agent/src/routes/chat.ts` | Dodaj `crisis_url` do payload |
| `workers/ai-agent/wrangler.toml` | Env `WP_CRISIS_URL` |
| `web/app/themes/niepodzielni-theme/resources/js/components/ai-chat.js` | Render bannera kryzysowego |

### Strona w WP

Po deploy: WP Admin → Strony → Dodaj nową → Tytuł „Pomoc w kryzysie" → Slug `pomoc-w-kryzysie` → Atrybuty strony → Szablon „Pomoc w kryzysie".

### Edge cases

- Esc na inputach (chat, formularz) — listener z `e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA'` warunkiem.
- Brak ośrodków interwencyjnych w bazie → mapa pokazuje tylko numery + tekstowy fallback „Najbliższy oddział: zadzwoń pod 112".
- Cache psychomapy — invalidate po dodaniu/edycji ośrodka z termem `interwencja-kryzysowa`. Hook już istnieje (`save_post_osrodek_pomocy`), trzeba sprawdzić że obejmuje wszystkie warianty cache key.

### Testy

- **Pest** `tests/Feature/CrisisPageTest.php`: GET `/pomoc-w-kryzysie/` zwraca 200, zawiera ciągi `116 123`, `112`, `data-crisis-hide`.
- **Pest** `tests/Feature/PsychomapaCrisisFilterTest.php`: GET `/wp-json/niepodzielni/v1/psychomapa?rodzaj_pomocy=interwencja-kryzysowa` zwraca tylko ośrodki z tym termem.
- **Vitest** `tests/crisis-hide.test.js`: symulacja `keydown Escape` poza inputem → wywoła `location.replace`. Mock `window.location` przez jsdom.

### Smoke test E2E

1. `docker compose up -d`.
2. WP Admin → Strony → utwórz „Pomoc w kryzysie" z szablonem.
3. WP Admin → Ośrodki → dodaj testowy ośrodek z termem `interwencja-kryzysowa`.
4. Otwórz `/pomoc-w-kryzysie/` → widzisz numery + ośrodek na mapie.
5. Klawisz Esc → przekierowanie na google.com.
6. AI chat → wpisz frazę kryzysową (z `CRISIS_PHRASES`) → banner z linkiem do `/pomoc-w-kryzysie`.

### Estymacja: **2-3 dni**

---

## #5 — Public proof (wall of impact)

**Cel**: sekcja na home + `/o-nas` z licznikami: psycholodzy w sieci, artykuły psychoedukacyjne, grupy wsparcia w tym miesiącu, średnia ocena Y.Y. Cache 1h Redis. JS countup animation przy intersection observer.

### Kroki

1. **Nowy View Composer** `web/app/themes/niepodzielni-theme/app/View/Composers/PublicStats.php`:
   - extends `Roots\Acorn\View\Composer`,
   - `protected static $views = ['partials.wall-of-impact']` (composer wstrzykuje się tylko przy renderze tego partiala),
   - metoda publiczna `stats(): array` z agregatami (czytelna w blade jako `{{ stats()['psychologists'] }}`).

2. **Logika agregacji** w `PublicStats::stats()`:
   - **Cache**: `wp_cache_get('np_public_stats', 'np_stats')`; jeśli miss — oblicz, `wp_cache_set(... HOUR_IN_SECONDS)`.
   - **Psychologowie**: `wp_count_posts('psycholog')->publish`.
   - **Artykuły**: na razie `wp_count_posts('post')->publish` (dopóki #7 z planu głównego nie wprowadzi CPT psychoedu — wtedy zmiana na `artykul_psychoedu`).
   - **Grupy wsparcia w tym miesiącu**: `WP_Query` po CPT `grupy_wsparcia` z meta query `data >= start_of_month`.
   - **Średnia ocena**: SQL `SELECT AVG(CAST(meta_value AS DECIMAL(3,1))) FROM wp_postmeta WHERE meta_key = '_average_rating' AND meta_value > 0` (post_meta jest aktualizowany przez `np_reviews_recalculate_rating`).
   - **Liczba opinii**: `SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM wp_postmeta WHERE meta_key = '_reviews_count'`.

3. **Cache invalidation**:
   - Hook `save_post_psycholog` → `wp_cache_delete('np_public_stats', 'np_stats')`.
   - Hook `save_post_grupy_wsparcia` → tak samo.
   - Hook `wp_insert_comment` z `comment_type === 'review'` → tak samo.
   - W kodzie: jeden helper `np_public_stats_invalidate()` w `api/71-public-stats.php`, podpięte przez `add_action`.

4. **Blade partial** `web/app/themes/niepodzielni-theme/resources/views/partials/wall-of-impact.blade.php`:
   - 4 karty z liczbami (`<span data-countup="X">0</span>`),
   - opisy pod liczbami,
   - aria-live="polite" na sekcji,
   - klasy Tailwind (zachowanie design system z istniejących sekcji home).

5. **JS countup** `web/app/themes/niepodzielni-theme/resources/js/components/countup.js`:
   - czysty IntersectionObserver, bez bibliotek,
   - na `intersect` startuje requestAnimationFrame ramp 1.5s easing-out z `0` do `data-countup`,
   - respektuje `prefers-reduced-motion: reduce` → ustawia od razu finalną wartość.

6. **Vite entry**: dodaj `'resources/js/components/countup.js'` do `vite.config.js`.

7. **Wykorzystanie partiala**: `@include('partials.wall-of-impact')` w `views/template-home.blade.php` i `views/template-o-nas.blade.php` (jeśli istnieją; jeśli nie — w `front-page.blade.php` i page-about).

8. **Endpoint REST opcjonalnie** — `/wp-json/niepodzielni/v1/stats/public` w nowym `api/71-public-stats.php` — przydatne gdy partial będzie konsumowany przez JS dynamiczny w innych miejscach (np. embeds dla mediów). Na MVP wystarczy View Composer; endpoint dorzucić jako bonus jeśli czas zostanie.

### Zakres techniczny — pliki

| Plik | Akcja |
|---|---|
| `web/app/themes/niepodzielni-theme/app/View/Composers/PublicStats.php` | Nowy composer |
| `web/app/themes/niepodzielni-theme/resources/views/partials/wall-of-impact.blade.php` | Nowy partial |
| `web/app/themes/niepodzielni-theme/resources/js/components/countup.js` | Nowy JS |
| `web/app/themes/niepodzielni-theme/resources/css/molecules/wall-of-impact.css` | Nowy CSS |
| `web/app/themes/niepodzielni-theme/vite.config.js` | Dodaj entry |
| `web/app/mu-plugins/niepodzielni-core/api/71-public-stats.php` | Nowy plik z helperem invalidacji + opcjonalnym REST endpoint |

### Edge cases

- Brak opinii w bazie → `avg_rating = null`; partial ukrywa kartę (nie pokazuje „0.0").
- Bardzo świeża instalacja → wszystkie liczby = 0 → partial pokazuje fallback „Dopiero startujemy" (admin-toggleable).
- SQL AVG na pustym zbiorze → zwraca `NULL`; obsłuż przez `?? 0`.
- Cache invalidation po edycji opinii — hook `wp_set_comment_status` (przy zmianie statusu na approved/spam).

### Testy

- **Pest** `tests/Unit/PublicStatsTest.php`: utwórz testowe posty (3 psycholog, 2 grupy z datą future, 1 review z rating 4), wywołaj `(new PublicStats)->stats()`, zweryfikuj liczby.
- **Pest** `tests/Unit/PublicStatsCacheTest.php`: pierwsze wywołanie liczy z DB, drugie z cache; po `np_public_stats_invalidate()` ponownie liczy.
- **Vitest** `tests/countup.test.js`: animacja respektuje `prefers-reduced-motion`.

### Smoke test E2E

1. Otwórz home → sekcja Wall of impact pokazuje 4 liczby, animacja countup po wjeździe w viewport.
2. WP Admin → dodaj nowego psychologa → sprawdź `redis-cli GET wp:np_public_stats:np_stats` (lub WP `wp_cache_get`) → klucz NIE istnieje (invalidated). Reload home → liczba +1.
3. `prefers-reduced-motion: reduce` → liczby pokazują się od razu, bez animacji.

### Estymacja: **2 dni**

---

## #4 — Fundraising + Stripe

**Cel**: blok Carbon `np/donate` z 3 trybami (PIT 1.5%, Stripe Checkout one-off, Stripe Subscription). Strona `/wesprzyj`. Sticky CTA w stopce w sezonie PIT (luty-kwiecień). Webhook Stripe → zapis w `wp_np_donations`.

### Kroki

1. **Composer**:
   ```
   composer require stripe/stripe-php:^16.0 dompdf/dompdf:^3.0
   ```
   `dompdf` jest pure-PHP, lekki, nie wymaga rozszerzeń. Stripe SDK 16.x kompatybilne z PHP 8.1+.

2. **Migracja DB** — tabela `wp_np_donations`:
   ```
   CREATE TABLE {prefix}np_donations (
     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     stripe_event_id VARCHAR(255) NULL UNIQUE,
     stripe_payment_intent_id VARCHAR(255) NULL,
     stripe_subscription_id VARCHAR(255) NULL,
     stripe_customer_id VARCHAR(255) NULL,
     type ENUM('one_off','subscription','pit_15') NOT NULL,
     amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
     currency CHAR(3) NOT NULL DEFAULT 'PLN',
     email VARCHAR(255) NULL,
     name VARCHAR(255) NULL,
     status VARCHAR(40) NOT NULL DEFAULT 'pending',
     metadata JSON NULL,
     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     INDEX idx_status (status),
     INDEX idx_email (email),
     INDEX idx_created (created_at)
   ) {charset_collate};
   ```
   Implementacja: `dbDelta()` na hooku activation mu-plugin (helper `np_donations_install()` z `register_activation_hook` / `add_action('plugins_loaded', ...)` z guard `get_option('np_donations_db_version') !== '1.0'`).

3. **Admin Settings — sekcja Stripe** w `admin/7-admin-settings.php`:
   - `np_stripe_publishable_key` (env: `NP_STRIPE_PUBLISHABLE_KEY`),
   - `np_stripe_secret_key` (env: `NP_STRIPE_SECRET_KEY`),
   - `np_stripe_webhook_secret` (env: `NP_STRIPE_WEBHOOK_SECRET`),
   - `np_pit_season_active` (boolean toggle),
   - `np_fundacja_krs` (string).
   Reuse `$env_field()` helper.

4. **Klasa `Niepodzielni\Donations\StripeClient`** (`web/app/mu-plugins/niepodzielni-core/src/Donations/StripeClient.php`):
   - Konstruktor czyta secret key z env/option,
   - Metody: `createCheckoutSessionOneOff(int $amountCents, string $email, array $metadata): string` (zwraca URL), `createCheckoutSessionSubscription(int $amountCents, string $email): string`, `verifyWebhookSignature(string $payload, string $sigHeader): \Stripe\Event`.
   - Throws `DonationsApiException` przy błędach Stripe.
   - **PSR-4**: dodaj namespace `Niepodzielni\\Donations\\` do `composer.json` autoload, `composer dump-autoload`.

5. **REST API endpointy** (`web/app/mu-plugins/niepodzielni-core/api/70-donations-api.php`):
   - `POST /niepodzielni/v1/donations/checkout` — body `{type: 'one_off'|'subscription', amount_cents, email, name, cf-turnstile-response}` → walidacja → `StripeClient::createCheckoutSession*` → INSERT do `wp_np_donations` (status `pending`) → zwraca `{checkout_url, donation_id}`. Reuse `np_verify_turnstile()`.
   - `POST /niepodzielni/v1/donations/webhook` — Stripe webhook (no auth, weryfikacja przez signature):
     - `payment_intent.succeeded` → UPDATE `status='succeeded'`, ustaw `stripe_event_id` (idempotency),
     - `customer.subscription.created` / `invoice.payment_succeeded` → analogicznie,
     - Wyślij email „Dziękujemy" przez `wp_mail` (HTML; pattern z `BaseFormHandler`).
     - Idempotency: zanim INSERT/UPDATE, sprawdź `WHERE stripe_event_id = ?` — jeśli istnieje, zwróć 200 bez działania.
   - `POST /niepodzielni/v1/donations/pit-pdf` — body `{name?, amount?}` → generuje PDF instrukcji wypełnienia PIT z numerem KRS fundacji → zwraca PDF jako attachment download. **Bez Stripe** — czysty dompdf z templatem HTML.

6. **Blade page template** (zgodnie z konwencją projektu — wszystko w widokach Blade, bez Gutenberga, bez shortcode'ów):
   - `web/app/themes/niepodzielni-theme/resources/views/template-wesprzyj.blade.php` z headerem `{{-- Template Name: Wesprzyj nas --}}` — `@extends('layouts.app')` + `@section('content')` + `@include('partials.donate-block', ['mode' => 'all', 'preset_amounts' => [50, 100, 200]])`.
   - `web/app/themes/niepodzielni-theme/resources/views/partials/donate-block.blade.php` — całe UI bloku darowizn:
     - 3 zakładki PIT / Jednorazowo / Co miesiąc (`@switch($mode)` jeśli admin chce ograniczyć do jednego trybu),
     - Presety kwot z `$preset_amounts`,
     - Form HTML z Turnstile (`<div class="cf-turnstile" data-sitekey="...">`),
     - `data-*` attributes do hookowania JS.
   - Partial parametryzowany — można w przyszłości reusować w innych template'ach (`/o-nas`, sekcji home itp.) bez duplikacji.

7. **Frontend JS** `web/app/themes/niepodzielni-theme/resources/js/donate.js`:
   - Hookuje się do partiala przez `[data-np-donate-root]`,
   - 3 sekcje (PIT/one-off/subscription), tabbed UI,
   - One-off + subscription: wybór kwoty (presety + custom input min 5 zł, max 50 000 zł), email, name, Turnstile widget render → POST do `/niepodzielni/v1/donations/checkout` → redirect do `checkout_url`.
   - PIT: form name + amount → POST do `/niepodzielni/v1/donations/pit-pdf` → trigger download PDF.
   - Loading states, error handling (toast), respektuje `prefers-reduced-motion`.

8. **Strona `/wesprzyj/`**:
   - WP Admin → Strony → Dodaj nową „Wesprzyj nas" → slug `wesprzyj` → Atrybuty strony → Szablon „Wesprzyj nas",
   - Treść strony pusta (cała zawartość w template'cie Blade).

9. **Sticky CTA w sezonie PIT**:
   - W `resources/views/sections/footer.blade.php` (PRZED `<footer>` lub przed bottom-bar):
     ```blade
     @if(get_option('np_pit_season_active'))
         <aside class="np-pit-sticky" aria-live="polite">
             <p>Możesz przekazać 1,5% PIT na Niepodzielnych — bez kosztów dla Ciebie.</p>
             <a href="/wesprzyj/#pit" class="btn btn-primary">Pokaż jak</a>
             <button data-pit-dismiss aria-label="Ukryj">×</button>
         </aside>
     @endif
     ```
   - Banner zapamiętuje dismissed przez 7 dni w localStorage (`np-pit-dismissed-until`).
   - CSS sticky bottom-right na desktop, full-width bottom na mobile.

10. **Email transactional** po sukcesie Stripe:
    - Pattern z `BaseFormHandler` — `wp_mail` z `wp_mail_content_type` filter.
    - Template HTML w PHP file (np. `src/Donations/templates/donation-thanks.php` — render z `extract($data)`).
    - Zawiera: kwota, data, nr darowizny, info o odpisie podatkowym (one-off > subscription wykorzystują różny tekst).

11. **Konfiguracja Stripe webhook**:
    - Stripe Dashboard → Developers → Webhooks → Add endpoint: `https://niepodzielni.com/wp-json/niepodzielni/v1/donations/webhook`,
    - Event types: `payment_intent.succeeded`, `payment_intent.payment_failed`, `customer.subscription.created`, `customer.subscription.deleted`, `invoice.payment_succeeded`, `invoice.payment_failed`.
    - Signing secret → `NP_STRIPE_WEBHOOK_SECRET` w `.env`.

### Zakres techniczny — pliki

| Plik | Akcja |
|---|---|
| `composer.json` | Add `stripe/stripe-php`, `dompdf/dompdf`, namespace `Niepodzielni\\Donations\\` |
| `web/app/mu-plugins/niepodzielni-core/src/Donations/StripeClient.php` | Nowy |
| `web/app/mu-plugins/niepodzielni-core/src/Donations/DonationsApiException.php` | Nowy |
| `web/app/mu-plugins/niepodzielni-core/src/Donations/PdfGenerator.php` | Nowy (dompdf wrapper dla PIT) |
| `web/app/mu-plugins/niepodzielni-core/src/Donations/templates/donation-thanks.php` | Nowy template emaila |
| `web/app/mu-plugins/niepodzielni-core/src/Donations/templates/pit-instruction.blade.php` (lub `.php`) | Nowy template PDF |
| `web/app/mu-plugins/niepodzielni-core/api/70-donations-api.php` | Nowy — REST routes + DB install |
| `web/app/mu-plugins/niepodzielni-core/admin/7-admin-settings.php` | Rozszerzenie o sekcję Stripe |
| `web/app/themes/niepodzielni-theme/resources/views/template-wesprzyj.blade.php` | Nowy page template |
| `web/app/themes/niepodzielni-theme/resources/views/partials/donate-block.blade.php` | Nowy partial — całe UI bloku darowizn |
| `web/app/themes/niepodzielni-theme/resources/js/donate.js` | Nowy entry point |
| `web/app/themes/niepodzielni-theme/resources/css/organisms/donate-block.css` | Nowy CSS |
| `web/app/themes/niepodzielni-theme/resources/views/sections/footer.blade.php` | Sticky PIT banner (warunkowo) |
| `web/app/themes/niepodzielni-theme/vite.config.js` | Dodaj entry |
| `.env.example` | Dodaj `NP_STRIPE_*` + `NP_FUNDACJA_KRS` |

### Bezpieczeństwo

- Webhook Stripe — weryfikacja `Stripe\Webhook::constructEvent($payload, $sigHeader, $secret)`. Bez tego endpoint przyjmuje tylko sygnowane requesty.
- Idempotency — UNIQUE index na `stripe_event_id`; INSERT … ON DUPLICATE KEY UPDATE bez zmiany.
- Min/max amount — server-side walidacja (5-50 000 zł) niezależnie od frontendu.
- Email validation — `is_email()` przed wysyłką.
- Turnstile na endpoincie `checkout` — chroni przed botami tworzącymi setki pendingów.
- Rate limit per IP — Redis incr counter `np_donate_ratelimit:<ip>` TTL 60s, max 5 / min.
- PDF generator — escape user input w templacie (`htmlspecialchars`), bo dompdf renderuje HTML.
- Logging Stripe errors — do `error_log()` + osobny WP option `np_donations_last_error` (admin widzi w settings).

### Edge cases

- User zamyka okno checkout — pending donation wisi 24h, cron sprzątający status='pending' → 'abandoned'.
- Subscription canceled przez Stripe → webhook `customer.subscription.deleted` → status='canceled'.
- Refund (kiedyś) → webhook `charge.refunded` → status='refunded'.
- Nieprawidłowa kwota custom (np. 0.01 zł) → 400 Bad Request.
- Sezon PIT (`np_pit_season_active`) wyłączony przy nieaktywnym KRS → ukryj zakładkę PIT w bloku.
- Brak konfiguracji Stripe (puste klucze) → admin notice w panelu, REST endpoint zwraca 503.

### Testy

- **Pest** `tests/Unit/Donations/StripeClientTest.php`: mock Stripe API (przez `stripe-mock` lub fakeClient w tests/), wywołaj `createCheckoutSessionOneOff` → asercje na payload.
- **Pest** `tests/Feature/DonationsCheckoutTest.php`: POST na endpoint z mock Turnstile, sprawdź że wpis pojawia się w `wp_np_donations` ze status `pending`.
- **Pest** `tests/Feature/DonationsWebhookTest.php`: POST z fake Stripe payload + signature → status updated do `succeeded`. Test idempotency: drugi POST z tym samym `stripe_event_id` nie tworzy duplikatu.
- **Pest** `tests/Feature/PitPdfTest.php`: POST → response z header `Content-Type: application/pdf`, body zaczyna od `%PDF-`.
- **Vitest** `tests/donate.test.js`: walidacja kwoty client-side, dispatch zdarzeń.

### Smoke test E2E

1. `composer install` + `composer dump-autoload`.
2. Aktywacja mu-plugin → tabela `wp_np_donations` istnieje (`SHOW TABLES`).
3. WP Admin → Settings → Niepodzielni → wpisz testowe klucze Stripe (test mode), zapisz.
4. WP Admin → Strony → utwórz „Wesprzyj nas" (slug `wesprzyj`) → Atrybuty strony → Szablon „Wesprzyj nas".
5. Frontend `/wesprzyj/`:
   - **Tryb PIT** → wpisz imię → kliknij „Pobierz instrukcję" → pobiera się PDF z KRS.
   - **One-off** → wybierz 100 zł → email + nazwa → przejście do Stripe Checkout (test mode 4242 4242 4242 4242) → po sukcesie redirect na `/wesprzyj/?status=success`.
   - **Subscription** → wybierz 50 zł/mc → ten sam flow.
6. Stripe Dashboard → Webhooks → Send test event `payment_intent.succeeded` → wpis w `wp_np_donations.status='succeeded'`.
7. Włącz `np_pit_season_active` → na każdej stronie pokazuje się sticky banner.
8. Kliknij × na banner → ukrywa się, localStorage `np-pit-dismissed-until` ma datę +7 dni.

### Estymacja: **6-9 dni roboczych**

---

## Sekwencja implementacji

| Dzień | Zadania |
|---|---|
| 1 | Setup composer (Stripe + dompdf), DB migration, scaffolding `StripeClient` |
| 2 | Admin settings, REST `/checkout` + `/pit-pdf` (bez webhooka) |
| 3 | Webhook + idempotency + email transactional |
| 4 | Carbon Fields Block `np/donate` + Blade view |
| 5 | Frontend JS donate.js + Tailwind styling + Turnstile |
| 6 | Sticky PIT banner + testy Pest+Vitest dla #4 |
| 7 | **#2** — psychomapa term, template Sage, partial numerów, Esc handler |
| 8 | **#2** — AI worker banner, testy Pest dla #2 |
| 9 | **#5** — composer PublicStats, partial wall-of-impact, countup.js, testy |
| 10 | Smoke testy E2E wszystkich 3, fix what break, PR review |

**Łącznie ~10 dni roboczych** (2 sprintowe tygodnie z buforem). Każda funkcja w osobnym commit-set, wszystko PR-em w jednym branchu (`claude/plan-new-features-essbq`).

---

## Co po akcepcie

1. Aktualizuję todo list, znaczę „Plan szczegółowy" jako completed.
2. Zaczynam od **#4 dnia 1** (composer + DB) — to jest największy blok z najwięcej zewnętrznymi zależnościami.
3. Po każdej z 3 funkcji robię oddzielny commit z `feat:` prefixem, pushuję, weryfikuję CI.
4. Końcowy push z testami + smoke test docs.
