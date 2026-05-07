# Monitoring runbook — Niepodzielni

Plan wdrożenia warstwy obserwowalności dla Niepodzielni-dev. Decyzje
podjęte w rozmowie z Adminem (analityk biznesowy):

- **Kanał alertów**: Discord — używacie firmowego serwera Discord; jeden
  kanał `#alerts-niepodzielni` zbiera alerty z 4 narzędzi.
- **Reakcje**: jednoosobowe (Admin). Email jako backup do archiwum.
- **Cloudflare**: domena `niepodzielni.pl` / `new.niepodzielni.com` za CF proxy
  (orange cloud), więc CF Web Analytics działa out-of-the-box.
- **Środowiska**: produkcja `new.niepodzielni.com` (branch `main`),
  rozwojowe `dev.niepodzielni.com` (branch `dev`). Część narzędzi tylko
  na jednym ze środowisk — patrz tabela w sekcji 1.

Celem jest **start za 0 zł/mies w dwóch fazach**, łącznie ~4h pracy programisty
plus ~30 min konfiguracji w przeglądarce (Cockpit, Better Stack, Sentry).

---

## 1. Co gdzie ląduje

| Narzędzie | Produkcja (`new.niepodzielni.com`) | Dev (`dev.niepodzielni.com`) | Czas wdrożenia | Koszt |
|---|---|---|---|---|
| Cockpit (panel serwera) | ✅ TAK | opcjonalnie | 30 min | 0 zł |
| Cloudflare Web Analytics | ✅ TAK | nie potrzeba | 5 min | 0 zł |
| Query Monitor | ❌ NIE (leak danych) | ✅ TAK | 15 min | 0 zł |
| Netdata (system metrics) | ✅ TAK | opcjonalnie | 30 min | 0 zł |
| Better Stack (uptime + status page) | ✅ TAK | opcjonalnie | 30 min | 0 zł |
| Sentry (PHP + Worker) | ✅ TAK | ✅ TAK (osobny DSN) | 1 h | 0 zł |
| Audit digest (codzienny mail/Discord) | ✅ TAK | nie potrzeba | 1 h kodu | 0 zł |
| Discord webhooki | wspólne dla obu env, kanał `#alerts-niepodzielni` | jw. | 5 min | 0 zł |

---

## 2. Krok 0 — przygotowanie Discorda (zrobić raz, przed wszystkim)

1. Wejdź na firmowy serwer Discord, którego jesteś adminem.
2. Utwórz kanał `#alerts-niepodzielni`. Sugeruję typ **prywatny** (Channel
   Settings → Permissions → wyłącz `@everyone`, dodaj tylko siebie i
   ewentualnego programistę). Alerty zawierają fragmenty stack trace —
   nie powinno tego widzieć całe biuro.
3. **Wygeneruj webhook**:
   - Klik prawym na kanale → Edit Channel → Integrations → Webhooks → New Webhook.
   - Nazwa: `Niepodzielni Monitoring`.
   - **Copy Webhook URL** — coś typu `https://discord.com/api/webhooks/12345.../abcd...`.
4. Trzymaj ten URL jako sekret. Trafi:
   - Do `.env` w produkcji jako `NP_DISCORD_WEBHOOK_URL=...` (przez Trellis vault).
   - Do GitHub Actions secrets jako `DISCORD_WEBHOOK_URL` (jeśli kiedyś
     będziesz alertować z CI).
   - Do Sentry / Better Stack / Netdata jako Discord integration URL.

> **Dlaczego osobny webhook dla każdego narzędzia, a nie jeden wspólny?**
> Discord pozwala na nieograniczoną liczbę webhooków per kanał — wygenerowanie
> 4 osobnych jest darmowe, a daje czytelniejsze nazwy bota („Sentry",
> „Better Stack", „Netdata", „WordPress audit") i awatary w kanale. Wszystkie
> wskazują ten sam `#alerts-niepodzielni`, więc widzisz je razem chronologicznie.

> **Discord ma natywne wsparcie dla Slack-formatted webhooków**: jeśli
> jakieś narzędzie generuje payload w formacie Slacka (np. starsze wersje
> niektórych integracji), wystarczy do URL Discorda dopisać `/slack` na
> końcu i Discord automatycznie przemapuje. Większość narzędzi (Sentry,
> Better Stack, Netdata) ma jednak natywną integrację Discord — używaj jej.

---

## 3. FAZA 1 — szybkie zwycięstwa (~50 min pracy)

### 3.1. Cockpit — graficzny panel serwera (30 min)

**Co dostaniesz**: web UI na `https://new.niepodzielni.com:9090` z wykresami
CPU/RAM/dysk, listą usług (start/stop/restart przyciskiem), terminalem w
przeglądarce, logami systemd, listą kont. Logujesz się tym samym hasłem co
przez SSH.

**Wdrożenie**:

```bash
# Jednorazowo, jako root na produkcji:
ssh root@new.niepodzielni.com

apt update
apt install -y cockpit cockpit-pcp
systemctl enable --now cockpit.socket

# Otwórz port 9090 w firewallu, ale TYLKO dla Twojego IP.
# Najpierw sprawdź swój IP z laptopa:  curl ifconfig.me
ufw allow from <TWOJ_IP> to any port 9090 proto tcp
ufw reload

# Sprawdź:
ss -ltn | grep 9090   # powinno pokazać LISTEN
```

**Bezpieczne wystawianie publiczne (opcjonalnie, później)**:

Jeśli kiedykolwiek będziesz chciał dostęp z dowolnego IP:

1. Reverse proxy przez nginx z Let's Encrypt na subdomenie `cockpit.niepodzielni.pl`.
2. Plus: fail2ban regex dla portu 9090 (kopia istniejącej reguły dla SSH —
   patrz `trellis/roles/fail2ban/`).
3. Plus: dwuetapowa autoryzacja (Cockpit ma plugin 2FA przez TOTP).

**Pierwsze logowanie**:

- `https://new.niepodzielni.com:9090`
- Self-signed cert → przeglądarka będzie ostrzegać; zaakceptuj raz.
- Login: `root` (lub Twój sudoer) + hasło SSH.
- Polski język wybierasz w prawym górnym rogu.

**Co warto sprawdzić przy pierwszym uruchomieniu**:

- Storage → wolne miejsce na każdym mount (cel: <80%).
- Services → zielone: `nginx`, `php8.4-fpm`, `mysql`, `redis-server`.
- Logs → ostatnie 24h, level Warning+ — pusto albo sporadyczne.

### 3.2. Cloudflare Web Analytics (5 min)

**Co dostaniesz**: panel CF z liczbą wejść/wyjść per strona, krajami,
urządzeniami, **Core Web Vitals** (LCP / CLS / INP — czyli czas ładowania
zmierzony u prawdziwych użytkowników, podzielony per podstrona).

**Wdrożenie** (działa od ręki, bo domena za proxy):

1. Cloudflare Dashboard → wybierz strefę `niepodzielni.pl` (i osobno `new.niepodzielni.com`).
2. Lewy panel → **Analytics & Logs** → **Web Analytics**.
3. „Enable for this site" → w sekundę gotowe.
4. Włącz **Web Vitals** (zazwyczaj domyślnie).

**Czas do pierwszych danych**: 24 h. Wcześniej zobaczysz „Loading…".

**Co tu szukać tygodniowo (5 min)**:

- Sekcja **Core Web Vitals** → która podstrona ma najwyższe LCP (czas do
  pierwszego dużego elementu)? Cel: <2.5 s.
- Sekcja **Top URLs** → wzrost vs. poprzedni tydzień.
- Sekcja **Performance** → 4xx/5xx errors per podstrona.

> **Bonus**: w panelu CF masz też **Notifications** (lewa kolumna) — możesz
> ustawić alert „origin error rate >5% przez 10 min" → Discord webhook
> z punktu 2 (CF wspiera webhooki generic, działa z Discordem out-of-the-box,
> wystarczy URL z `/slack` na końcu jeśli format wymaga przemapowania).

### 3.3. Query Monitor — debug wydajności na DEV (15 min)

**Co dostaniesz**: w pasku admina (tylko gdy zalogowany jako admin) widzisz
panel z liczbą zapytań SQL per request, pamięć PHP, zalogowane warningi,
wszystkie wykonane hooki.

**Wdrożenie tylko na DEV** (nigdy na produkcji — leak ścieżek i SQL):

```bash
# Lokalnie, po wczytaniu brancha dev:
git checkout dev

# Dopisz do composer.json w sekcji require-dev (NIE w require!):
#   "wpackagist-plugin/query-monitor": "^3"
# … i jeśli go nie ma, repozytorium wpackagist:
#   "repositories": [
#       { "type": "composer", "url": "https://wpackagist.org" }
#   ]

composer require --dev wpackagist-plugin/query-monitor

# Plugin sam się aktywuje na dev (wp_environment_type='development').
# Jeśli nie — w admin → Plugins zaznacz „Network Activate".
```

**Konfiguracja, żeby plugin nie wlazł na produkcję**:

W `config/environments/production.php` (już istnieje) dopisz na końcu:

```php
// Bezpieczeństwo: jeśli Query Monitor by się tu znalazł (np. przez auto-update),
// nie pokazuj jego output nikomu poza adminami.
if (! defined('QM_DISABLED')) {
    define('QM_DISABLED', true);
}
```

**Jak czytać panel** (dla programisty):

1. Otwórz dowolną wolną podstronę na dev.
2. Klik w pasek admina → „Queries" → posortuj po czasie.
3. Wszystko >50 ms → kandydat do indeksu/cache.
4. Klik „Hooks & Actions" → ile filtrów jest na request (>500 = problem).

> **Nie używaj Query Monitor jako narzędzia długoterminowego monitoringu** —
> on służy do *debuggingu konkretnego requesta przez programistę*. Do
> historycznych trendów masz Sentry Performance (faza 2 lub 3).

---

## 4. FAZA 2 — pełna obserwowalność (~3 h pracy)

### 4.1. Netdata Cloud (30 min)

**Co dostaniesz**: live wykresy CPU / RAM / dysk / sieć / Redis hit ratio /
MySQL slow queries / nginx requests-per-second. Auto-discovery — wykrywa
zainstalowane usługi sam. Web UI w chmurze (`app.netdata.cloud`) plus lokalny
agent na serwerze.

**Wdrożenie**:

```bash
ssh root@new.niepodzielni.com

# Oficjalny installer Netdata
wget -O /tmp/netdata-kickstart.sh https://my-netdata.io/kickstart.sh
sh /tmp/netdata-kickstart.sh --stable-channel --claim-token <TOKEN> --claim-rooms <ROOM_ID>
```

`<TOKEN>` i `<ROOM_ID>` wziąć z https://app.netdata.cloud → Add Node →
Install on Linux → kopiujesz pełne polecenie. Free tier do 5 nodes.

**Discord integration**:

- Netdata Cloud → Settings → Notifications → Add → **Discord**
- Wklejasz webhook URL z punktu 2 (Krok 0).
- Wybierz alarmy: „Critical" + „Warning". Pomiń „Clear" (nie zalewaj
  kanału informacją że problem zniknął).

**Co dostaje Discord** (przykład):

```
🟡 [WARNING] new.niepodzielni.com — Disk space usage on /
   77% used, growing 0.4% per day. ETA full: ~8 weeks.
```

### 4.2. Better Stack — uptime + status page (30 min)

**Co dostaniesz**:

1. Co 5 min monitor sprawdza listę kluczowych URL-i. Gdy któryś zwróci
   nie-200 lub timeout → Discord alert w 30 sekund.
2. Publiczna status page na `status.niepodzielni.pl` (CNAME do
   Better Stack) — pokazuje 30-dniową historię, ten sam stan widzą
   psychologowie i klienci.

**Free tier**: 10 monitorów co 5 min, status page, e-mail/Discord alerty,
3-month retention.

**Lista monitorów** (do skonfigurowania w UI):

| URL | Asercja | Powód |
|---|---|---|
| `https://new.niepodzielni.com/` | status 200 + zawiera `class="psy-card"` lub fragment hero | strona główna |
| `https://new.niepodzielni.com/konsultacje-pelnoplatne/` | 200 + zawiera nazwisko któregoś psychologa | listing pelno (jeden ze sztandarowych) |
| `https://new.niepodzielni.com/konsultacje-niskoplatne/` | 200 + `class="psy-card"` | listing nisko |
| `https://new.niepodzielni.com/aktualnosci/` | 200 | listing aktualności |
| `https://new.niepodzielni.com/wp-login.php` | 200 + `wp-submit` w treści | panel admina dostępny |
| `https://new.niepodzielni.com/wp-json/niepodzielni/v1/psychomapa` | 200 + `application/json` | REST API żyje |
| `https://niepodzielni-ai-agent-production.<account>.workers.dev/` | 200 + `service: niepodzielni-ai-agent` | Worker AI żyje |

**Wdrożenie**:

1. https://betterstack.com → Sign up (free).
2. Logs Tail → przy okazji włącz, dostajesz 1 GB darmowych logów.
3. Uptime → Add Monitor → wpisujesz każdy URL z tabeli + asercję
   (Advanced → Expected response → Body should contain).
4. Notifications → **Discord** → webhook z Krok 0.
5. Status Page → Create → wybierz 7 monitorów, ustaw subdomain
   `status.niepodzielni.pl`. Better Stack pokaże Ci CNAME do dodania w DNS
   Cloudflare (`status` → `tatatatata.betteruptime.com`). Pamiętaj
   o trybie `DNS only` (gray cloud) na rekordzie status — Better Stack
   sam zarządza certyfikatem TLS.

**Co dostaje Discord** (przykład):

```
🔴 [DOWN] new.niepodzielni.com — Listing Pelno
   HTTP 502 Bad Gateway since 14:23 UTC. Last good check: 14:18.
   Status page: https://status.niepodzielni.pl
```

### 4.3. Sentry — błędy aplikacji PHP + Worker (1 h)

**Co dostaniesz**: każdy uncaught exception, fatal error, JS error u
użytkownika trafia do panelu Sentry, jest grupowany („ten sam błąd
wystąpił 47×, oto pierwszy stack trace, oto ostatnich 3 użytkowników").
Free tier: 5k errors/mies — dla Was więcej niż wystarczy.

**Wdrożenie PHP** (mu-plugin):

1. `composer require sentry/sentry`
2. Nowy plik `web/app/mu-plugins/niepodzielni-core/admin/17-sentry-init.php`:

```php
<?php
// Inicjalizacja Sentry — tylko gdy SENTRY_DSN ustawione w .env.
if (! defined('ABSPATH')) {
    exit;
}

if (! defined('SENTRY_DSN') || empty(SENTRY_DSN)) {
    return;
}

\Sentry\init([
    'dsn'              => SENTRY_DSN,
    'environment'      => defined('WP_ENV') ? WP_ENV : 'unknown',
    'release'          => defined('NP_RELEASE') ? NP_RELEASE : null,
    'sample_rate'      => 1.0,        // wszystkie błędy
    'traces_sample_rate' => 0.1,      // 10% requestów dla performance
    // ── PII filtering: usuń z payloadu wszystko co przypomina PESEL/email/tel ──
    'before_send' => function (\Sentry\Event $event): ?\Sentry\Event {
        // Usuń body POST (mogą być dane formularza kontaktowego)
        $request = $event->getRequest();
        if (! empty($request['data'])) {
            unset($request['data']);
            $event->setRequest($request);
        }
        return $event;
    },
]);
```

3. W `niepodzielni-core.php` (po `13-login-throttle.php`):

```php
require_once NIEPODZIELNI_CORE_PATH . 'admin/17-sentry-init.php';
```

4. W `config/application.php` (po linii z `NP_AI_BOT_TOKEN`):

```php
Config::define('SENTRY_DSN', env('SENTRY_DSN') ?: '');
Config::define('NP_RELEASE', env('NP_RELEASE') ?: 'dev');
```

5. W `.env.example`:

```
SENTRY_DSN=
NP_RELEASE=
```

6. Operacyjnie: w Sentry → Create Project → Platform: PHP (Native PHP, nie
   WordPress — bo my podpinamy ręcznie). Projekt nazwijmy
   `niepodzielni-wp`. Po założeniu dostaniesz DSN typu
   `https://abc@sentry.io/123`. Wleć go do produkcyjnego `.env`
   (przez Trellis vault) i do dev `.env` (osobny projekt
   `niepodzielni-wp-dev`, osobny DSN).

**Wdrożenie Worker**:

1. `cd workers/ai-agent && npm install @sentry/cloudflare`
2. W `src/index.ts`, na samej górze:

```ts
import * as Sentry from '@sentry/cloudflare';

const sentryWrap = (env: Env, fn: () => Promise<Response>) => {
    if (env.SENTRY_DSN) {
        Sentry.init({
            dsn: env.SENTRY_DSN,
            environment: env.SENTRY_ENV ?? 'unknown',
            tracesSampleRate: 0.1,
        });
    }
    return fn();
};
```

   Owinąć główny handler `fetch(request, env)` w `sentryWrap` lub użyć
   wrappera z `@sentry/cloudflare` (`Sentry.withSentry(handler)`).

3. `wrangler secret put SENTRY_DSN --env production` → wkleić DSN z
   projektu `niepodzielni-worker` w Sentry. Analogicznie `--env staging`.

4. `types.ts` dopisać `SENTRY_DSN: string; SENTRY_ENV: string;`.

**Discord integration** (po stronie Sentry):

Sentry **nie ma natywnej integracji z Discord** (ma Slack), ale dwie ścieżki
są równie dobre:

- **Opcja A — przez Slack-compatible webhook**: Sentry → Settings →
  Integrations → Webhooks → Add → URL = `<DISCORD_WEBHOOK_URL>/slack`
  (Discord zna format Slacka i przemapuje).
- **Opcja B — przez bota typu MonitoBot albo własny mini-relay**: Sentry
  Webhook (generic) → mała funkcja Cloudflare Worker która tłumaczy
  format Sentry na Discord. Dla MVP Opcja A wystarczy.

W Sentry → Project → Alerts → Create Alert → trigger „A new issue is
created" → Action „Send a notification via webhook" → wybierz Discord.
Tylko **nowe** błędy alertują, nie zalewają kanału powtórkami.

**Co dostaje Discord** (przykład):

```
🐛 [Sentry] niepodzielni-wp · production
   PHP 8.4 Fatal error: Uncaught TypeError in EventsListingService.php:104
   First seen 2 minutes ago — affects 3 users
   View: https://sentry.io/issues/12345
```

### 4.4. Audit digest — codzienny mail/Discord o bezpieczeństwie (1 h kodu)

**Co dostaniesz**: codziennie o 7:00 podsumowanie z `wp_np_audit` (ta
tabela już istnieje od PR #7) — udane logowania, nieudane, lockouty, top
IP. Email zwykły admin email + duplikat do Discord.

**Wdrożenie**: nowy plik `web/app/mu-plugins/niepodzielni-core/admin/18-audit-digest.php`
(numer 18, bo 17 zarezerwowane na sentry-init):

```php
<?php
// Codzienny digest z audit log → email + Discord webhook.
if (! defined('ABSPATH')) {
    exit;
}

const NP_AUDIT_DIGEST_HOOK = 'np_audit_digest_daily';

add_action('init', static function (): void {
    if (! wp_next_scheduled(NP_AUDIT_DIGEST_HOOK)) {
        // Codziennie o 7:00 czasu serwera.
        $first = strtotime('tomorrow 07:00') ?: time() + DAY_IN_SECONDS;
        wp_schedule_event($first, 'daily', NP_AUDIT_DIGEST_HOOK);
    }
});

add_action(NP_AUDIT_DIGEST_HOOK, static function (): void {
    global $wpdb;
    $table = $wpdb->prefix . 'np_audit';

    // ── Zlicz zdarzenia z ostatnich 24h ─────────────────────────────────────
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT action, COUNT(*) AS c
         FROM {$table}
         WHERE ts >= DATE_SUB(NOW(), INTERVAL %d HOUR)
         GROUP BY action",
        24,
    ));
    $counts = [];
    foreach ($rows as $r) {
        $counts[$r->action] = (int) $r->c;
    }

    // Top IP po nieudanych próbach
    $topIps = $wpdb->get_results($wpdb->prepare(
        "SELECT ip, COUNT(*) AS c
         FROM {$table}
         WHERE action = 'login_failed' AND ts >= DATE_SUB(NOW(), INTERVAL %d HOUR)
         GROUP BY ip ORDER BY c DESC LIMIT 5",
        24,
    ));

    $report  = "📊 Niepodzielni — bezpieczeństwo wczoraj\n\n";
    $report .= sprintf("Udane logowania:     %d\n", $counts['login_success'] ?? 0);
    $report .= sprintf("Nieudane próby:      %d\n", $counts['login_failed']  ?? 0);
    $report .= sprintf("Zablokowane IP:      %d\n", $counts['login_lockout'] ?? 0);
    $report .= sprintf("Reset hasła:         %d\n", $counts['password_reset'] ?? 0);
    $report .= sprintf("Nowi użytkownicy:    %d\n", $counts['user_registered'] ?? 0);

    if ($topIps) {
        $report .= "\nTop IP nieudane (24h):\n";
        foreach ($topIps as $r) {
            $report .= sprintf("  %s — %d prób\n", $r->ip, (int) $r->c);
        }
    }

    // ── Email do admina ─────────────────────────────────────────────────────
    wp_mail(
        get_option('admin_email'),
        '📊 Audit digest — ' . wp_date('Y-m-d'),
        $report,
    );

    // ── Discord webhook (jeśli skonfigurowany) ──────────────────────────────
    // Discord oczekuje { "content": "..." } (vs Slack: { "text": "..." }).
    // Trzy backticki dają monospace block w Discord — wynik wygląda jak tabela.
    $webhook = defined('NP_DISCORD_WEBHOOK_URL') ? NP_DISCORD_WEBHOOK_URL : '';
    if ($webhook) {
        wp_remote_post($webhook, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'username' => 'WordPress Audit',
                'content'  => "```\n" . $report . "```",
            ]),
        ]);
    }

    do_action('np_audit_event', [
        'action' => 'audit_digest_sent',
        'meta'   => $counts,
    ]);
});

// Bezpieczne wyrzucenie cron przy disable
register_deactivation_hook(__FILE__, static function (): void {
    $ts = wp_next_scheduled(NP_AUDIT_DIGEST_HOOK);
    if ($ts) {
        wp_unschedule_event($ts, NP_AUDIT_DIGEST_HOOK);
    }
});
```

**Konfiguracja**:

1. `config/application.php`:

```php
Config::define('NP_DISCORD_WEBHOOK_URL', env('NP_DISCORD_WEBHOOK_URL') ?: '');
```

2. `.env` (produkcja, przez Trellis vault):

```
NP_DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/.../...
```

3. W `niepodzielni-core.php` po `15-retention-cron.php`:

```php
require_once NIEPODZIELNI_CORE_PATH . 'admin/18-audit-digest.php';
```

4. Po deployu sprawdź:

```bash
wp cron event run np_audit_digest_daily --path=/srv/www/new.niepodzielni.com/current/web/wp
```

   Powinno wpaść coś do `#alerts-niepodzielni` od razu.

**Próg alertu „natychmiastowego"** (opcjonalnie, na bonus):

Jeśli w ciągu 1h pojawia się >20 lockoutów (atak brute-force), zamiast
czekać do 7:00 wysłać alert teraz. Hook do `np_security_lockout` (już
emitowany przez `13-login-throttle.php`):

```php
add_action('np_security_lockout', static function (array $payload): void {
    $key   = 'np_lockout_burst';
    $count = (int) get_transient($key);
    set_transient($key, $count + 1, HOUR_IN_SECONDS);

    if ($count + 1 === 20) { // Próg, alert raz na okno
        $webhook = defined('NP_DISCORD_WEBHOOK_URL') ? NP_DISCORD_WEBHOOK_URL : '';
        if ($webhook) {
            wp_remote_post($webhook, [
                'timeout' => 5,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode([
                    'username' => 'WordPress Audit',
                    'content'  => "🚨 **Brute-force attack** — 20+ lockoutów w 1h. "
                              .  "IP: `" . ($payload['ip'] ?? '?') . "`",
                ]),
            ]);
        }
    }
});
```

---

## 5. Co już istnieje w PR #7 i jest fundamentem

- `wp_np_audit` (tabela) — już zbiera login_success / login_failed /
  login_lockout / user_registered / password_reset / profile_updated
  + custom `np_audit_event`. Audit digest tylko z niej czyta.
- `13-login-throttle.php` — emituje akcję `np_security_lockout` —
  dorzucamy nasłuch (sekcja 4.4).
- `16-security-headers.php` — eksponuje filtr `np_security_headers` —
  można tu dorzucić `report-uri` jeśli chcemy raporty CSP do Better
  Stack/Sentry (na razie zostawiamy CSP w trybie Report-Only bez raportów).

---

## 6. Po pełnym wdrożeniu — typowy dzień

```
07:03  📊 [WordPress Audit] Bezpieczeństwo wczoraj
       Udane logowania: 4 (Admin + 3 psychologów)
       Nieudane próby: 11 (3 IP)
       Zablokowane: 0

11:48  🐛 [Sentry] Nowy błąd: TypeError on /aktualnosci page
       1 user affected · production · view in Sentry

15:22  🟡 [Netdata] Disk space on / — 78% used
       (próg 75%, growing 0.5% per day)

— alerty w Discord #alerts-niepodzielni —

W tym samym czasie patrzysz raz w tygodniu:
- status.niepodzielni.pl     → wszystko zielone
- Cloudflare Web Analytics   → Core Web Vitals na /psycholodzy się
                                pogarsza? trend up? to do action

Raz w miesiącu:
- Sentry → top 5 błędów do naprawy → mówisz programiście.
- Netdata → trend ostatnich 30 dni — dysk za 4 miesiące się zapełni.
```

---

## 7. Bezpieczeństwo i RODO

Specyfika branży (psychoterapia → wrażliwe PII):

- **Sentry `before_send`** w sekcji 4.3 wyrzuca body POST z eventów —
  formularz kontaktowy nie wycieka do Sentry. Zweryfikuj po pierwszym
  dniu, że stack trace nie zawiera `$_POST` value (Sentry ma tab
  „Additional data"). Jeśli wyciek — dodaj kolejne `unset()` na
  `data_extra`, `cookies`, `query_string`.
- **Cloudflare Web Analytics** nie używa cookies, nie zbiera IP —
  całkowicie RODO-friendly, można nawet pominąć banner cookies dla niego.
- **Better Stack** nie wchodzi w ruch użytkowników, tylko sprawdza URL z
  zewnątrz — neutralne pod kątem RODO.
- **Audit digest** wysyła tylko **liczby** + IP w postaci IPv4 (PII pod
  RODO). Discord trzyma dane w USA — webhook idzie tylko jednokierunkowo
  (POST → Discord), ale wiadomość zostaje w historii kanału. Jeśli
  RODO compliance wymaga EU-only, używaj wyłącznie email + zostaw
  Discord wyłączony, lub usuń stare wiadomości po 30 dniach (Discord
  pozwala usunąć wiadomości manualnie albo przez bota).

---

## 8. Kolejność wdrożenia (rekomendowana)

1. **Krok 0** (Discord webhook) — 10 min, bez tego reszta nie ma gdzie alertować.
2. **3.1 Cockpit** — natychmiastowy zysk dla Admina (panel zamiast SSH).
3. **3.2 Cloudflare Web Analytics** — 5 min, pierwsze dane za 24 h.
4. **3.3 Query Monitor** na DEV — gdy będziecie debugować wydajność.
5. **4.1 Netdata** — gdy ktoś wraz z Cockpitem chce alertu.
6. **4.2 Better Stack** — najszybszy zysk (alert że strona padła).
7. **4.3 Sentry** — wymaga deploya WP i Workera, więc planuj jak refactor.
8. **4.4 Audit digest** — nowy mu-plugin, jeden commit, deploy.

---

## 9. Co zostawiamy poza zakresem (do oddzielnej decyzji)

- **Pełny APM stack** (Datadog, NewRelic, Grafana+Prometheus+Loki) —
  niepotrzebne dla tej skali. Sentry Performance pokrywa 80% potrzeb.
- **Pełny SIEM** (Wazuh, OSSEC) — dla regulowanej branży (banki,
  zdrowie wymagane przez prawo) tak; dla nas wystarcza audit log + digest.
- **Status page jako brand** — Better Stack daje za darmo, ale jeśli
  chcecie własną domenę z brandingiem, dodać CNAME `status.niepodzielni.pl`.
- **Heartbeats** dla cronów (Bookero sync, retention, audit purge) —
  dodać kiedy raz cron się wywali bez alertu. Better Stack ma „Heartbeats"
  za darmo (10 sztuk).

---

## 10. Estymacja kosztu wdrożenia

| Etap | Czas | Wykonawca |
|---|---|---|
| Krok 0 (Discord) | 10 min | Admin |
| 3.1 Cockpit | 30 min | Programista (1 polecenie + ufw) |
| 3.2 CF Analytics | 5 min | Admin (klik w panelu) |
| 3.3 Query Monitor | 15 min | Programista (composer + git commit) |
| 4.1 Netdata | 30 min | Programista (kickstart + claim) |
| 4.2 Better Stack | 30 min | Admin (UI) + 5 min DNS |
| 4.3 Sentry | 1 h | Programista (PHP SDK + Worker SDK + ENV) |
| 4.4 Audit digest | 1 h | Programista (mu-plugin + ENV + cron test) |
| **Razem** | **~4 h** | — |

**Koszt miesięczny**: 0 zł na obecnej skali. Pierwsze koszty pojawią się
gdy:

- Sentry: >5k errors/mies → Team plan ~110 zł/mies.
- Better Stack: >10 monitorów lub >3 mies retention logów → Team ~110 zł/mies.
- Discord: brak limitów dla tego typu użycia (bot/webhook są darmowe).

Realnie pierwsze 12 miesięcy w 100% darmowe.

---

## 11. Definition of Done

Po wdrożeniu wszystkich punktów Admin powinien móc odpowiedzieć **YES**
na każde z poniższych pytań:

- [ ] Mogę zalogować się do panelu serwera bez SSH (Cockpit).
- [ ] Wiem ile osób było na stronie wczoraj, na której podstronie najwięcej.
- [ ] Wiem która podstrona ma najgorszy LCP (Largest Contentful Paint).
- [ ] Dostaję Discord alert gdy strona przestanie działać (Better Stack).
- [ ] Dostaję Discord alert gdy nowy błąd PHP/JS pojawi się w aplikacji (Sentry).
- [ ] Dostaję Discord alert gdy serwer ma <10% wolnego dysku (Netdata).
- [ ] Co rano widzę w Discord digest bezpieczeństwa (audit digest).
- [ ] Mam status page który pokazuję psychologom gdy coś nie działa.
- [ ] Wiem że żadne PII nie wycieka do Sentry (zweryfikowane na 1 evencie).
