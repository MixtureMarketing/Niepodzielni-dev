# Plan optymalizacji wydajności — Niepodzielni

**Ostatnia aktualizacja:** 2026-05-06  
**Stack:** WordPress Bedrock, PHP 8.3, **MySQL 8.0**, Redis, nginx, Cloudflare Workers  
**Ruch:** ~30 000 użytkowników/miesiąc → ~1 000/dzień → **~12 VU w szczycie**, spike ~35 VU

**Status faz:**
- ✅ Faza 1 — Backend/DB quick wins (commit `e4828ce`)
- ✅ Faza 2a — Lazy-load ai-chat.js + Worker KV cache (commit `2525e7e`)
- ⏳ Faza 0 — Baseline tooling (wymaga ręcznych działań na VPS — instrukcja poniżej)
- ⏳ Faza 2b — DB indeksy, warunkowy load Bookero CDN, AVAIL_CACHE KV deploy
- ⏳ Faza 3 — Critical CSS, Cloudflare Cache Rules, Worker embeddings KV

---

## Co już zostało zrobione

### Faza 1 — Backend/DB (commit `e4828ce`)

| Plik | Zmiana | Efekt |
|---|---|---|
| `config/application.php` | `SAVEQUERIES`/`QM_SHOW_ALL_QUERIES` wyłączone w produkcji | Zero per-query SQL memory alloc + stack trace |
| `config/environments/production.php` | Nowy plik — `SAVEQUERIES=false`, `WP_DEBUG=false` | Blokada przed przypadkowym debug w prod |
| `api/30-panel-psycholog.php` | `update_comment_meta_cache()` + `parent__in` batch | 401 → ~3 queries w `np_panel_get_reviews` |
| `api/19-ai-endpoints.php` | Transient cache 60s + `posts_per_page=300` + `no_found_rows` | `/bot-availability` cache'owany; bounded scan |
| `api/15-matchmaker-shortcode.php` | `posts_per_page` 500→200 | Mniejszy scan matchmaker |
| `api/40-opinie-api.php` | `update_comment_meta_cache()` przed pętlą ocen | Eliminacja N+1 przy ratingu |

### Faza 2a — Frontend + Worker (commit `2525e7e`)

| Plik | Zmiana | Efekt |
|---|---|---|
| `resources/js/app.js` | `import` → `requestIdleCallback(() => import(...))` | Vite code-split; ~820 LOC JS poza critical path |
| `workers/ai-agent/src/routes/chat.ts` | KV cache 90s TTL w `buildAvailabilityContext()` | `/bot-availability`: ~300ms → ~2ms (KV edge hit) |
| `workers/ai-agent/src/types.ts` | `AVAIL_CACHE: KVNamespace` w `Env` | TypeScript coverage |
| `workers/ai-agent/wrangler.toml` | `[[kv_namespaces]] AVAIL_CACHE` binding | Wymaga wstawienia realnych KV ID przed deployem |

---

## Matematyka ruchu (dlaczego te liczby VU)

```
30 000 użytkowników/miesiąc
  ÷ 30 dni                     = 1 000 użytkowników/dzień (średnia)
  × 80% w godzinach szczytu    =   800 sesji w 8h (9:00–17:00)
  ÷ 8h = 100 sesji/h
  × średnia sesja 4–5 min      = 100 × (5/60) ≈ 8–10 VU równocześnie

Dodajemy 20–30% margines       → 12 VU = normalny szczyt
Spike (social media / prasa)   → 35 VU = 3× normal (realistyczny)
Stress test (czy serwer wytrzyma 10× peak) → 120 VU jednorazowo
```

Pliki testowe: `audit/k6/load-test.js` (strony), `audit/k6/api-test.js` (REST API).

---

## Faza 0 — Zbieranie baseline na VPS

> Wykonać PRZED kolejnymi optymalizacjami. Uruchom load test k6 równolegle z każdym pomiarem.

### 0.1 MySQL 8.0 slow query log

> **Uwaga:** projekt używa **MySQL 8.0**, nie MariaDB. Składnia jest ta sama, ale plik konfiguracyjny i polecenie restart różnią się.

Sprawdź ścieżkę konfigu:
```bash
mysql --help | grep "my.cnf"
# Typowo: /etc/mysql/mysql.conf.d/mysqld.cnf (Ubuntu)
#          /etc/my.cnf lub /etc/mysql/my.cnf (CentOS/RedHat)
```

Utwórz osobny plik `/etc/mysql/conf.d/slow-log.cnf` (nie nadpisuje głównego):
```ini
[mysqld]
slow_query_log                = ON
slow_query_log_file           = /var/log/mysql/slow.log
long_query_time               = 0.1
log_queries_not_using_indexes = ON
log_slow_admin_statements     = ON
```

Restart:
```bash
systemctl restart mysql
# Sprawdź status — MySQL 8.0 jest rygorystyczny ws. składni cnf
systemctl status mysql
```

Po sesji load-testów zbierz digest:
```bash
# Opcja A — Percona Toolkit (najlepsze)
pt-query-digest /var/log/mysql/slow.log > /tmp/slow_digest.txt

# Opcja B — wbudowane narzędzie MySQL
mysqldumpslow -s t -t 30 /var/log/mysql/slow.log > /tmp/slow_digest.txt
```

**Cel:** Top 20 zapytań wg `Query_time × Count`. Szukaj `wp_postmeta`, `wp_commentmeta` z pełnym TABLE SCAN.

### 0.1b MySQL 8.0 Performance Schema (alternatywa — bez restartu)

MySQL 8.0 ma wbudowane `performance_schema` aktywne domyślnie. Daje te same dane bez zmian konfiga:

```sql
-- Włącz consumer dla top queries (jednorazowo, nie wymaga restartu)
UPDATE performance_schema.setup_consumers
   SET enabled = 'YES'
 WHERE name IN ('events_statements_summary_by_digest', 'events_statements_history_long');

-- Top 20 wolnych zapytań po sesji load-testów:
SELECT
    DIGEST_TEXT,
    COUNT_STAR           AS count,
    ROUND(AVG_TIMER_WAIT/1e9, 2) AS avg_ms,
    ROUND(MAX_TIMER_WAIT/1e9, 2) AS max_ms,
    SUM_ROWS_EXAMINED    AS rows_examined,
    SUM_NO_INDEX_USED    AS no_index_count
FROM performance_schema.events_statements_summary_by_digest
WHERE SCHEMA_NAME = DATABASE()
ORDER BY SUM_TIMER_WAIT DESC
LIMIT 20;

-- Zapytania bez indeksu (najważniejsze!):
SELECT DIGEST_TEXT, COUNT_STAR, SUM_NO_INDEX_USED
FROM performance_schema.events_statements_summary_by_digest
WHERE SUM_NO_INDEX_USED > 0 AND SCHEMA_NAME = DATABASE()
ORDER BY SUM_NO_INDEX_USED DESC LIMIT 20;
```

Podłącz się do MySQL z VPS:
```bash
mysql -u root -p nazwa_bazy
# lub przez wp-cli:
wp db query "SELECT DIGEST_TEXT, COUNT_STAR ..." --path=/var/www/html/web/wp
```

### 0.2 PHP-FPM slowlog

Edytuj `/etc/php/8.3/fpm/pool.d/www.conf`:
```ini
slowlog                      = /var/log/php-fpm/slow.log
request_slowlog_timeout      = 500ms
request_slowlog_trace_depth  = 20
```

```bash
systemctl restart php8.3-fpm
```

Po load-testach:
```bash
cat /var/log/php-fpm/slow.log
```

Szukaj requestów >500ms ze stack trace wskazującym na konkretną funkcję WP.

### 0.3 OPcache stats (one-shot — usunąć po odczycie)

```bash
# Wgraj tymczasowy plik na serwer
cat > /var/www/html/web/app/mu-plugins/opcache-stats.php << 'EOF'
<?php
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1') { http_response_code(403); exit; }
header('Content-Type: application/json');
echo json_encode(opcache_get_status(false), JSON_PRETTY_PRINT);
EOF

# Odczytaj
curl -s http://127.0.0.1/app/mu-plugins/opcache-stats.php | python3 -m json.tool \
    | tee audit/baseline/opcache-$(date +%Y%m%d).json

# USUŃ natychmiast po odczycie
rm /var/www/html/web/app/mu-plugins/opcache-stats.php
```

Kluczowe metryki z wyniku:
- `opcache_statistics.opcache_hit_rate` → cel **>99%**
- `memory_usage.used_memory_percentage` → jeśli >80%, zwiększ `opcache.memory_consumption` w `php.ini`
- `opcache_statistics.num_cached_scripts` → liczba zakeszowanych plików PHP

### 0.4 Redis stats

```bash
redis-cli INFO stats | grep -E 'keyspace_hits|keyspace_misses|evicted_keys|connected_clients' \
    | tee audit/baseline/redis-$(date +%Y%m%d).txt

redis-cli SLOWLOG GET 50 >> audit/baseline/redis-$(date +%Y%m%d).txt
redis-cli MEMORY STATS | grep -E 'used_memory_human|maxmemory_human|mem_fragmentation_ratio' \
    >> audit/baseline/redis-$(date +%Y%m%d).txt
```

Policz hit ratio ręcznie:
```
hit_ratio = keyspace_hits / (keyspace_hits + keyspace_misses) × 100
```
Cel: **>95%**. Jeśli `evicted_keys > 0` — Redis usuwa dane z braku pamięci → zwiększ `maxmemory` w `redis.conf`.

### 0.5 nginx — timing w logach

Sprawdź aktualny format:
```bash
grep -r "log_format" /etc/nginx/
```

Jeśli brak `$request_time`, dodaj w sekcji `http {}` w `/etc/nginx/nginx.conf`:
```nginx
log_format timed '$remote_addr [$time_local] "$request" $status '
                 '$body_bytes_sent $request_time $upstream_response_time '
                 '"$http_referer" "$http_user_agent"';

access_log /var/log/nginx/access_timed.log timed;
```

```bash
nginx -t && systemctl reload nginx
```

Agreguj po load-testach (kolumny: URL, request_time):
```bash
# Top 20 wolnych ścieżek wg mediany
awk '{print $7, $9}' /var/log/nginx/access_timed.log \
  | sort | awk '{sum[$1]+=$2; n[$1]++} END {for(k in sum) printf "%.3f %s\n", sum[k]/n[k], k}' \
  | sort -rn | head -20 \
  | tee audit/baseline/nginx-timing-$(date +%Y%m%d).txt
```

### 0.6 Xhprof / Tideways (tymczasowo, 24–48h)

Instalacja na VPS:
```bash
pecl install tideways_xhprof
echo "extension=tideways_xhprof.so" > /etc/php/8.3/fpm/conf.d/99-tideways.ini
echo "tideways_xhprof.clock_use_rdtsc=0" >> /etc/php/8.3/fpm/conf.d/99-tideways.ini
systemctl restart php8.3-fpm
```

Trigger przez cookie — wklej do `web/app/mu-plugins/xhprof-trigger.php` (usuń po zebraniu danych):
```php
<?php
// TYMCZASOWY — USUŃ PO ZEBRANIU DANYCH
if (!function_exists('tideways_xhprof_enable')) return;
if (empty($_COOKIE['xhprof_on'])) return;
tideways_xhprof_enable(TIDEWAYS_XHPROF_FLAGS_MEMORY | TIDEWAYS_XHPROF_FLAGS_CPU);
register_shutdown_function(function () {
    $data = tideways_xhprof_disable();
    @mkdir('/tmp/xhprof');
    file_put_contents('/tmp/xhprof/' . uniqid() . '.xhprof', serialize($data));
});
```

Ustaw ciasteczko `xhprof_on=1` w przeglądarce, odwiedź:
1. `admin-ajax.php?action=np_panel_get_reviews`
2. `wp-json/niepodzielni/v1/bot-availability`
3. `/psycholodzy/`
4. `/`

Wygeneruj flamegraph:
```bash
git clone https://github.com/brendangregg/FlameGraph /tmp/FlameGraph
php /tmp/xhprof_ui/utils/xhprof_runs.php   # lub własny renderer
```

**Po zebraniu danych:** usuń `xhprof-trigger.php`, usuń `99-tideways.ini`, `systemctl restart php8.3-fpm`.

### 0.7 DISABLE_WP_CRON — wymagana zmiana na VPS

Dodaj do `/var/www/html/.env`:
```
DISABLE_WP_CRON=true
```

Dodaj do crontab root (`crontab -e`):
```cron
* * * * * /usr/local/bin/wp --path=/var/www/html/web/wp cron event run --due-now --quiet 2>&1 | logger -t wp-cron
```

Sprawdź ścieżkę WP-CLI:
```bash
which wp || (curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp)
```

**Efekt:** eliminuje loopback HTTP request na każdej wizycie anonimowej (WordPress bez tej flagi robi `/wp-cron.php` fetch przy każdym pageview).

---

## Load Testing — symulacja ruchu

Strona ma ~30k użytkowników/miesiąc ale jest środowiskiem testowym — baseline trzeba zebrać pod symulowanym obciążeniem.

### Instalacja k6

```bash
# Ubuntu/Debian
apt install gnupg2 -y
gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
    --keyserver hkp://keyserver.ubuntu.com:80 \
    --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
    | tee /etc/apt/sources.list.d/k6.list
apt update && apt install k6

# Lub bezpośrednio z GitHub releases (bez apt):
curl -sL https://github.com/grafana/k6/releases/latest/download/k6-linux-amd64.tar.gz \
    | tar -xz && mv k6-*/k6 /usr/local/bin/
```

### Uruchomienie testów

```bash
# Test podstawowy (strony HTML) — 12 VU = realny szczyt
k6 run audit/k6/load-test.js \
    -e BASE_URL=https://niepodzielni-dev-01.mixturemarketing.pl \
    --out json=audit/results/k6-pages-$(date +%Y%m%d-%H%M).json

# Test REST API (/bot-availability)
k6 run audit/k6/api-test.js \
    -e BASE_URL=https://niepodzielni-dev-01.mixturemarketing.pl \
    -e BOT_TOKEN=TWOJ_TOKEN \
    --out json=audit/results/k6-api-$(date +%Y%m%d-%H%M).json
```

Przed uruchomieniem testów uzupełnij w `audit/k6/load-test.js` slugi psychologów w tablicy `SINGLES` (linie z komentarzem).

### Alternatywa — wrk (prostszy, bez scenariuszy)

```bash
apt install wrk

# Front page — 4 wątki, 12 połączeń (= normal peak), 60 sekund
wrk -t4 -c12 -d60s --latency \
    https://niepodzielni-dev-01.mixturemarketing.pl/ \
    | tee audit/results/wrk-front-$(date +%Y%m%d).txt

# Listing psychologów
wrk -t4 -c12 -d60s --latency \
    https://niepodzielni-dev-01.mixturemarketing.pl/psycholodzy/ \
    | tee audit/results/wrk-listing-$(date +%Y%m%d).txt

# REST API /bot-availability (wymaga tokenu w Lua skrypcie lub nagłówku)
wrk -t4 -c10 -d30s --latency \
    -H "X-API-Key: TWOJ_TOKEN" \
    "https://niepodzielni-dev-01.mixturemarketing.pl/wp-json/niepodzielni/v1/bot-availability?consult_type=pelno&days=14" \
    | tee audit/results/wrk-bot-api-$(date +%Y%m%d).txt
```

---

## Faza 2b — Do wdrożenia po zebraniu baseline

### AVAIL_CACHE KV — wstawienie realnych ID

Przed deployem Workera:
```bash
cd workers/ai-agent

wrangler kv:namespace create AVAIL_CACHE
# → Skopiuj zwrócone id

wrangler kv:namespace create AVAIL_CACHE --preview
# → Skopiuj zwrócone preview_id
```

Edytuj `workers/ai-agent/wrangler.toml` — zamień placeholder'y:
```toml
[[kv_namespaces]]
binding    = "AVAIL_CACHE"
id         = "WKLEJ_ID_Z_POWYŻEJ"
preview_id = "WKLEJ_PREVIEW_ID_Z_POWYŻEJ"
```

### DB indeksy na wp_postmeta i wp_commentmeta

> Wykonaj po backup bazy. MySQL 8.0 na stronie testowej — ryzyko minimalne.

```sql
-- Sprawdź aktualną strukturę
SHOW INDEX FROM wp_postmeta;
SHOW INDEX FROM wp_commentmeta;

-- Hot keys (porównaj z wynikami slow log / performance_schema)
SELECT meta_key, COUNT(*) AS c
FROM wp_postmeta
GROUP BY meta_key ORDER BY c DESC LIMIT 30;

-- Dodaj indeksy (MySQL 8.0: CREATE INDEX IF NOT EXISTS zamiast ALTER TABLE)
CREATE INDEX IF NOT EXISTS idx_postmeta_key_val
    ON wp_postmeta (meta_key(50), meta_value(20));

CREATE INDEX IF NOT EXISTS idx_commentmeta_key
    ON wp_commentmeta (meta_key(50));

-- Przelicz statystyki
ANALYZE TABLE wp_postmeta;
ANALYZE TABLE wp_commentmeta;

-- Weryfikacja (powinno pokazać: type=ref, key=idx_postmeta_key_val)
EXPLAIN SELECT * FROM wp_postmeta
WHERE meta_key = 'bookero_id_niski' AND meta_value > '';
```

**Uwaga MySQL 8.0:** `ALTER TABLE ... ADD INDEX IF NOT EXISTS` **nie istnieje** w MySQL 8.0 (to składnia MariaDB). Używaj `CREATE INDEX IF NOT EXISTS ... ON tabela (...)`.

### Warunkowy load Bookero CDN

Sprawdź jak script jest enqueue'owany:
```bash
grep -rn "bookero\|cdn.bookero" \
    web/app/themes/niepodzielni-theme/app/ \
    web/app/mu-plugins/ \
    --include="*.php"
```

Cel: `cdn.bookero.pl` (~1.1 MB JS) tylko na stronach z widgetem rezerwacji:
```php
// w setup.php lub podobnym
if (is_singular('psycholog') || has_shortcode(get_post()->post_content ?? '', 'bookero')) {
    wp_enqueue_script('bookero', 'https://cdn.bookero.pl/plugin/...', [], null, true);
}
```

---

## Faza 3 — Strategic (po potwierdzeniu wyników fazy 1–2)

### 3.1 Cloudflare Cache Rules — full-page cache (największy ROI)

W Cloudflare Dashboard → `niepodzielni.pl` → **Rules → Cache Rules**:

**Reguła 1: Cache anonimowych stron**
```
Nazwa: Cache anonymous pages
Warunek (wszystkie muszą być spełnione):
  - URI Path nie pasuje do: /wp-admin* /wp-login* /wp-json* /admin-ajax*
  - Cookie nie zawiera: wordpress_logged_in_  wp-settings-
Akcja:
  - Cache Level: Cache Everything
  - Edge TTL: 1 hour
  - Browser TTL: 5 minutes
```

**Reguła 2: Bypass dla zalogowanych**
```
Nazwa: Bypass for logged-in users
Warunek: Cookie zawiera: wordpress_logged_in_
Akcja: Bypass Cache
```

**Efekt przy 30k/mies:** TTFB dla ~95% ruchu (anonimowi) spada z 300–800ms → 5–30ms (edge node CF).  
To najważniejsza optymalizacja dla Core Web Vitals i Google ranking.

### 3.2 Critical CSS per template

```bash
npm install -D critters
```

Inline CSS powyżej fold per template w Vite build — eliminuje render-blocking CSS, LCP spada o 200–500ms.

### 3.3 Worker KV cache dla embeddingów (embed.ts)

```typescript
// SHA-256(query) → 7-dniowy cache embeddingu
const hashHex = [...new Uint8Array(
    await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text))
)].map(b => b.toString(16).padStart(2,'0')).join('');

const kvKey  = `embed:${hashHex}`;
const cached = await env.EMBED_CACHE?.get(kvKey, 'arrayBuffer');
if (cached) return new Float32Array(cached);

const vector = /* ...wywołanie AI embedding... */;
await env.EMBED_CACHE?.put(kvKey, new Float32Array(vector).buffer, { expirationTtl: 604800 });
```

Dodaj KV namespace `EMBED_CACHE` do `wrangler.toml`. Powtarzające się frazy (depresja, lęki, stres) → ~0ms zamiast ~100–200ms.

### 3.4 Usunięcie legacy Vectorize indexes

Po potwierdzeniu że `VECTORIZE_KNOWLEDGE` działa:
```bash
cd workers/ai-agent
wrangler vectorize delete psycholodzy
wrangler vectorize delete faq
```

Usuń też binding'i z `wrangler.toml` i `Env` interface.

### 3.5 Monitoring — mysqld_exporter + Grafana Cloud

```bash
# mysqld_exporter dla MySQL 8.0
wget https://github.com/prometheus/mysqld_exporter/releases/latest/download/mysqld_exporter-linux-amd64.tar.gz
# Konfiguracja: /etc/mysql-exporter.cnf z [client] user/password
```

Grafana Cloud free tier (10k metrics/month) wystarczy dla tej skali.  
Stack: `node_exporter` + `mysqld_exporter` + `php-fpm_exporter` + `redis_exporter`.

---

## KPI — mierzyć PRZED i PO każdej naprawie

| Metryka | Narzędzie | Baseline | Target |
|---|---|---|---|
| DB queries / `np_panel_get_reviews` | Query Monitor | ~401 | **≤5 ✅** |
| `/bot-availability` latencja (cold) | nginx `$request_time` | ~300ms | **<5ms z KV ✅** |
| DB queries / front page | Query Monitor | ? | ≤40 |
| TTFB p50 / listing (bez CF cache) | nginx logs + wrk | ? | <400ms |
| TTFB p50 / front (z CF cache) | Cloudflare Analytics | ? | <30ms |
| OPcache hit ratio | opcache_get_status | ? | >99% |
| Redis hit ratio | `redis-cli INFO` | ? | >95% |
| JS transferred / front page | Lighthouse | ? | <120 KB gzip |
| AI Worker `/chat` p95 | `wrangler tail` | ? | <2s |
| Core Web Vitals LCP | PageSpeed Insights | ? | <2.5s |

---

## Kolejność realizacji

```
✅ Faza 1  — Backend DB quick wins
✅ Faza 2a — Lazy-load ai-chat + Worker KV cache

⏳ Działania na VPS (Faza 0):
   1. MySQL slow log — /etc/mysql/conf.d/slow-log.cnf + restart mysql
   2. PHP-FPM slowlog — request_slowlog_timeout=500ms + restart php8.3-fpm
   3. DISABLE_WP_CRON=true w .env + system cron (crontab root)
   4. nginx timing format — dodaj $request_time do log_format
   5. Uruchom k6: audit/k6/load-test.js (12 VU, 5 min)
   6. Zbierz MySQL Performance Schema query digest
   7. Zbierz OPcache stats (one-shot script, usunąć po odczycie)
   8. Zbierz Redis INFO stats
   9. Opcjonalnie: Xhprof/Tideways (24h, usunąć po zebraniu)

⏳ Faza 2b (po baseline, ~sprint 2):
  10. wrangler kv:namespace create AVAIL_CACHE → wstaw ID do wrangler.toml
  11. DB indeksy: CREATE INDEX IF NOT EXISTS na wp_postmeta + wp_commentmeta
  12. Warunkowy load Bookero CDN (tylko single psycholog)

⏳ Faza 3 (po wynikach, ~sprint 3):
  13. Cloudflare Cache Rules — full-page cache anonimowych (największy ROI)
  14. Critical CSS inline (critters)
  15. Worker KV cache dla embeddingów
  16. Usuń legacy Vectorize indexes (psycholodzy, faq)
```

---

## Linki i zasoby

- [MySQL 8.0 — slow query log](https://dev.mysql.com/doc/refman/8.0/en/slow-query-log.html)
- [MySQL 8.0 — Performance Schema statement digests](https://dev.mysql.com/doc/refman/8.0/en/performance-schema-statement-digests.html)
- [Percona Toolkit — pt-query-digest](https://www.percona.com/doc/percona-toolkit/LATEST/pt-query-digest.html)
- [k6 dokumentacja](https://k6.io/docs/)
- [Tideways XHProf dla PHP 8.x](https://github.com/tideways/php-xhprof-extension)
- [Cloudflare Cache Rules](https://developers.cloudflare.com/cache/how-to/cache-rules/)
- [Wrangler KV commands](https://developers.cloudflare.com/workers/wrangler/commands/#kv)
- [FlameGraph](https://github.com/brendangregg/FlameGraph)
