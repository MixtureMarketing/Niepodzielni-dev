# Emetor np_* duplicates — analiza bezpieczeństwa

**Data analizy:** 2026-05-08
**Branch:** `ops/emetor-duplicates-analysis`
**Sprint:** PR Sprint #2 — DB audit C1
**Status:** ANALYSIS ONLY — `DELETE` NIE wykonany

---

## TL;DR — KRYTYCZNE ODWRÓCENIE PREMISY

Pierwotne założenie tasku zakładało, że:
- `_np_*` (z prefiksem) = Carbon Fields kanon, niewidoczny w UI
- `np_*` (bez prefiksu) = pozostałość Emetor, public, do usunięcia

**To założenie jest niepoprawne dla CPT `osrodek_pomocy`.**

Po analizie source-of-truth (`web/app/mu-plugins/niepodzielni-core/cpt/23-osrodki-metaboxes.php`)
oraz wszystkich konsumentów meta (templates, REST endpoint, CLI import, services):

- **`np_*` (bez prefiksu) = KANON** — czytane przez wszystkie templates, REST API i geocoding service.
  Carbon Fields rejestruje pola pod tymi nazwami przez `Field::make('text', 'np_ulica', ...)`.
- **`_np_*` (z prefiksem) = wewnętrzna kopia Carbon Fields** — zapisywana przez CF (i jawnie
  duplikowana przez `ImportPsychomapyCommand`) na potrzeby renderu w admin UI. Komentarz w kodzie
  importu wprost mówi: *"podwójny zapis (plain + _prefix) wymagany przez Carbon Fields"*
  (`ImportPsychomapyCommand.php:219`, `:225`, `:267`, `:285`).

**Wniosek:** usunięcie kluczy `np_*` (bez prefiksu) **ZEPSUJE**:
- single-osrodek_pomocy.blade.php (cała strona profilu ośrodka — adres, kontakt, godziny, social),
- REST endpoint `/wp-json/niepodzielni/v1/psychomapa` (mapa ośrodków pobiera `np_telefon`, `np_logo_url`, `np_miasto` po `meta_key`),
- GeocodingService (czyta `np_ulica`, `np_nr_domu`, `np_kod_pocztowy`, `np_miasto` przez `carbon_get_post_meta`),
- ImportPsychomapyCommand (po pierwszym imporcie zapisze je z powrotem — race),
- listę kolumn w admin (CPT zgloszenia czyta `np_email`).

Jeśli "duplikaty" mają być posprzątane, należy **odwrócić strategię**: rozważyć usunięcie
wariantu **`_np_*`** (CF internal) tylko jeżeli CF poprawnie odbudowuje je przy edycji
posta — co wymaga **osobnego** dochodzenia (CF v3 może odczytywać z `_np_*` przy renderze
metaboxa). Zalecenie: **na tym etapie NIE usuwać żadnego wariantu** dopóki nie zostanie
zweryfikowane, że Carbon Fields admin UI poprawnie czyta z `np_*` bez wariantu `_np_*`.

---

## Ewidencja — gdzie używany jest który wariant

### Wariant `np_*` (bez prefiksu) — KANONICZNY, AKTYWNIE CZYTANY

| Klucz | Plik | Linia | Sposób odczytu |
|---|---|---|---|
| `np_ulica` | `web/app/themes/niepodzielni-theme/resources/views/single-osrodek_pomocy.blade.php` | 10 | `get_post_meta` |
| `np_ulica` | `web/app/mu-plugins/niepodzielni-core/services/GeocodingService.php` | 124 | `carbon_get_post_meta` |
| `np_nr_domu` | `views/single-osrodek_pomocy.blade.php` | 11 | `get_post_meta` |
| `np_nr_domu` | `services/GeocodingService.php` | 125 | `carbon_get_post_meta` |
| `np_nr_mieszkania` | `views/single-osrodek_pomocy.blade.php` | 12 | `get_post_meta` |
| `np_kod_pocztowy` | `views/single-osrodek_pomocy.blade.php` | 13 | `get_post_meta` |
| `np_kod_pocztowy` | `services/GeocodingService.php` | 126 | `carbon_get_post_meta` |
| `np_miasto` | `views/single-osrodek_pomocy.blade.php` | 14 | `get_post_meta` |
| `np_miasto` | `services/GeocodingService.php` | 127 | `carbon_get_post_meta` |
| `np_miasto` | `web/app/mu-plugins/niepodzielni-core/api/21-psychomapa-endpoint.php` | 60, 66 | SQL `meta_key = 'np_miasto'` |
| `np_wojewodztwo` | `views/single-osrodek_pomocy.blade.php` | 15 | `get_post_meta` |
| `np_telefon` | `views/single-osrodek_pomocy.blade.php` | 16 | `get_post_meta` |
| `np_telefon` | `api/21-psychomapa-endpoint.php` | 61, 66 | SQL |
| `np_telefon_2` | `views/single-osrodek_pomocy.blade.php` | 17 | `get_post_meta` |
| `np_telefon_3` | `views/single-osrodek_pomocy.blade.php` | 18 | `get_post_meta` |
| `np_email` | `views/single-osrodek_pomocy.blade.php` | 19 | `get_post_meta` |
| `np_email` | `web/app/mu-plugins/niepodzielni-core/cpt/22-cpt-zgloszenia.php` | 135, 150 | admin column |
| `np_www` | `views/single-osrodek_pomocy.blade.php` | 20 | `get_post_meta` |
| `np_facebook` | `views/single-osrodek_pomocy.blade.php` | 21 | `get_post_meta` |
| `np_instagram` | `views/single-osrodek_pomocy.blade.php` | 22 | `get_post_meta` |
| `np_tiktok` | `views/single-osrodek_pomocy.blade.php` | 23 | `get_post_meta` |
| `np_logo_url` | `views/single-osrodek_pomocy.blade.php` | 9 | `get_post_meta` |
| `np_logo_url` | `api/21-psychomapa-endpoint.php` | 62, 66 | SQL |
| `lat` | `views/single-osrodek_pomocy.blade.php` | 24 | `get_post_meta` |
| `lat` | `api/21-psychomapa-endpoint.php` | 66 | SQL |
| `lng` | `views/single-osrodek_pomocy.blade.php` | 25 | `get_post_meta` |
| `lng` | `api/21-psychomapa-endpoint.php` | 66 | SQL |
| `{pon,wt,sr,czw,pt,sb,nd}_otwarcie` | `views/single-osrodek_pomocy.blade.php` | 151 (loop) | `get_post_meta` (klucz dynamiczny w loopie) |
| `{...}_zamkniecie` | `views/single-osrodek_pomocy.blade.php` | 152 | dynamic loop |
| `{...}_zamkniete` | `views/single-osrodek_pomocy.blade.php` | 153 | dynamic loop |

**Konsumenci zapisujący `np_*` (CLI):**
`web/app/mu-plugins/niepodzielni-core/cli/ImportPsychomapyCommand.php` linie:
- `:222–226` `saveMeta()` — pętla po `META_MAP` zapisuje **i** `np_*` **i** `_np_*`
- `:266–267` logo — oba warianty
- `:284–285` godziny — oba warianty
- `:324–327` lat/lng — oba warianty

### Wariant `_np_*` (z prefiksem) — wewnętrzny CF + 2 markery importu

| Klucz | Plik | Linia | Notatka |
|---|---|---|---|
| `_np_address_hash` | `services/GeocodingService.php` | 136, 155 | marker hash adresu — **TYLKO ten wariant istnieje, brak `np_address_hash`** |
| `_np_address_hash` | `cli/ImportPsychomapyCommand.php` | 311, 333 | jw. |
| `_np_imported` | `cli/ImportPsychomapyCommand.php` | 128, 148 | timestamp ostatniego importu — **TYLKO ten wariant** |
| `_np_*` (pozostałe — `_np_ulica`, `_np_telefon`, ...) | tylko zapisywane przez CF / `ImportPsychomapyCommand::saveMeta` z komentarzem *"podwójny zapis wymagany przez Carbon Fields"* | — | **Brak miejsca w kodzie, gdzie `_np_X` (poza markerami `_imported`/`_address_hash`) byłoby ODCZYTYWANE.** Są to dane CF używane przez CF runtime do renderu metaboxa w admin. |

---

## Klasyfikacja — 3 kubełki

### KUBEŁEK A — BEZPIECZNE DO USUNIĘCIA

**PUSTY.** Żaden klucz `np_*` (bez prefiksu) z listy nie jest "bezpieczny do usunięcia",
bo wszystkie są aktywnie odczytywane przez templates lub REST endpoint.

(Gdyby task został wykonany na ślepo z pierwotnym założeniem, doszłoby do utraty
widoczności adresów, telefonów, e-maili, social i godzin otwarcia 253 ośrodków
na froncie + zepsucia mapy psychomapa.)

### KUBEŁEK B — UŻYWANE, MIGRACJA WYMAGANA PRZED USUNIĘCIEM

Wszystkie poniższe klucze `np_*` (bez `_`) są **aktywnie czytane**. Aby je usunąć
musiałby najpierw nastąpić refactor wszystkich konsumentów na `_np_*` (lub
`carbon_get_post_meta`, które abstrahuje obie reprezentacje).

Lista (29 kluczy × 253 posty ≈ 7337 row, plus markery):

**Adres (6):** `np_ulica`, `np_nr_domu`, `np_nr_mieszkania`, `np_kod_pocztowy`, `np_miasto`, `np_wojewodztwo`
**Kontakt (9):** `np_telefon`, `np_telefon_2`, `np_telefon_3`, `np_email`, `np_www`, `np_logo_url`, `np_facebook`, `np_instagram`, `np_tiktok`
**Lat/Lng (2):** `lat`, `lng`
**Godziny (21):** `{pon,wt,sr,czw,pt,sb,nd}_{otwarcie,zamkniecie,zamkniete}`

Procedura migracji (jeśli kiedyś zechcemy zostawić tylko jeden wariant):

1. Refactor `single-osrodek_pomocy.blade.php`: zamień każdy `get_post_meta($post_id, 'np_X', true)`
   na `carbon_get_post_meta($post_id, 'np_X')` (CF czyta z `_np_X` natywnie).
2. Refactor `21-psychomapa-endpoint.php` SQL: zamień `meta_key = 'np_miasto'` na `meta_key = '_np_miasto'`
   (zmiana inwazyjna — trzeba testować perf, bo to jeden duży `JOIN`).
3. Refactor `22-cpt-zgloszenia.php` admin column: `np_email` → `_np_email`.
4. **Sprawdzić**, czy CF v3 zapisuje wartości tylko w `_np_*` (jeśli tak — usuń podwójny zapis
   z `ImportPsychomapyCommand::saveMeta/saveLogo/saveHours/maybeGeocode`).
5. Smoke test: profile ośrodków, mapa, REST endpoint, admin grid zgloszeń.
6. Migracja DB: `DELETE FROM wp_postmeta WHERE meta_key IN (...)` dla wariantu **bez** `_`.

**UWAGA:** kierunek migracji może być również odwrotny — usunąć `_np_*` i zostawić `np_*`
jako kanon. Wymaga to weryfikacji, czy CF admin metabox poprawnie odczyta wartość, gdy
dostępny jest tylko `np_X` bez wariantu `_np_X`. To pytanie do CF source / dev test.
Bez tej weryfikacji **żaden** wariant nie jest bezpieczny do delete.

### KUBEŁEK C — NIEPEWNE / DYNAMICZNE

- **Godziny otwarcia (`{pon..nd}_{otwarcie,zamkniecie,zamkniete}`):** odczytywane w pętli w
  `single-osrodek_pomocy.blade.php:151-153`. Nazwa klucza składana z `$prefix` — `grep` po
  pełnej nazwie zwraca 0, ale faktycznie KAŻDY z 21 kluczy jest używany. Sklasyfikowane jako
  KUBEŁEK B (mimo dynamicznego loopu).

- **`_np_address_hash`, `_np_imported`:** UNIKALNE markery — nie mają wariantu bez `_`.
  **Nie usuwać** — zniknie idempotencja importu (wymusi ponowne geokodowanie 253 ośrodków
  i przekroczy rate limit Nominatim 1 req/sec).

- **Czy CF v3 rzeczywiście zapisuje "podwójnie"?** Komentarz w `ImportPsychomapyCommand.php:219`
  to twierdzi, ale to kod **importu CSV**, nie CF runtime. Czyli to faktycznie sam import
  pisze podwójnie — CF prawdopodobnie zapisuje TYLKO `_np_*` przy edycji w admin.
  Konsekwencja: jeśli redaktor edytuje ośrodek w CF metaboxie, **wartość `np_*` (bez `_`)
  NIE zostanie zaktualizowana** — front pokaże stare dane do następnego importu CSV.
  **TO JEST POTENCJALNY BUG.** Wymaga weryfikacji ręcznej (edytuj ośrodek → zapisz →
  `wp post meta list <id>` → zobacz, czy `np_telefon` się zmieniło).

---

## Sugerowane następne kroki dla operatora (NIE w tym PR)

1. **NIE wykonuj `DELETE` na `np_*` ani `_np_*`** dopóki nie potwierdzisz czego CF używa przy edycji.
2. Ręczna weryfikacja CF behavior:
   ```bash
   wp post meta list <ID_OSRODKA> --keys=np_telefon,_np_telefon
   # zmień telefon w admin UI (osrodek_pomocy edit screen) → zapisz
   wp post meta list <ID_OSRODKA> --keys=np_telefon,_np_telefon
   ```
   Jeśli `np_telefon` NIE zmieniło się → **bug w CF flow** → naprawić zanim cokolwiek deletować.
3. Po wybraniu strategii (jeden wariant kanon) → osobny PR z:
   - refactorem konsumentów (templates, SQL, services),
   - `wp np migrate` skryptem usuwającym przeciwny wariant,
   - rollback planem (bo to ~9500 row produkcji).
4. Rozważyć usunięcie podwójnego zapisu z `ImportPsychomapyCommand::saveMeta` po decyzji.

---

## Załącznik — pliki przeanalizowane

- `web/app/mu-plugins/niepodzielni-core/cpt/21-carbon-fields.php` — CF boot (nie dotyczy osrodek_pomocy bezpośrednio)
- `web/app/mu-plugins/niepodzielni-core/cpt/22-cpt-osrodki.php` — rejestracja CPT
- `web/app/mu-plugins/niepodzielni-core/cpt/23-osrodki-metaboxes.php` — **source of truth dla pól CF**
- `web/app/mu-plugins/niepodzielni-core/cpt/22-cpt-zgloszenia.php` — admin column `np_email`
- `web/app/mu-plugins/niepodzielni-core/cli/ImportPsychomapyCommand.php` — import CSV, podwójny zapis
- `web/app/mu-plugins/niepodzielni-core/services/GeocodingService.php` — czyta `np_*` przez CF API
- `web/app/mu-plugins/niepodzielni-core/api/21-psychomapa-endpoint.php` — REST endpoint, raw SQL po `meta_key`
- `web/app/themes/niepodzielni-theme/resources/views/single-osrodek_pomocy.blade.php` — frontend ośrodka

**Wynik audytu:** żadnych keys do bezpiecznego DELETE. Wszystkie `np_*` aktywnie używane;
`_np_*` to wewnętrzna reprezentacja CF + 2 markery (`_imported`, `_address_hash`) bez
wariantu publicznego. Pierwotna premisa tasku odwrócona — bez dodatkowej weryfikacji
flow Carbon Fields żaden DELETE nie jest bezpieczny.
