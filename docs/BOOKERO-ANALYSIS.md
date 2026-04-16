# Analiza integracji Bookero — niepodzielni.com

Źródła: `niepodzielni.com.har` + `bookero-compiled.js`  
Data analizy: 2026-04-14

---

## 1. Globalne ID kalendarzy Bookero

Bookero ma **dwa osobne konta/kalendarze** dla dwóch typów konsultacji:

| Typ | Bookero hash (`id` w config) |
|-----|------------------------------|
| Pełnopłatny | `5tu8AC22Akna` |
| Niskopłatny | `hxRnUexTsSvc` |

To są hashe **kont** Bookero — identyczne dla wszystkich psychologów danego typu.  
Muszą być zapisane w WP options: `np_bookero_cal_pelny` i `np_bookero_cal_nisko`.

---

## 2. Per-psycholog: Worker ID

Każdy psycholog ma własne `worker_id` w systemie Bookero:

- `bookero_id_pelny` (postmeta) = worker ID w kalendarzu pełnopłatnym
- `bookero_id_niski` (postmeta) = worker ID w kalendarzu niskopłatnym

Przykład: Jakub Baluś → `bookero_id_niski = 29603`

---

## 3. Konfiguracja `window.bookero_config`

bookero-compiled.js czyta **synchronicznie** `window.bookero_config` przy załadowaniu skryptu.

### Struktura (wymagana):

```javascript
window.bookero_config = {
  id: 'hxRnUexTsSvc',          // Global Bookero calendar hash (pelno/nisko)
  container: 'bookero_render_target',  // ID elementu DOM do renderowania
  type: 'calendar',
  position: '',
  plugin_css: false,            // CSS Bookero NIE jest ładowane (overridujemy własnym)
  lang: 'pl',
  custom_config: {
    use_worker_id: 29603,       // Worker ID psychologa (bookero_id_niski/pelny)
    hide_worker_info: 1         // Ukrywa info o pracowniku w widgecie
  }
};
```

### Kluczowe:
- `id` ≠ worker ID — to hash całego kalendarza/konta
- `use_worker_id` = per-psycholog, filtruje widok do jednego pracownika
- `plugin_css: false` — własny CSS w bookero-calendar.css

---

## 4. Poprawna struktura HTML na stronie psychologa

Na podstawie HAR z produkcji (`data-calendar-type="product"`):

```html
<!-- Wrapper z atrybutami dla bookero-init.js -->
<div id="bookero_wrapper"
     class="bookero-calendar-wrapper"
     data-calendar-type="product"
     data-id-pelno=""
     data-id-nisko="29603">
    <div class="bookero-preloader-wrapper"></div>
</div>

<!-- Target dla renderowania — POZA bookero_wrapper! -->
<div id="bookero_render_target"></div>
<div id="what_calendar"></div>
```

**WAŻNE:** `bookero_render_target` musi być **poza** `bookero_wrapper`, jako osobny element na tym samym poziomie DOM.

---

## 5. Sekwencja zapytań API Bookero

Po załadowaniu bookero-compiled.js następuje seria zapytań do `plugin.bookero.pl`:

```
1. GET /plugin-api/v2/init
   ?bookero_id=hxRnUexTsSvc&lang=pl&type=calendar
   → konfiguracja, lista workers, usługi

2. GET /plugin-api/v2/getCustomDuration
   ?bookero_id=hxRnUexTsSvc&service=50604
   → czy usługa ma customową długość

3. GET /plugin-api/v2/getPeriodicity
   ?bookero_id=hxRnUexTsSvc&service=50604
   → czy usługa jest cykliczna

4. GET /plugin-api/v2/getMonth
   ?bookero_id=hxRnUexTsSvc&service=50604&worker=29603&plus_months=0
   → dane miesiąca (które dni mają wolne terminy)
   Odpowiedź: { days: { "15": { hours: [{hour:"17:00", valid:1}] } } }

5. GET /plugin-api/v2/getMonthDay
   ?bookero_id=...&date=...&hour=...&service=...&worker=...
   → szczegóły konkretnego dnia po kliknięciu

6. GET /plugin-api/v2/getPrice
   → cena po wyborze terminu
```

---

## 6. Odpowiedź `init` — struktura workers

```json
{
  "result": 1,
  "workers_list": [
    {
      "id": 26480,
      "name": "Adam Radomski",
      "avatar": "https://cdn.bookero.pl/cache/200x200/...",
      "avatar_color": "#b6d8e9",
      "avatar_initials": "AR"
    }
  ],
  "require_mail": 1,
  "require_phone": 1,
  "show_prices": 2,
  "auto_accept": 1
}
```

---

## 7. Jak bookero-init.js inicjuje kalendarz

```javascript
// bookero-init.js (nasz plik)
const id_pel = calendarWrapper.dataset.idPelno || '';  // data-id-pelno
const id_nis = calendarWrapper.dataset.idNisko || '';  // data-id-nisko

// bkr = window.niepodzielniBookero (z wp_localize_script)
const config = {
    id: isPelno ? bkr.pelnoId : bkr.niskoId,          // Hash konta Bookero
    container: 'bookero_render_target',
    type: 'calendar',
    position: '',
    plugin_css: false,
    lang: bkr.lang || 'pl',
    custom_config: {
        use_worker_id: isPelno ? id_pel : id_nis,       // Worker ID psychologa
        hide_worker_info: 1
    }
};

window.bookero_config = config;

// Dynamicznie ładuje bookero-compiled.js, który natychmiast czyta window.bookero_config
const script = document.createElement('script');
script.src = 'https://cdn.bookero.pl/plugin/v2/js/bookero-compiled.js';
document.body.appendChild(script);
```

---

## 8. Wspólny kalendarz (bk-shared-calendar) vs kalendarz Bookero

| | Bookero widget | Wspólny kalendarz |
|---|---|---|
| Renderuje | bookero-compiled.js (zewnętrzny) | bk-shared-calendar.js (własny) |
| Dane | Bezpośrednio z plugin.bookero.pl | Przez WP AJAX → Bookero API → cache |
| Użycie | Strona indywidualnego psychologa | Listingi pełno/nisko |
| Rezerwacja | Przez widget Bookero | Przez własny formularz → bk_create_booking |

---

## 9. Checklist poprawnej konfiguracji

- [ ] `np_bookero_cal_pelny` ustawione w WP options (Settings → Niepodzielni) = `5tu8AC22Akna`
- [ ] `np_bookero_cal_nisko` ustawione w WP options = `hxRnUexTsSvc`
- [ ] Każdy psycholog ma `bookero_id_pelny` i/lub `bookero_id_niski` w postmeta
- [ ] `bookero_render_target` jest **poza** `bookero_wrapper` w HTML
- [ ] `window.niepodzielniBookero.pelnoId` i `.niskoId` przekazane przez wp_localize_script

---

## 10. Przykłady z produkcji (HAR)

| Psycholog | URL | bookero_id_pelny | bookero_id_niski |
|-----------|-----|-----------------|-----------------|
| Jakub Baluś | `/osoby/jakub-balus/?konsultacje=nisko` | `` (brak) | `29603` |
| Adam Radomski | `/osoby/adam-radomski/?konsultacje=pelno` | (ustalony) | — |
| Katarzyna Wzorek | `/osoby/katarzyna-wzorek/?konsultacje=pelno` | (ustalony) | — |
| Ilona Kołodziej | `/osoby/ilona-kolodziej/?konsultacje=nisko` | — | (ustalona) |
