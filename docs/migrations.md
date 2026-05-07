# DB Migrations

Idempotentny runner migracji bazy danych dla projektu Niepodzielni.

## Konwencja

- Pliki migracji w `web/app/mu-plugins/niepodzielni-core/migrations/`.
- Nazwa: `YYYY-MM-<slug>.php` (np. `2026-05-add-postmeta-index.php`).
- Każdy plik definiuje funkcję w namespace `Niepodzielni\Core\Migrations`:
  `np_migration_<slug>(array $options): array`,
  gdzie `<slug>` = nazwa pliku z myślnikami i kropkami zamienionymi na `_`.
- Funkcja **musi** być idempotentna: sprawdzić stan i wykonać DDL/DML tylko jeśli potrzeba.
- Zwraca tablicę: `name, status (applied|skipped|dry-run|error), message, duration_ms, rows_before, rows_after, size_before_mb, size_after_mb`.

Migracje **nie odpalają się automatycznie**. Operator wykonuje je ręcznie w oknie serwisowym.

## Lista migracji

| Data       | Slug                          | Cel                                                                           |
| ---------- | ----------------------------- | ----------------------------------------------------------------------------- |
| 2026-05-07 | `2026-05-add-postmeta-index`  | Composite index `(post_id, meta_key)` na `wp_postmeta` — przyspiesza meta_query w listingu psychologów, Carbon Fields, Bookero ID lookups. |
| 2026-05-07 | `2026-05-cpt-keys-unify`      | Ujednolicenie kluczy postmeta dla 3 CPT eventów (Etap 3 refactoru): kopia `godzina → godzina_rozpoczecia` (warsztaty, grupy-wsparcia) oraz `koszt → cena` (wydarzenia). Idempotent: kopiuje tylko gdy nowy klucz pusty. Stare klucze pozostają w DB (cleanup w osobnej, późniejszej migracji). |
| 2026-05-07 | `2026-05-autoload-cleanup`    | Redukcja rozmiaru autoloaded options w `wp_options` — wyłącza `autoload='no'` dla opcji >100 KB oraz transients zapisanych z `autoload='yes'` (bug w starszych pluginach). Whitelist krytycznych opcji rdzenia (siteurl, home, active_plugins, cron, …). Idempotent. **W trybie `wp np migrate run` ZAWSZE dry-run — realna mutacja tylko przez `wp np migrate autoload-cleanup --yes`.** |

## Uruchamianie — DEV (Docker)

```bash
# Dry-run (pokaż co byłoby wykonane)
docker exec niepodzielni-php wp np migrate run --dry-run

# Wykonaj
docker exec niepodzielni-php wp np migrate run

# Weryfikacja indeksu
docker exec niepodzielni-db mysql bedrock_niepodzielni \
  -e "SHOW INDEX FROM wp_postmeta WHERE Key_name='idx_post_meta';"

# EXPLAIN — przed/po
docker exec niepodzielni-db mysql bedrock_niepodzielni \
  -e "EXPLAIN SELECT * FROM wp_postmeta WHERE post_id = 1 AND meta_key = 'specjalizacje';"
```

Po migracji `key` w EXPLAIN powinien pokazać `idx_post_meta` zamiast `post_id`.

### Weryfikacja `2026-05-cpt-keys-unify`

```bash
# Sprawdź ile postów warsztatów/grup ma już nowy klucz
docker exec niepodzielni-db mysql bedrock_niepodzielni -e "
  SELECT p.post_type, COUNT(*) AS rows_with_new_key
  FROM wp_postmeta pm
  JOIN wp_posts p ON p.ID = pm.post_id
  WHERE p.post_type IN ('warsztaty','grupy-wsparcia') AND pm.meta_key = 'godzina_rozpoczecia'
  GROUP BY p.post_type;"

# Wydarzenia: sprawdź czy `cena` ma wartości
docker exec niepodzielni-db mysql bedrock_niepodzielni -e "
  SELECT COUNT(*) FROM wp_postmeta pm
  JOIN wp_posts p ON p.ID = pm.post_id
  WHERE p.post_type='wydarzenia' AND pm.meta_key='cena' AND pm.meta_value != '';"
```

## Uruchamianie — PROD (operator, okno serwisowe)

> Uwaga: w przypadku `wp_postmeta` na produkcji pliki mogą mieć kilkadziesiąt MB.
> InnoDB online ALTER (ALGORITHM=INPLACE, LOCK=NONE) jest non-blocking dla DML,
> ale i tak wykonujemy w oknie serwisowym.

### 1) Backup DB

```bash
mysqldump --single-transaction --quick --triggers --routines \
  --databases <DB_NAME> | gzip > /tmp/pre-migration-$(date +%F-%H%M).sql.gz
```

### 2) Sprawdź dry-run

```bash
wp --path=/srv/www/niepodzielni.com/current/web/wp \
   np migrate run --dry-run
```

Oczekiwany output: `status: dry-run` z opisem planowanego ALTER TABLE
(albo `status: skipped` jeśli indeks już istnieje).

### 3) Uruchom migrację

```bash
wp --path=/srv/www/niepodzielni.com/current/web/wp \
   np migrate run
```

Oczekiwany status `applied` lub `skipped`. Czas wykonania zalogowany jako `duration_ms`.

### 4) Weryfikacja

```bash
wp --path=/srv/www/niepodzielni.com/current/web/wp db query \
  "SHOW INDEX FROM wp_postmeta WHERE Key_name='idx_post_meta';"

wp --path=/srv/www/niepodzielni.com/current/web/wp db query \
  "EXPLAIN SELECT * FROM wp_postmeta WHERE post_id = 1 AND meta_key = 'specjalizacje';"
```

W kolumnie `key` powinno być `idx_post_meta`, w `Extra` `Using index condition` lub `Using where; Using index`.

### 5) Pomiar listingu psychologów

```bash
# Wyczyść transient cache listingu
wp --path=... transient delete --all

# Mierz curl-em (5 razy, średnia)
for i in 1 2 3 4 5; do
  curl -o /dev/null -s -w "%{time_total}\n" https://niepodzielni.com/psycholodzy/
done
```

## Migracja `2026-05-autoload-cleanup` — procedura operator-on-prod (B7)

> **Specjalna semantyka**: w `wp np migrate run` ta migracja zawsze raportuje
> jako `dry-run`, niezależnie od flag. Realne wyłączenie autoload wymaga
> dedykowanego subcommandu `wp np migrate autoload-cleanup --yes`. Jest to
> celowe — `wp_options.autoload` to wrażliwy obszar i mutacja musi być
> świadoma + wykonana po przeglądzie listy kandydatów.

### Kontekst — dlaczego

Każdy request WP wykonuje:

```sql
SELECT option_name, option_value FROM wp_options WHERE autoload = 'yes';
```

Wynik trafia do `wp_load_alloptions()` i jest serializowany do pamięci PHP.
Wtyczki czasem zapisują transients/cache z `autoload='yes'` lub trzymają
wielomegabajtowe ustawienia jako autoloaded — efekt: dziesiątki MB pamięci
+ koszt deserializacji per request.

### Heurystyka kandydatów

Migracja kwalifikuje opcję do `autoload='no'` jeśli (i NIE jest na whitelist):

- `LENGTH(option_value) > 102_400` (100 KB), LUB
- nazwa zaczyna się od `_transient_` / `_site_transient_`.

Whitelist (NIGDY nie tykamy): `siteurl`, `home`, `blogname`, `blogdescription`,
`admin_email`, `template`, `stylesheet`, `WPLANG`, `blog_charset`, `date_format`,
`time_format`, `gmt_offset`, `timezone_string`, `start_of_week`, `rewrite_rules`,
`active_plugins`, `cron`, `auth_*`, `nonce_*`, `secure_auth_*`, `logged_in_*`.

### 1) Backup wp_options

```bash
wp --path=/srv/www/niepodzielni.com/current/web/wp \
   db query "SELECT option_name, autoload, LENGTH(option_value) FROM wp_options WHERE autoload='yes' ORDER BY LENGTH(option_value) DESC" \
   > /tmp/autoload-snapshot-$(date +%F-%H%M).tsv

mysqldump --single-transaction --quick \
  <DB_NAME> wp_options | gzip > /tmp/wp_options-pre-autoload-$(date +%F-%H%M).sql.gz
```

### 2) Dry-run — przegląd listy

```bash
wp --path=/srv/www/niepodzielni.com/current/web/wp \
   np migrate autoload-cleanup --dry-run
```

Output:

- `status: dry-run`
- `autoload total: X.XX MB → X.XX MB` (przed/po hipotetycznie)
- TOP20 największych autoload=yes
- TARGETS: lista kandydatów z reasonem (`size=NkB,transient`)

**Operator weryfikuje listę.** Jeśli na liście pojawi się opcja krytyczna
dla działania pluginu (np. cache mapy ważny przy każdym requeście),
zgłoś incident i dodaj nazwę do whitelist w migracji przed uruchomieniem.

### 3) Wykonaj

```bash
wp --path=/srv/www/niepodzielni.com/current/web/wp \
   np migrate autoload-cleanup --yes
```

Oczekiwany `status: applied` (lub `skipped` jeśli idempotentnie nic nie zostało).

### 4) Weryfikacja

```bash
# Łączny rozmiar autoload payloadu (powinien spaść)
wp --path=... db query \
  "SELECT ROUND(SUM(LENGTH(option_value))/1024/1024, 2) AS autoload_mb \
   FROM wp_options WHERE autoload='yes';"

# Pomiar TTFB (5 prób)
for i in 1 2 3 4 5; do
  curl -o /dev/null -s -w "%{time_starttransfer}\n" https://niepodzielni.com/
done

# Sprawdź czy kluczowe ścieżki działają (smoke test)
curl -fsS -o /dev/null https://niepodzielni.com/ \
  && curl -fsS -o /dev/null https://niepodzielni.com/psycholodzy/ \
  && echo OK
```

### Rollback (autoload-cleanup)

Z snapshot z kroku 1:

```bash
# Punktowy rollback pojedynczej opcji:
wp --path=... db query \
  "UPDATE wp_options SET autoload='yes' WHERE option_name='<NAME>';"

# Pełny rollback z mysqldump:
gunzip < /tmp/wp_options-pre-autoload-<TS>.sql.gz | wp --path=... db cli
```

Po rollbacku można ponownie uruchomić `--dry-run` i przeanalizować listę.

## Rollback

Jeśli indeks powoduje regresję (mało prawdopodobne — secondary index nie wpływa
na poprawność, tylko na plan zapytań):

```bash
wp --path=... db query "DROP INDEX idx_post_meta ON wp_postmeta;"
```

Po rollbacku można ponownie uruchomić `wp np migrate run` — runner wykryje brak
indeksu i utworzy go ponownie (idempotencja).
