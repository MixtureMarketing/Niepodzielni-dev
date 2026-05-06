# Plan optymalizacji wydajności — Niepodzielni

**Ostatnia aktualizacja:** 2026-05-06  
**Status faz:**
- ✅ Faza 1 — Backend/DB quick wins (wdrożone, commit `e4828ce`)
- ✅ Faza 2a — Lazy-load ai-chat.js + Worker KV cache (wdrożone, commit `2525e7e`)
- ⏳ Faza 0 — Baseline tooling (wymaga dostępu do VPS — instrukcja poniżej)
- ⏳ Faza 2b — DB indeksy, warunkowy load Bookero CDN
- ⏳ Faza 3 — Frontend critical CSS, Cloudflare Cache Rules, Workers strategic

---

## Co już zostało zrobione

### Faza 1 — Backend/DB (commit `e4828ce`)

| Plik | Zmiana | Efekt |
|---|---|---|
| `config/application.php` | `SAVEQUERIES`/`QM_SHOW_ALL_QUERIES` wyłączone w produkcji | Zero per-query SQL memory alloc + stack trace na każdym żądaniu |
| `config/environments/production.php` | Stworzony plik z `SAVEQUERIES=false`, `WP_DEBUG=false` | Blokada przed przypadkowym włączeniem debug w prod |
| `api/30-panel-psycholog.php` | `update_comment_meta_cache()` + `parent__in` batch query | 401 → ~3 queries w `np_panel_get_reviews` |
| `api/19-ai-endpoints.php` | Transient cache 60s + `posts_per_page` 300 + `no_found_rows` | `/bot-availability` cache'owany; boundowane queries |
| `api/15-matchmaker-shortcode.php` | `posts_per_page` 500→200 | Mniejszy scan przy ładowaniu matchmakera |
| `api/40-opinie-api.php` | `update_comment_meta_cache()` przed pętlą ocen | Eliminacja N+1 przy przeliczaniu ratingu |

### Faza 2a — Frontend + Worker (commit `2525e7e`)

| Plik | Zmiana | Efekt |
|---|---|---|
| `resources/js/app.js` | `import './ai-chat.js'` → `requestIdleCallback(() => import(...))` | Vite code-split; ~820 LOC JS nie blokuje renderowania strony |
| `workers/ai-agent/src/routes/chat.ts` | KV cache 90s TTL wokół `buildAvailabilityContext()` | /bot-availability: ~200-400ms → ~1-5ms (KV edge hit) |
| `workers/ai-agent/src/types.ts` | `AVAIL_CACHE: KVNamespace` w interfejsie `Env` | TypeScript coverage |
| `workers/ai-agent/wrangler.toml` | `[[kv_namespaces]] AVAIL_CACHE` binding | Deklaracja bindingu dla Wrangler |

---

## Faza 0 — Zbieranie baseline na VPS

> Wykonać PRZED kolejnymi modyfikacjami kodu. Tylko read/config — żadnych zmian kodu.  
> Strona nie ma realnego ruchu → wyniki trzeba uzupełnić symulowanym obciążeniem (patrz sekcja Load Testing).

### 0.1 MariaDB slow query log

Połącz się z VPS. Utwórz plik `/etc/mysql/conf.d/slowlog.cnf`:

```ini
[mysqld]
slow_query_log          = ON
slow_query_log_file     = /var/log/mysql/slow.log
long_query_time         = 0.1
log_queries_not_using_indexes = ON
log_slow_admin_statements     = ON
```

Restart MariaDB:
```bash
systemctl restart mariadb
```

Po 24h (lub po sesji load-testów) zbierz digest:
```bash
pt-query-digest /var/log/mysql/slow.log > /tmp/slow_digest.txt
# Lub jeśli nie ma Percona Toolkit:
mysqldumpslow -s t -t 30 /var/log/mysql/slow.log > /tmp/slow_digest.txt
```

Cel: top 20 zapytań według `Query_time × Count`. Szukaj `wp_postmeta` i `wp_commentmeta` bez indeksu.

### 0.2 PHP-FPM slowlog

Edytuj `/etc/php/8.3/fpm/pool.d/www.conf` (lub odpowiedni pool):
```ini
slowlog = /var/log/php-fpm/slow.log
request_slowlog_timeout = 500ms
request_slowlog_trace_depth = 20
```

Restart:
```bash
systemctl restart php8.3-fpm
```

Po sesji load-testów:
```bash
cat /var/log/php-fpm/slow.log
```

Szukaj wywołań trwających >500ms z ich stack trace.

### 0.3 OPcache stats (one-shot, usunąć po odczycie)

Wgraj tymczasowy plik `/var/www/html/web/app/mu-plugins/opcache-stats.php`:
```php
<?php
// WAŻNE: usuń ten plik po jednorazowym odczycie
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    http_response_code(403);
    exit;
}
header('Content-Type: application/json');
echo json_encode(opcache_get_status(false), JSON_PRETTY_PRINT);
```

Odczytaj z serwera:
```bash
curl -s http://127.0.0.1/app/mu-plugins/opcache-stats.php | python3 -m json.tool
```

Kluczowe metryki:
- `opcache_statistics.opcache_hit_rate` → cel >99%
- `opcache_statistics.num_cached_scripts` → liczba zakeszowanych plików
- `memory_usage.used_memory_percentage` → jeśli >80%, zwiększ `opcache.memory_consumption` w `php.ini`

**Usuń plik natychmiast po odczycie.**

### 0.4 Redis stats

```bash
redis-cli INFO stats | grep -E 'keyspace_hits|keyspace_misses|evicted_keys|connected_clients'
redis-cli SLOWLOG GET 50
redis-cli MEMORY STATS | grep -E 'used_memory_human|maxmemory_human|mem_fragmentation_ratio'
```

Zapisz wyniki w pliku. Cel: `keyspace_hits / (keyspace_hits + keyspace_misses)` > 95%.

Jeśli `evicted_keys` > 0 — Redis usuwa dane z powodu braku pamięci → zwiększ `maxmemory` w `redis.conf`.

### 0.5 nginx — timing w logach

Sprawdź format logu w `/etc/nginx/nginx.conf` lub `/etc/nginx/conf.d/`:
```bash
grep -r "log_format" /etc/nginx/
```

Jeśli brak `$request_time` i `$upstream_response_time`, dodaj:
```nginx
log_format timed '$remote_addr [$time_local] "$request" $status '
                 '$body_bytes_sent $request_time $upstream_response_time '
                 '"$http_referer" "$http_user_agent"';

access_log /var/log/nginx/access_timed.log timed;
```

Reload nginx:
```bash
nginx -t && systemctl reload nginx
```

Po load-testach agreguj wyniki:
```bash
# Top 20 wolnych ścieżek wg mediany request_time
awk '{print $7, $9}' /var/log/nginx/access_timed.log \
  | sort | awk '{sum[$1]+=$2; cnt[$1]++} END {for(k in sum) print sum[k]/cnt[k], k}' \
  | sort -rn | head -20
```

### 0.6 Xhprof / Tideways (tymczasowo, 24-48h)

```bash
# PHP 8.x — tideways-xhprof jest nowszą wersją
pecl install tideways_xhprof
# Dodaj do /etc/php/8.3/fpm/conf.d/20-tideways.ini:
# extension=tideways_xhprof.so
# tideways_xhprof.clock_use_rdtsc=0
systemctl restart php8.3-fpm
```

Trigger przez cookie (nie spowalnia normalnych requestów):
```php
// Dodaj do wp-config.php lub mu-plugin tymczasowego:
if (isset($_COOKIE['xhprof_enabled'])) {
    tideways_xhprof_enable(TIDEWAYS_XHPROF_FLAGS_MEMORY | TIDEWAYS_XHPROF_FLAGS_CPU);
    register_shutdown_function(function() {
        $data = tideways_xhprof_disable();
        $dir  = '/tmp/xhprof/';
        @mkdir($dir);
        file_put_contents($dir . uniqid() . '.xhprof', serialize($data));
    });
}
```

Endpointy do profilowania (ustaw ciasteczko `xhprof_enabled=1` w przeglądarce):
1. `admin-ajax.php?action=np_panel_get_reviews` — test N+1 fix
2. `wp-json/niepodzielni/v1/bot-availability` — test transient cache
3. `/` (front page)
4. `/psycholodzy/` (listing)

Wizualizacja flamegraph: [https://github.com/brendangregg/FlameGraph](https://github.com/brendangregg/FlameGraph) lub XHProf UI.

**Usuń rozszerzenie i konfigurację po zebraniu danych.**

### 0.7 Query Monitor eksport

Na środowisku staging (lub z IP admina na prodzie) zainstaluj plugin Query Monitor, odwiedź:
- `/` (front page)
- `/psycholodzy/` (listing)
- `/psycholog/{slug}/` (single psycholog)

Eksportuj panel "Queries" do CSV. Cel: ≤40 queries na front page, ≤25 na single.

### 0.8 DISABLE_WP_CRON — wymagana zmiana env

Dodaj do pliku `.env` na VPS:
```
DISABLE_WP_CRON=true
```

Dodaj system cron (crontab root):
```bash
crontab -e
# Dodaj linię:
* * * * * /usr/local/bin/wp --path=/var/www/html/web/wp cron event run --due-now --quiet 2>&1 | logger -t wp-cron
```

Sprawdź ścieżkę WP CLI:
```bash
which wp
# Jeśli brak: curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp
```

Efekt: eliminuje loopback HTTP request na każdej wizycie anonimowego użytkownika.

---

## Load Testing — symulacja ruchu (strona testowa bez realnego trafficu)

Ponieważ strona jest środowiskiem testowym bez realnego ruchu, wszystkie baseline'y muszą być zbierane podczas symulowanego obciążenia.

### Narzędzie: k6 (rekomendowane)

```bash
# Instalacja na VPS lub lokalnie
apt install gnupg2 -y
gpg -k
gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
    --keyserver hkp://keyserver.ubuntu.com:80 \
    --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
    | tee /etc/apt/sources.list.d/k6.list
apt update && apt install k6
```

### Scenariusze testów

Zapisz jako `audit/k6/load-test.js`:

```javascript
import http from 'k6/http';
import { sleep, check } from 'k6';

// Realistyczny profil ruchu dla fundacji NGO
export const options = {
    scenarios: {
        // Scenariusz 1: normalny ruch (20 VU przez 5 minut)
        normal: {
            executor: 'constant-vus',
            vus: 20,
            duration: '5m',
            tags: { scenario: 'normal' },
        },
        // Scenariusz 2: spike (80 VU przez 2 minuty — symuluje kampanię social media)
        spike: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '30s', target: 80 },
                { duration: '2m',  target: 80 },
                { duration: '30s', target: 0 },
            ],
            startTime: '6m',
            tags: { scenario: 'spike' },
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<2000'],   // 95% żądań poniżej 2s
        http_req_failed:   ['rate<0.01'],    // <1% błędów
    },
};

const BASE = 'https://niepodzielni-dev-01.mixturemarketing.pl';

export default function () {
    // Strony z największym ruchem (szacunkowy rozkład)
    const pages = [
        { url: `${BASE}/`,              weight: 30 },
        { url: `${BASE}/psycholodzy/`,  weight: 25 },
        { url: `${BASE}/o-nas/`,        weight: 10 },
        { url: `${BASE}/kontakt/`,      weight: 10 },
    ];

    // Ważone losowanie strony
    const rand = Math.random() * 100;
    let cumulative = 0;
    let target = pages[0].url;
    for (const p of pages) {
        cumulative += p.weight;
        if (rand <= cumulative) { target = p.url; break; }
    }

    const res = http.get(target, {
        headers: { 'Accept-Encoding': 'gzip, deflate, br' },
    });

    check(res, {
        'status 200': r => r.status === 200,
        'TTFB <500ms': r => r.timings.waiting < 500,
    });

    sleep(Math.random() * 3 + 1); // 1-4s między żądaniami (realistyczny użytkownik)
}
```

Uruchomienie:
```bash
k6 run audit/k6/load-test.js --out json=audit/results/k6-$(date +%Y%m%d-%H%M).json
```

### Scenariusz: REST API (bot-availability)

```javascript
// audit/k6/api-test.js
import http from 'k6/http';
import { check } from 'k6';

export const options = {
    vus: 10,
    duration: '2m',
};

export default function () {
    const res = http.get(
        'https://niepodzielni-dev-01.mixturemarketing.pl/wp-json/niepodzielni/v1/bot-availability?consult_type=pelno&days=14',
        { headers: { 'X-API-Key': __ENV.BOT_TOKEN } },
    );
    check(res, { 'status 200': r => r.status === 200 });
}
```

```bash
k6 run -e BOT_TOKEN=xxx audit/k6/api-test.js
```

### Alternatywa: wrk (prostszy, bez scenariuszy)

```bash
# Instalacja
apt install wrk

# Baseline front page (8 wątków, 50 połączeń, 60 sekund)
wrk -t8 -c50 -d60s --latency https://niepodzielni-dev-01.mixturemarketing.pl/ \
    > audit/results/wrk-frontpage-$(date +%Y%m%d).txt

# Benchmark REST endpoint (10 wątków, 30 połączeń)
wrk -t10 -c30 -d30s --latency \
    -H "X-API-Key: TWOJ_TOKEN" \
    "https://niepodzielni-dev-01.mixturemarketing.pl/wp-json/niepodzielni/v1/bot-availability?consult_type=pelno&days=14" \
    > audit/results/wrk-bot-api-$(date +%Y%m%d).txt
```

---

## Faza 2b — Pozostałe do wdrożenia (wymaga dostępu VPS lub więcej planowania)

### DB indeksy na wp_postmeta i wp_commentmeta

> Wykonać po backup bazy! Na stronie testowej możesz zrobić to bezpośrednio.

```sql
-- Sprawdź hot keys przed dodaniem indeksu (wyniki z slow log powiedzą które są najgorętsze)
SELECT meta_key, COUNT(*) AS c
FROM wp_postmeta
GROUP BY meta_key
ORDER BY c DESC
LIMIT 30;

-- Composite index dla kluczy Bookero (używanych w matchmaker + sync)
ALTER TABLE wp_postmeta
    ADD INDEX IF NOT EXISTS idx_postmeta_key_val (meta_key(50), meta_value(20));

-- Index dla commentmeta (opinie + panel)
ALTER TABLE wp_commentmeta
    ADD INDEX IF NOT EXISTS idx_commentmeta_key (meta_key(50));
```

Sprawdź efekt (po `ANALYZE TABLE`):
```sql
EXPLAIN SELECT * FROM wp_postmeta WHERE meta_key = 'bookero_id_niski';
-- Powinien pokazać 'Using index' zamiast 'Using where'
```

### Warunkowy load Bookero CDN

Sprawdź w jaki sposób skrypt Bookero jest enqueue'owany:
```bash
grep -rn "bookero\|cdn.bookero" \
    /var/www/html/web/app/themes/niepodzielni-theme/app/ \
    /var/www/html/web/app/mu-plugins/ \
    --include="*.php"
```

Cel: skrypt Bookero (~1.1 MB) powinien ładować się tylko na stronach z widgetem rezerwacji (single psycholog, strony z shortcode `[bookero]`), nie globalnie.

Przykładowy fix w `setup.php`:
```php
// było:
wp_enqueue_script('bookero', 'https://cdn.bookero.pl/plugin/...');

// fix — tylko na stronach z widgetem:
if (is_singular('psycholog') || has_shortcode(get_post()->post_content ?? '', 'bookero')) {
    wp_enqueue_script('bookero', 'https://cdn.bookero.pl/plugin/...');
}
```

### AVAIL_CACHE KV — tworzenie namespace przed deployem Workera

```bash
# W katalogu workers/ai-agent/
wrangler kv:namespace create AVAIL_CACHE
# → zwróci: id = "xxxxx"

wrangler kv:namespace create AVAIL_CACHE --preview
# → zwróci: preview_id = "yyyyy"
```

Wstaw wygenerowane ID do `wrangler.toml` (linie z `REPLACE_WITH_KV_NAMESPACE_ID`).

---

## Faza 3 — Strategic (sprint 3, po potwierdzeniu wyników fazy 1-2)

### 3.1 Critical CSS per template

Używając Vite plugin lub `critters`:
```bash
npm install -D critters
```

Konfiguracja w `vite.config.js`:
```js
import Critters from 'critters';
// inline CSS powyżej fold dla każdego szablonu
```

Efekt: eliminuje render-blocking CSS — LCP może spaść o 200-500ms.

### 3.2 Cloudflare Cache Rules — full-page cache

W Cloudflare Dashboard → `niepodzielni.pl` → **Cache Rules**:

```
Reguła: "Cache anonymous pages"
Warunek: 
  - Request path nie zawiera wp-admin, wp-login, wp-json
  - Brak ciasteczek: wordpress_logged_in_*, wp-settings-*
Akcja: Cache Everything, TTL: 3600s (1h)
```

```
Reguła: "Bypass cache for logged-in users"  
Warunek: Cookie matches wordpress_logged_in_*
Akcja: Bypass Cache
```

Efekt: TTFB dla anonimowych użytkowników spada z ~300-800ms → ~5-30ms (Cloudflare edge).

### 3.3 Worker KV cache dla embeddingów

W `workers/ai-agent/src/embed.ts` — cache SHA-256 query → wektor:

```typescript
const queryHash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
const hashHex   = [...new Uint8Array(queryHash)].map(b => b.toString(16).padStart(2,'0')).join('');
const kvKey     = `embed:${hashHex}`;

// Sprawdź cache
const cached = await env.EMBED_CACHE?.get(kvKey, 'arrayBuffer');
if (cached) return new Float32Array(cached);

// Generuj nowy embedding
const vector = await /* fetch from AI */ ...;

// Zapisz do KV z TTL 7 dni
await env.EMBED_CACHE?.put(kvKey, new Float32Array(vector).buffer, { expirationTtl: 604800 });
```

Wymaga nowego KV namespace `EMBED_CACHE` w `wrangler.toml`.

Efekt: powtarzające się zapytania (depresja, lęki, stres — top 10 fraz) → ~0ms zamiast ~100-200ms na generowanie embeddingu.

### 3.4 Usunięcie legacy Vectorize indexes

Po potwierdzeniu że `VECTORIZE_KNOWLEDGE` działa poprawnie:
```bash
# Usuń stare indeksy (oszczędność kosztów + uproszczenie kodu)
wrangler vectorize delete psycholodzy
wrangler vectorize delete faq
```

Usuń też z `wrangler.toml`:
```toml
# Do usunięcia:
# [[vectorize]]
# binding = "VECTORIZE_PSY"
# ...
# [[vectorize]]
# binding = "VECTORIZE_FAQ"
# ...
```

### 3.5 Monitoring — Grafana + Prometheus (opcjonalnie)

Jeśli po loadtestach pojawią się systematyczne bottlenecki:

```bash
# Exportery
apt install prometheus-node-exporter    # CPU, RAM, disk, network
# mysqld_exporter — metryki MariaDB
# php-fpm_exporter — metryki PHP-FPM  
# redis_exporter — metryki Redis
```

Grafana Cloud ma free tier (10k metrics/month) — wystarczy na małą stronę.

---

## KPI — mierzyć PRZED i PO każdej naprawie

| Metryka | Narzędzie | Baseline | Target |
|---|---|---|---|
| DB queries / `np_panel_get_reviews` | Query Monitor | ~401 | ≤5 ✅ wdrożone |
| DB queries / front page | Query Monitor | ? | ≤40 |
| TTFB p50 / listing | nginx logs + wrk | ? | <200ms |
| `/bot-availability` latencja | nginx `$request_time` | ~200-400ms | <5ms z KV ✅ wdrożone |
| OPcache hit ratio | opcache_get_status | ? | >99% |
| Redis hit ratio | redis-cli INFO | ? | >95% |
| JS transferred / front page | Lighthouse | ? | <120 KB compressed |
| AI Worker `/chat` p95 | wrangler tail | ? | <2s |
| TTFB anonimowy / front | Cloudflare Analytics | ? | <30ms (z CF cache) |

---

## Kolejność realizacji (rekomendowana)

```
[ZROBIONE] Faza 1  — Backend DB quick wins
[ZROBIONE] Faza 2a — Lazy-load ai-chat + Worker KV cache

[DO ZROBIENIA na VPS]
  1. Włącz MariaDB slow log + PHP-FPM slowlog
  2. Uruchom k6 load test (10-20 VU, 5-10 minut)
  3. Zbierz baseline: OPcache, Redis, nginx timings
  4. Uruchom Xhprof tymczasowo (24h), zbierz flame graphs
  5. DISABLE_WP_CRON=true w .env + system cron

[Faza 2b — po baseline]
  6. Utwórz KV namespace AVAIL_CACHE + wstaw ID do wrangler.toml
  7. DB indeksy (po backup)
  8. Warunkowy load Bookero CDN

[Faza 3 — po potwierdzeniu wyników]
  9. Critical CSS
  10. Cloudflare Cache Rules (full-page cache)
  11. Worker KV cache dla embeddingów
  12. Usuń legacy Vectorize indexes
```

---

## Linki i zasoby

- [Percona Toolkit — pt-query-digest](https://www.percona.com/doc/percona-toolkit/LATEST/pt-query-digest.html)
- [k6 dokumentacja](https://k6.io/docs/)
- [Tideways XHProf](https://github.com/tideways/php-xhprof-extension)
- [Cloudflare Cache Rules](https://developers.cloudflare.com/cache/how-to/cache-rules/)
- [Wrangler KV](https://developers.cloudflare.com/workers/wrangler/commands/#kv)
- [FlameGraph](https://github.com/brendangregg/FlameGraph)
