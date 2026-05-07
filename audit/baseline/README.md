# Baseline — wyniki pomiarów przed optymalizacjami

Katalog na pliki zebrane z VPS przed i po kolejnych fazach optymalizacji.

## Struktura

```
baseline/
  redis-stats-YYYYMMDD.txt      — wyjście redis-cli INFO stats
  opcache-stats-YYYYMMDD.json   — wyjście opcache_get_status()
  slow-digest-YYYYMMDD.txt      — wyjście pt-query-digest lub mysqldumpslow
  nginx-timing-YYYYMMDD.txt     — agregacja $request_time z nginx access log
  qm-export-YYYYMMDD.csv        — Query Monitor export (queries per URL)

results/
  k6-YYYYMMDD-HHMM.json         — raw k6 output (--out json)
  wrk-frontpage-YYYYMMDD.txt    — wrk benchmark front page
  wrk-bot-api-YYYYMMDD.txt      — wrk benchmark /bot-availability
```

## Jak zebrać

Patrz sekcja "Faza 0" w: `docs/PERFORMANCE_PLAN.md`
