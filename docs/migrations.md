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

## Rollback

Jeśli indeks powoduje regresję (mało prawdopodobne — secondary index nie wpływa
na poprawność, tylko na plan zapytań):

```bash
wp --path=... db query "DROP INDEX idx_post_meta ON wp_postmeta;"
```

Po rollbacku można ponownie uruchomić `wp np migrate run` — runner wykryje brak
indeksu i utworzy go ponownie (idempotencja).
