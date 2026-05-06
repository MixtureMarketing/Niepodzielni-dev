# Plan rozbudowy Niepodzielni-dev — funkcje o najwyższej wartości biznesowej

## Kontekst

**Niepodzielni-dev** — platforma Fundacji Niepodzielni (wsparcie psychologiczne) na WordPress Bedrock + Sage 11 + Bookero + AI Worker (Cloudflare GPT-4o-mini). Repo jest dojrzałe technicznie: matchmaker, AI chat z filtrami kryzysowymi, system recenzji, psychomapa, framework formularzy.

**Zakres planu** (po decyzji użytkownika): pomijamy funkcje wymagające:
- danych po stronie WP z Bookero (brak webhooków, endpoint `getReservations` zawodny) — czekamy na **własny system rezerwacji w przygotowaniu**,
- zakładania kont użytkowników (panel pacjenta, intake form po wizycie, dashboard psychologa z kontekstem pacjenta),
- integracji telezdrowia, weryfikowanych recenzji po wizycie itp. — wszystko to wraca na stół po wdrożeniu własnego silnika rezerwacji.

Skupiamy się na funkcjach **niezależnych od stanu rezerwacji**, działających na anonimowym ruchu, na content engine, AI, fundraisingu i SEO. Maksymalny reuse istniejącej infrastruktury (`BaseFormHandler`, ScoringEngine, AI Worker, Cloudflare KV/Vectorize, Carbon Fields).

---

## TIER 1 — Quick wins (1-2 sprinty każdy)

### 1. Self-assessment quiz PHQ-9 + GAD-7 → zasilenie matchmakera
- **Wartość**: pacjent (lower friction onboarding) + fundacja (lead magnet, segmentacja anonimowa, treść SEO).
- **Flow**: nowa strona `/sprawdz-sie` → 9+7 pytań → wynik z severity bands i interpretacją (bez diagnozy, z disclaimerem) → CTA "zobacz polecanego psychologa" prefilluje krok 1 matchmakera (PHQ-9 ≥10 → "depresja", GAD-7 ≥10 → "lęk"; mapowanie na taksonomię `obszar-pomocy`). Wynik anonimowy zapisywany w `wp_np_assessments` z UUID jako tokenem (bez emaila, bez konta), opcjonalny opt-in do newslettera (#3) z magic-link „odzyskaj swój wynik”.
- **Reuse**: ScoringEngine w `web/app/themes/niepodzielni-theme/resources/js/matchmaker/` (rozszerzyć hard filter o `assessment_token`), `BaseFormHandler` (walidacja), Turnstile, State.js (sessionStorage + URL params).
- **Zakres**: nowy `api/62-assessment-api.php` (REST `POST /np/v1/assessment`), Blade `resources/views/template-assessment.blade.php` + `partials/assessment-step.blade.php`, JS `resources/js/assessment.js`, tabela `wp_np_assessments (uuid, phq9_total, gad7_total, severity, recommended_areas_json, created_at, opted_in_email)`.
- **Estymacja**: S (3-5 dni). Licencje OK (PHQ-9 public domain, GAD-7 free use Pfizer).

### 2. Crisis Help Hub — szybka pomoc + tryb prywatności
- **Wartość**: pacjent (najbardziej wrażliwy moment — kryzys) + fundacja (etyczna odpowiedzialność, SEO na zapytania kryzysowe).
- **Flow**: dedykowana strona `/pomoc-w-kryzysie`: numery alarmowe (116 123, 112, Telefon Zaufania dla Dzieci 116 111) z przyciskami `tel:` na mobile, mapa szpitali psychiatrycznych (reuse psychomapa z taksonomią `rodzaj-pomocy=interwencja`), checklist „co zrobić teraz”, **przycisk „Ukryj stronę”** (klawisz Esc → przekierowanie na google.com, czyści historię). AI chat już ma detekcję kryzysu (`workers/ai-agent/src/routes/chat.ts:783`, 40+ fraz PL) — przy kryzysie wpinamy banner z linkiem do tej strony zamiast wyciszać konwersację.
- **Reuse**: psychomapa endpoint (`api/21-psychomapa-endpoint.php`) z filtrem `rodzaj-pomocy`, AI Worker crisis filter, istniejące taksonomie CPT `osrodek_pomocy`.
- **Zakres**: nowa taksonomia term `interwencja-kryzysowa` w `rodzaj-pomocy`, Blade `template-crisis.blade.php`, JS `resources/js/crisis-hide.js` (Esc handler + history.replaceState), modyfikacja `workers/ai-agent/src/routes/chat.ts` w handle kryzysu (link do `/pomoc-w-kryzysie`).
- **Estymacja**: S (2-3 dni). **Najwyższy priorytet etyczny.**

### 3. Newsletter MailerLite + segmentacja anonimowa
- **Wartość**: fundacja (owned channel, niższy CAC, retencja edukacyjna).
- **Flow**: opt-in widget w stopce, `ContactForm`, matchmakerze (po wynikach), assessment (#1). Tagi: `lead_kontakt`, `lead_matchmaker_<obszar>`, `lead_assessment_<severity>`, `lead_psychoedu_<tag>`. Confirmacja double opt-in. Backend wysyła zdarzenia do MailerLite REST.
- **Reuse**: `src/Forms/BaseFormHandler.php`, `src/Bookero/BookeroApiClient.php` jako wzorzec klienta HTTP (skopiować nie współdzielić — Bookero wyleci docelowo).
- **Zakres**: nowa klasa `src/Newsletter/MailerLiteClient.php`, hook `np_newsletter_subscribe` z fallbackiem na lokalny zapis przy błędzie API (kolejka retry przez Action Scheduler), opcje w `admin/7-admin-settings.php` (API key, default tag).
- **Estymacja**: S (3 dni). **Wymaga konta MailerLite (free plan do 1k subs).**

### 4. Fundraising — 1.5% PIT + darowizny one-off + miesięczne wsparcie
- **Wartość**: fundacja (nowy strumień przychodu niezależny od sesji, sezonowy peak luty-kwiecień).
- **Flow**: blok Gutenberg/Carbon `np/donate` z 3 trybami:
  - (a) **1.5% PIT** — generator PDF z numerem KRS (`<?php echo NP_KRS; ?>`), kopiuj-do-schowka, instrukcja wypełnienia w wybranych formatach (PIT-37, PIT-36, PIT-28),
  - (b) **Stripe Checkout one-off** (50/100/200 zł + custom),
  - (c) **Stripe Subscription** "Wspieram co miesiąc" (gość lub email — bez konta WP).
  Strona `/wesprzyj`, sticky CTA w stopce w sezonie PIT (luty-kwiecień przez setting `np_pit_season_active`).
- **Reuse**: `BaseFormHandler` + Turnstile, klient HTTP wzorowany na `BookeroApiClient`.
- **Zakres**: `api/70-donations-api.php` (webhook Stripe `payment_intent.succeeded`, `customer.subscription.created`), `src/Donations/StripeClient.php`, tabela `wp_np_donations (id, stripe_intent_id, amount, currency, type ENUM('one_off','subscription','pit_15'), email, recurring, created_at, status)`, Blade `partials/donate-widget.blade.php`, generator PDF przez `dompdf` (już w `composer.json`? jeśli nie — lekki TCPDF).
- **Estymacja**: M (1-2 tyg). **Wymaga konta Stripe + danych fundacji do PIT.**

### 5. Public proof — wall of impact (live counters)
- **Wartość**: fundacja. KPI: konwersja (social proof +10-20% CTR na CTA).
- **Flow**: sekcja na home + `/o-nas`: "X psychologów w sieci / Y artykułów psychoedukacyjnych / Z grup wsparcia w tym miesiącu / N zł zebranych w tym roku (z #4) / średnia ocena Y.Y (z `comment_meta` opinii)". Cache 1h Redis, JS countup animation (intersection observer).
- **Reuse**: pattern cache z `api/21-psychomapa-endpoint.php` (object cache 6h), View Composers w `app/View/Composers/`.
- **Zakres**: endpoint `GET /np/v1/stats/public` w nowym `api/71-public-stats.php` (agregat: `wp_count_posts(psycholog)`, `wp_count_posts(post)`, sum `wp_np_donations`, AVG comment rating), View Composer `App\View\Composers\PublicStats`, Blade `partials/wall-of-impact.blade.php`, JS `resources/js/components/countup.js`.
- **Estymacja**: S (2-3 dni). Działa od dnia 1 — bez zewnętrznych zależności.

### 6. SEO — Schema.org rich snippets dla psychologów + psychomapy
- **Wartość**: fundacja (organic traffic; Google rich results dla zapytań typu "psycholog Warszawa lęki" zwiększają CTR ~20%).
- **Flow**: w `single-psycholog.blade.php` JSON-LD `Person` + `MedicalBusiness` + `AggregateRating` (z opinii). W `single-osrodek_pomocy.blade.php` JSON-LD `LocalBusiness` z geo i `openingHours`. W psychoedukacji (#7) `Article` + `BreadcrumbList`.
- **Reuse**: `app/seo.php` (już istnieje, dorzucić emit JSON-LD), Carbon Fields psychologa.
- **Zakres**: rozszerzenie `app/seo.php` o emiter JSON-LD per CPT, helper `np_render_json_ld(array)`. Walidacja w Google Rich Results Test.
- **Estymacja**: S (2 dni). **Czysty SEO win bez zewnętrznych usług.**

---

## TIER 2 — Strategic (1-2 miesiące każdy)

### 7. Hub psychoedukacyjny — CPT artykułów + AI rekomendacje treści
- **Wartość**: pacjent (edukacja, dłuższy time-on-site) + fundacja (autorytet, organic traffic, retencja).
- **Flow**: nowy CPT `artykul_psychoedu` z taksonomiami `obszar-pomocy` (współdzielone z psychologiem) i `poziom` (poczatkujacy/sredni/zaawansowany). Po publikacji hook `save_post` → POST do worker `/sync/articles` → embedding BGE-M3 → Vectorize index `np-articles`. Widget "Polecane dla Ciebie": (a) na końcu artykułu (similarity), (b) na stronie matchmakera po wynikach (z entities z odpowiedzi), (c) po wyniku assessment (#1). Newsletter (#3) auto-pulluje 3 nowe artykuły tygodniowo per segment tagów.
- **Reuse**: `workers/ai-agent/src/routes/sync.ts` (BGE-M3 + Vectorize, l. 574+), `routes/search.ts`.
- **Zakres**: `cpt/24-cpt-psychoedu.php`, hook `save_post_artykul_psychoedu`, nowy index Vectorize `np-articles` (binding w `wrangler.toml`), endpoint worker `POST /search/articles`, shortcode `[np_recommended type="article" topic="..."]`, Blade `partials/article-recommendations.blade.php`.
- **Estymacja**: M (2-3 tyg).

### 8. AI chat z pamięcią sesji (KV) + extraction entities → matchmaker
- **Wartość**: pacjent (UX kontynuacji rozmowy między wizytami na stronie) + fundacja (konwersja chat→matchmaker).
- **Flow**: worker generuje `chat_session_id` (UUID), zapis w cookie HttpOnly + Cloudflare KV (`AI_CHAT:{id}` TTL 30 dni). Sliding window 20 wiadomości + auto-summary co 10 wiadomości (osobny LLM call do summarization, zapis do `AI_CHAT:{id}:summary`). Tool `recommend_resources` rozszerzony o extraction entities (`obszar`, `tryb`, `cena_max`) → link do matchmakera z prefillem `?area=...&mode=...&max_price=...`. Anonimowo, bez konta. Klient może w UI kliknąć „rozpocznij nową rozmowę” = czyści cookie.
- **Reuse**: `workers/ai-agent/src/routes/chat.ts` (Intent Fast Track, l. 824), istniejące binding KV (jeśli jest — sprawdzić w `wrangler.toml`), system prompt (l. 158).
- **Zakres**: KV binding `CHAT_SESSIONS` w `wrangler.toml` (jeżeli jeszcze brak), helper `workers/ai-agent/src/lib/sessionStore.ts` (`get/put/summarize`), endpoint `GET /chat/session/:id` (read-only, dla recovery), modyfikacja `routes/chat.ts` (load history → append → save → summarize-if-needed). Frontend `resources/js/components/ai-chat.js` (już istnieje) — czytanie `chat_session_id` z cookie.
- **Estymacja**: M (2 tyg). **Wymaga: KV binding na Cloudflare (free tier wystarcza ~100k reads/day).**

### 9. Matchmaker rozbudowa — porównaj psychologów + eksport PDF + shareable link
- **Wartość**: pacjent (decyzja oparta na danych, niższy decision fatigue) + fundacja (mierzalna konwersja na profil psychologa).
- **Flow**:
  - **Compare** — w wynikach matchmakera checkbox "Porównaj" (max 3) → strona `/porownaj?ids=12,45,78` z tabelą side-by-side: dopasowanie %, najwcześniejszy termin, cena, obszary (z highlightem matchowanych), nurt, opinia (avg rating), lokalizacje.
  - **Export PDF** — przycisk "Pobierz raport" generuje PDF z odpowiedziami quizu + top 3 psychologami + datą — pacjent może to wziąć do swojego lekarza rodzinnego.
  - **Shareable link** — wynik zapisany w `wp_np_match_sessions` (anonimowo, UUID); link `/matchmaker/wynik/{uuid}` przywraca wyniki (bez emaila, bez konta).
- **Reuse**: cały `resources/js/matchmaker/` (ScoringEngine, State, Templates), `api/15-matchmaker-shortcode.php`, `dompdf` z #4.
- **Zakres**: tabela `wp_np_match_sessions (uuid, answers_json, top_psychologists_json, created_at)`, `api/61-matchmaker-api.php` (`POST /sessions`, `GET /sessions/:uuid`), Blade `template-compare.blade.php`, JS `resources/js/matchmaker/Compare.js`, generator PDF.
- **Estymacja**: M (2 tyg).

### 10. Wydarzenia & warsztaty — kalendarz + iCal subscribe + email-share (bez rezerwacji)
- **Wartość**: pacjent (powiadomienia o nowych warsztatach) + fundacja (frekwencja, frequency wizyt na stronie).
- **Flow**: na listing CPT `wydarzenia` i `warsztaty` dodaj widok kalendarza miesięcznego (Blade + JS, Tailwind). Każde wydarzenie ma:
  - przycisk **„Dodaj do mojego kalendarza”** (.ics file download — Google/Apple/Outlook),
  - przycisk **„Subskrybuj ten cykl”** (URL `/feed/wydarzenia.ics` — webcal:// subscribe, klient kalendarza odświeża sam),
  - **email-share** ("powiadom mnie 1 dzień wcześniej") — opt-in bez konta, zapis tylko email + event_id, jednorazowy cron-mail przed wydarzeniem.
- **Reuse**: `cpt/17-cpt-wydarzenia.php`, `cpt/18-cpt-warsztaty.php`, View Composer `TemplateWydarzenia`.
- **Zakres**: endpoint `GET /feed/wydarzenia.ics` (RFC 5545, lekka implementacja inline), `api/72-events-reminders.php` (POST opt-in, cron `np_send_event_reminders`), tabela `wp_np_event_reminders (id, email, event_post_id, sent)`, Blade `partials/calendar-month.blade.php`, JS `resources/js/components/event-calendar.js`.
- **Estymacja**: M (1-2 tyg). **Bez płatności i kont — fundacja decyduje o rezerwacjach offline / przez Google Forms aktualnie.**

### 11. Anonimowy „mój zeszyt” w localStorage — ulubieni psycholodzy + historia quizów
- **Wartość**: pacjent (powrót do strony z zachowaniem kontekstu, bez rejestracji) + fundacja (niższe odbicie powracających).
- **Flow**: gwiazdka „dodaj do moich” na karcie psychologa → zapis ID w `localStorage`. Strona `/moj-zeszyt` (czysty JS, bez backendu) pokazuje:
  - Ulubionych psychologów (live odczyt z REST `GET /wp/v2/psycholog?include=...`),
  - Ostatnie wyniki quizów (#1) i matchmakera (#9) — UUID z localStorage,
  - „Eksportuj jako JSON / wyślij na email” (jednorazowy mail przez `BaseFormHandler` + Turnstile).
- **Reuse**: REST API WP (już out-of-the-box), `api/61-matchmaker-api.php` z #9, `BaseFormHandler` do email.
- **Zakres**: Blade `template-moj-zeszyt.blade.php`, JS `resources/js/zeszyt.js` (wszystko frontend), endpoint `POST /np/v1/zeszyt/export-email` (jednorazowy).
- **Estymacja**: S (3-5 dni). **Zero PII server-side dopóki user nie kliknie „wyślij na email”.**

---

## TIER 3 — Long-term (kwartał+)

### 12. Newsletter content automation — AI tworzy tygodniowy digest
- **Wartość**: fundacja (skalowanie content marketing bez dodatkowej pracy redakcyjnej).
- **Flow**: cron tygodniowy: bierze 3 nowe artykuły z #7, dla każdego segmentu (#3) generuje przez worker LLM krótki teaser (1 zdanie + CTA), buduje email HTML, wysyła kampanię przez MailerLite Campaigns API. Redakcja akceptuje jednym kliknięciem w admin panelu (queue review).
- **Reuse**: worker AI Gateway, `MailerLiteClient` z #3, CPT psychoedu z #7.
- **Zakres**: nowy worker route `POST /digest/generate`, `api/73-digest-queue.php` (admin UI z queue), Action Scheduler weekly cron.
- **Estymacja**: M (2-3 tyg). **Wymaga: #3, #7.**

### 13. Wsparcie psychologów na zewnątrz — toolkit dla zarejestrowanych
- **Wartość**: psycholog (retencja talentu, mniej rotacji) + fundacja (employer branding).
- **Flow**: rozszerzenie `panel-psycholog` o sekcję „Materiały dla mnie”: pobieralne szablony social media (Canva templates), badge HTML „Psycholog Niepodzielnych” do wstawienia na własną stronę, pobieralny QR code do swojego profilu, statystyki widoków profilu (z `wp_np_profile_views` — nowa tabela inkrementowana przez nieblokujący wp_remote_post na single post template).
- **Reuse**: `api/30-panel-psycholog.php` ACL i widoki, Carbon Fields psychologa.
- **Zakres**: tabela `wp_np_profile_views (id, post_id, day, views_count UNIQUE(post_id,day))`, hook `wp_footer` na single-psycholog z lekkim AJAX inkrementem, sekcja w panelu (Blade), generator QR code (biblioteka `endroid/qr-code` przez Composer).
- **Estymacja**: M (2 tyg). **Bez integracji rezerwacji — to czeka na własny system.**

### 14. Accessibility audit + WCAG 2.1 AA fixes
- **Wartość**: pacjent (a11y to konieczność dla osób z niepełnosprawnościami, w tym z zaburzeniami psychicznymi) + fundacja (compliance + ETSI EN 301 549 wymóg dla podmiotów otrzymujących środki publiczne).
- **Flow**: audit narzędziem axe-core (Vitest plugin) + manual screen reader (NVDA/VoiceOver) na 5 kluczowych szablonach (home, single-psycholog, matchmaker, contact form, psychomapa). Fix listy braków z `docs/plan.md` (l. 51, 52): ARIA labels w filtrach, alt na obrazach hero, kontrast WCAG AA, focus visible, keyboard nav, redukcja motion (`prefers-reduced-motion`).
- **Reuse**: testy Vitest (już skonfigurowane), Tailwind a11y plugins.
- **Zakres**: nowa sekcja w teście `web/app/themes/niepodzielni-theme/tests/e2e/a11y.spec.js`, fixy w Blade i CSS.
- **Estymacja**: M (2-3 tyg).

---

## Rekomendowana sekwencja sprintów

| Okres | Funkcje | Cel biznesowy |
|---|---|---|
| Sprint 1 (2 tyg) | #2 Crisis Hub + #5 Public proof + #6 Schema SEO | Etyka + social proof + organic traffic od dnia 1 |
| Sprint 2 (2 tyg) | #1 Assessment + #3 Newsletter | Lead generation z owned channelem |
| Sprint 3 (2 tyg) | #4 Fundraising | Domknięcie monetyzacji przed/w sezonie PIT |
| Miesiąc 2 | #8 AI memory + #9 Matchmaker compare/export | Retention loop pacjenta |
| Miesiąc 3 | #7 Hub psychoedu + #11 Mój zeszyt | Content engine + powracający users |
| Q+1 | #10 Events kalendarz + #12 Newsletter automation + #13 Toolkit psy + #14 a11y | Operacje, skalowanie, compliance |

---

## Kluczowe pliki — reuse / rozszerzenie / nowe

**Reuse bez modyfikacji:**
- `web/app/mu-plugins/niepodzielni-core/src/Forms/BaseFormHandler.php` — wzorzec walidacji REST + OTP + Turnstile
- `web/app/mu-plugins/niepodzielni-core/src/Forms/CommonFields.php` — wspólne pola formularzy
- `web/app/mu-plugins/niepodzielni-core/api/21-psychomapa-endpoint.php` — pattern cache 6h
- `web/app/mu-plugins/niepodzielni-core/admin/9-psycholog-role.php` — capabilities

**Rozszerzane:**
- `web/app/themes/niepodzielni-theme/app/seo.php` — emit JSON-LD (#6)
- `web/app/themes/niepodzielni-theme/resources/js/matchmaker/` — Compare.js i prefill z assessment (#1, #9)
- `web/app/themes/niepodzielni-theme/resources/js/components/ai-chat.js` — sesja z cookie (#8)
- `workers/ai-agent/src/routes/chat.ts` — pamięć sesji KV (#8)
- `workers/ai-agent/wrangler.toml` — KV binding `CHAT_SESSIONS`, Vectorize binding `np-articles` (#7)
- `workers/ai-agent/src/routes/sync.ts` — sync artykułów psychoedukacyjnych (#7)
- `web/app/mu-plugins/niepodzielni-core/api/30-panel-psycholog.php` — sekcja materiałów dla psychologa (#13)
- `web/app/themes/niepodzielni-theme/resources/views/sections/footer.blade.php` — sticky CTA fundraising w sezonie (#4)

**Nowe pliki / katalogi:**
- `api/62-assessment-api.php` (#1), `api/72-events-reminders.php` (#10), `api/70-donations-api.php` (#4), `api/71-public-stats.php` (#5), `api/61-matchmaker-api.php` (#9), `api/73-digest-queue.php` (#12)
- `src/Newsletter/MailerLiteClient.php` (#3), `src/Donations/StripeClient.php` (#4)
- `cpt/24-cpt-psychoedu.php` (#7)
- `workers/ai-agent/src/lib/sessionStore.ts` (#8)
- Blade: `template-assessment.blade.php`, `template-crisis.blade.php`, `template-compare.blade.php`, `template-moj-zeszyt.blade.php`, `partials/wall-of-impact.blade.php`, `partials/donate-widget.blade.php`, `partials/calendar-month.blade.php`, `partials/article-recommendations.blade.php`

**Nowe tabele DB:**
- `wp_np_assessments` (#1)
- `wp_np_donations` (#4)
- `wp_np_match_sessions` (#9)
- `wp_np_event_reminders` (#10)
- `wp_np_profile_views` (#13)

---

## Weryfikacja end-to-end

Po każdym sprincie:

1. **Testy automatyczne**:
   - `vendor/bin/pest --filter=<feature>` — Pest unit/feature dla nowych endpointów
   - `vendor/bin/phpstan analyse` (level 8 — already in CI)
   - `vendor/bin/pint --test`
   - `npm run test` (Vitest, w katalogu motywu)

2. **Smoke test funkcjonalny w Dockerze**: `docker compose up -d`, otwórz `http://localhost:8000`, wykonaj golden path danego sprintu.

3. **Lejek metryczny** (Cloudflare Zaraz, już skonfigurowane): zdarzenia per funkcja:
   - `assessment_completed` (#1), `crisis_page_view` (#2), `newsletter_subscribed` (#3), `donation_succeeded` (#4), `chat_session_resumed` (#8), `matchmaker_compared` (#9), `event_reminder_optin` (#10).

4. **AI Worker smoke** (po #8): `wrangler tail` — `chat_session_id` musi się utrzymywać między requestami z tego samego cookie. Test: reload strony → AI pamięta poprzednie wiadomości.

5. **SEO walidacja** (po #6): Google Rich Results Test (https://search.google.com/test/rich-results) na 1 stronie psychologa, 1 stronie ośrodka, 1 artykule psychoedukacyjnym → wszystkie powinny przejść bez błędów.

6. **A11y** (po #14): `npx axe ./test-pages.txt` w CI, `lighthouse` accessibility ≥90 na 5 kluczowych stronach.

7. **Backwards compatibility**: każdy etap NIE może zepsuć istniejącego flow Bookero ani matchmakera. Feature flagi w `wp_options` (`np_feature_*`) na ryzykowne zmiany (#8 KV memory, #6 JSON-LD).

---

## Co NIE wchodzi w ten plan (zaadresowane przez własny system rezerwacji w przygotowaniu)

- Capture rezerwacji w WP, panel pacjenta z historią wizyt, dashboard psychologa z kontekstem klienta, intake form po rezerwacji, weryfikowane recenzje "po wizycie", telezdrowie wbudowane, sign-up na warsztaty z płatnością, automatyczne przypomnienia o sesji (T-24h/T-2h).

Wszystko to wraca na stół po wdrożeniu własnego silnika rezerwacji — wtedy `wp_np_bookings` wypełnia się z naszej strony i wszystkie zależne flow stają się trywialne.
