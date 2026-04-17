/**
 * Matchmaker v4 — Dopasowanie Psychologa
 *
 * Orkiestrator: łączy State, ScoringEngine i Templates w spójny widget.
 * Logika i szablony żyją w podmodułach — ten plik odpowiada tylko za:
 *   - inicjalizację stanu (z URL / sessionStorage / default)
 *   - renderowanie (deleguje do Templates)
 *   - obsługę zdarzeń DOM (Event Delegation — jeden listener click + jeden input)
 *   - inline kalendarz Bookero (_toggleCalendar)
 *   - partial update kroku 2 (_updateStep2UI)
 *
 * Mount point:  <div id="np-matchmaker">
 * Data source:  window.NP_MATCHMAKER (inline script z PHP shortcode)
 */

import {
    DEFAULT_STATE,
    saveToSession,
    restoreFromSession,
    saveToUrl,
    restoreFromUrl,
    clearPersistence,
} from './matchmaker/State.js';

import {
    W,
    countWith,
    runMatchmakerWith,
    getRelaxedSuggestions,
} from './matchmaker/ScoringEngine.js';

import {
    esc,
    tplProgress,
    tplStep1,
    tplStep2,
    tplResults,
} from './matchmaker/Templates.js';

import { debounce } from './utils/debounce.js';

// ─── Dane z PHP (window.NP_MATCHMAKER ustawiane przez shortcode) ──────────────

const MM_DATA    = window.NP_MATCHMAKER || {};
const MM_AREAS   = MM_DATA.obszary     || [];
const MM_CURATED = MM_DATA.curated     || [];

// ─── Klasa główna ─────────────────────────────────────────────────────────────

class NpMatchmaker {

    constructor( el ) {
        this.el                = el;
        this._showAll          = false;
        this._fullResultsCache = null;
        this._relaxedCache     = null;
        this._isFirstRender    = true;

        // Priorytet przywracania stanu: URL > sessionStorage > domyślny
        const urlState     = restoreFromUrl();
        const sessionState = urlState ? null : restoreFromSession();
        this.state = { ...DEFAULT_STATE, ...( urlState || sessionState || {} ) };

        // Zdarzenia podpinane RAZ — Event Delegation na this.el (nie na dzieciach)
        this._initEvents();
        this._render();
    }

    // ─── Zarządzanie stanem ────────────────────────────────────────────────────

    _set( patch ) {
        Object.assign( this.state, patch );
        saveToSession( this.state );
        this._render();
    }

    _go( step ) {
        this._set( { step } );
    }

    _countFiltered() {
        return countWith( MM_DATA, this.state );
    }

    // ─── Scoring ──────────────────────────────────────────────────────────────

    _runMatchmaker() {
        const full = runMatchmakerWith( MM_DATA, this.state );
        this._fullResultsCache = full;
        return this._showAll ? full : full.slice( 0, W.MAX_RESULTS );
    }

    _getRelaxedSuggestions() {
        if ( this._relaxedCache ) return this._relaxedCache;
        this._relaxedCache = getRelaxedSuggestions( MM_DATA, this.state );
        return this._relaxedCache;
    }

    // ─── Render ───────────────────────────────────────────────────────────────

    _render() {
        this._relaxedCache     = null;
        this._fullResultsCache = null;

        const { step } = this.state;
        let html = step < 4 ? tplProgress( this.state ) : '';

        if ( step === 1 ) {
            html += tplStep1( this.state, MM_DATA );

        } else if ( step === 2 ) {
            html += tplStep2( this.state, MM_DATA, this._countFiltered() );

        } else if ( step === 4 ) {
            const results     = this._runMatchmaker();
            const fullResults = this._fullResultsCache || [];
            const suggestions = this._getRelaxedSuggestions();
            html += tplResults( this.state, results, fullResults, MM_DATA, suggestions, this._showAll );
            saveToUrl( this.state );
        }

        this.el.innerHTML = html;
        this._relaxedCache = null;

        // Zarządzanie focusem (A11y) — pomiń pierwsze renderowanie (nie kradnij focusu strony)
        if ( ! this._isFirstRender ) {
            const stepTitle = this.el.querySelector( '.np-mm__step-title' );
            if ( stepTitle ) stepTitle.focus();
        }
        this._isFirstRender = false;
    }

    // ─── Event Delegation — podpinane RAZ w konstruktorze ─────────────────────

    /**
     * Rejestruje JEDEN listener 'click' i JEDEN 'input' na this.el.
     * Identyfikacja celu przez closest() — działa poprawnie po każdym re-renderze
     * (innerHTML = ...) bez ponownego podpinania listenerów.
     *
     * Korzyści wobec podejścia forEach+addEventListener per-element:
     *   - Brak wycieków pamięci (nie tworzymy N closures przy każdym renderze)
     *   - Niższe zużycie pamięci przy dużej liście wyników (np. 50+ kart)
     *   - Jeden wpis w event queue zamiast N — szybsze _render()
     */
    _initEvents() {

        // ── Click delegation ──────────────────────────────────────────────────
        this.el.addEventListener( 'click', ( e ) => {

            // 1. Przyciski wyboru opcji (krok 1): who / visitType / pricing / lang
            const choiceBtn = e.target.closest( '.np-mm__choice-btn' );
            if ( choiceBtn ) {
                this._set( { [ choiceBtn.dataset.field ]: choiceBtn.dataset.value } );
                return;
            }

            // 2. Toggle "tylko z terminami" (krok 1)
            if ( e.target.closest( '[data-action="toggle-available"]' ) ) {
                this._set( { onlyAvailable: ! this.state.onlyAvailable } );
                return;
            }

            // 3. Kafelki obszarów (krok 2)
            const tile = e.target.closest( '.np-mm__tile' );
            if ( tile ) {
                const slug  = tile.dataset.area;
                const areas = [ ...this.state.areas ];
                const idx   = areas.indexOf( slug );
                let { primaryArea } = this.state;

                if ( idx >= 0 ) {
                    if ( slug === primaryArea ) {
                        // Klik na primary → usuń i przypisz primary do kolejnego
                        areas.splice( idx, 1 );
                        primaryArea = areas[ 0 ] || '';
                    } else {
                        // Klik na zaznaczony (nie primary) → promuj do primary
                        primaryArea = slug;
                    }
                } else {
                    if ( areas.length >= 3 ) {
                        tile.classList.add( 'np-mm__tile--shake' );
                        setTimeout( () => tile.classList.remove( 'np-mm__tile--shake' ), 400 );
                        return;
                    }
                    areas.push( slug );
                    if ( ! primaryArea ) primaryArea = slug;
                }

                // Partial update zamiast pełnego re-renderu — szybsze, bez mignięcia
                this.state.areas       = areas;
                this.state.primaryArea = primaryArea;
                this._updateStep2UI();
                return;
            }

            // 4. Przyciski nurtu (krok 2)
            const nurtBtn = e.target.closest( '.np-mm__nurt-btn' );
            if ( nurtBtn ) {
                const val = nurtBtn.dataset.nurt;
                this.state.nurt = val;
                this.el.querySelectorAll( '.np-mm__nurt-btn' ).forEach( b => {
                    b.classList.toggle( 'np-mm__nurt-btn--active', b.dataset.nurt === val );
                } );
                return;
            }

            // 5. Toggle panelu nurtu (krok 2)
            const nurtToggle = e.target.closest( '[data-toggle="nurt"]' );
            if ( nurtToggle ) {
                const panel = this.el.querySelector( '.np-mm__nurt-panel' );
                const open  = panel.hidden; // true = panel był ukryty → teraz go otwieramy
                panel.hidden         = ! open;
                this.state.nurtOpen  = open;
                nurtToggle.textContent = open
                    ? '▲ Ukryj preferencje nurtu'
                    : '▼ Mam preferencje co do nurtu (opcjonalne)';
                return;
            }

            // 6. "Pokaż więcej obszarów" (krok 2)
            const moreBtn = e.target.closest( '[data-more="areas"]' );
            if ( moreBtn ) {
                const section = this.el.querySelector( '.np-mm__more-section' );
                section.hidden = ! section.hidden;
                const ext = MM_AREAS.filter( a => ! MM_CURATED.includes( a.slug ) ).length;
                moreBtn.textContent = section.hidden
                    ? `Pokaż więcej obszarów (${ ext })`
                    : 'Zwiń';
                return;
            }

            // 7. Nawigacja dalej
            const nextBtn = e.target.closest( '[data-next]' );
            if ( nextBtn && ! nextBtn.disabled ) {
                this._go( parseInt( nextBtn.dataset.next ) );
                return;
            }

            // 8. Nawigacja wstecz
            const backBtn = e.target.closest( '[data-back]' );
            if ( backBtn ) {
                this._go( parseInt( backBtn.dataset.back ) );
                return;
            }

            // 9. Reset do początku
            if ( e.target.closest( '.np-mm__btn-reset' ) ) {
                this._showAll = false;
                clearPersistence();
                this.state = { ...DEFAULT_STATE };
                this._render();
                return;
            }

            // 10. Zamknij inline kalendarz — przycisk ✕ generowany przez _toggleCalendar
            const calClose = e.target.closest( '.np-mm__cal-close' );
            if ( calClose ) {
                const panel = calClose.closest( '.np-mm__inline-cal' );
                const card  = calClose.closest( '.np-mm__card' );
                if ( panel ) {
                    panel.hidden    = true;
                    panel.innerHTML = '';
                }
                const openCalBtn = card?.querySelector( '[data-action="open-cal"]' );
                if ( openCalBtn ) openCalBtn.textContent = 'Umów wizytę';
                return;
            }

            // 11. Otwórz / zamknij inline kalendarz Bookero
            if ( e.target.closest( '[data-action="open-cal"]' ) ) {
                const card = e.target.closest( '.np-mm__card' );
                if ( card ) this._toggleCalendar( card );
                return;
            }

            // 12. Fallback "nie wiem, czego szukam"
            if ( e.target.closest( '[data-action="fallback"]' ) ) {
                this._set( { step: 4, areas: [], primaryArea: '', nurt: '' } );
                return;
            }

            // 13. "Pokaż więcej wyników"
            if ( e.target.closest( '[data-action="show-more"]' ) ) {
                this._showAll = true;
                this._render();
                return;
            }

            // 14. Propozycje poluzowania filtrów (ekran "brak wyników")
            const relaxBtn = e.target.closest( '[data-relax]' );
            if ( relaxBtn ) {
                const idx         = parseInt( relaxBtn.dataset.relax );
                const suggestions = this._getRelaxedSuggestions();
                if ( suggestions[ idx ] ) this._set( suggestions[ idx ].patch );
                return;
            }
        } );

        // ── Input delegation — wyszukiwarka obszarów (krok 2) ─────────────────
        // Debounce 200 ms: filtrowanie kafelków to operacja DOM (style.display) bez
        // scoringu — 200 ms daje responsywność z ochroną przed flood-em na smartfonach.
        this.el.addEventListener( 'input', debounce( ( e ) => {
            if ( ! e.target.closest( '.np-mm__search' ) ) return;
            const q = e.target.value.toLowerCase();
            this.el.querySelectorAll( '.np-mm__tile-grid--extended .np-mm__tile' ).forEach( t => {
                t.style.display = t.textContent.toLowerCase().includes( q ) ? '' : 'none';
            } );
        }, 200 ) );
    }

    // ─── Partial update kroku 2 ───────────────────────────────────────────────

    /**
     * Aktualizuje kafelki obszarów i liczniki w kroku 2 bez pełnego re-renderu.
     * Wywołany po każdym kliknięciu kafelka — szybszy niż innerHTML =.
     *
     * Regiony #np-mm-counter i #np-mm-area-counter mają aria-live="polite",
     * więc czytniki ekranu automatycznie ogłoszą zmianę bez dodatkowej obsługi.
     */
    _updateStep2UI() {
        const { areas, primaryArea } = this.state;
        const selected  = areas.length;
        const counter   = this.el.querySelector( '#np-mm-area-counter' );
        const nextBtn   = this.el.querySelector( '[data-next="4"]' );
        const liveCount = this.el.querySelector( '#np-mm-counter' );

        if ( counter ) {
            counter.innerHTML = selected === 0
                ? 'Wybierz 1–3 obszary'
                : `Wybrano: <strong>${ selected }/3</strong>`;
        }
        if ( nextBtn ) {
            nextBtn.disabled = selected === 0;
            nextBtn.classList.toggle( 'np-mm__btn-next--disabled', selected === 0 );
        }
        if ( liveCount ) {
            const count = this._countFiltered();
            liveCount.innerHTML = count > 0
                ? `Pasujących specjalistów: <strong>${ count }</strong>`
                : '<span class="np-mm__counter-warn">Brak specjalistów dla tych filtrów</span>';
        }

        // Aktualizuj klasy i etykiety kafelków w miejscu (bez re-renderu całej siatki)
        this.el.querySelectorAll( '.np-mm__tile' ).forEach( tile => {
            const slug       = tile.dataset.area;
            const isSelected = areas.includes( slug );
            const isPrimary  = isSelected && slug === primaryArea;
            const areaName   = MM_AREAS.find( a => a.slug === slug )?.name || slug;

            tile.classList.toggle( 'np-mm__tile--primary',  isPrimary );
            tile.classList.toggle( 'np-mm__tile--selected', isSelected && ! isPrimary );
            tile.setAttribute( 'aria-pressed', String( isSelected ) );
            tile.textContent = isPrimary ? `★ ${ areaName }` : areaName;
        } );

        // Pokaż/ukryj podpowiedź o primary (★)
        let hint = this.el.querySelector( '.np-mm__primary-hint' );
        if ( selected >= 1 ) {
            if ( ! hint ) {
                hint             = document.createElement( 'p' );
                hint.className   = 'np-mm__primary-hint';
                hint.textContent = 'Kliknij wybrany obszar ponownie, aby oznaczyć go jako główny problem (★ = podwójna waga).';
                const areaCounter = this.el.querySelector( '#np-mm-area-counter' );
                if ( areaCounter ) areaCounter.after( hint );
            }
        } else if ( hint ) {
            hint.remove();
        }

        saveToSession( this.state );
    }

    // ─── Inline kalendarz Bookero ─────────────────────────────────────────────

    /**
     * Otwiera / zamyka inline widget Bookero wewnątrz karty wyników.
     *
     * Strategia ładowania skryptu (OBSZAR 3):
     *   1. Pierwsze otwarcie → inject <script> BEZ cache-bustera (?t=).
     *      Przeglądarka cachuje skrypt po pierwszym pobraniu z CDN.
     *   2. Kolejne otwarcia → window.bookero_config aktualizowany przed reinit.
     *      Sprawdzamy znane API reinit dostawcy. Jeśli brak, re-execute przez
     *      replaceWith() — przeglądarka serwuje z cache (0 ms sieci).
     *
     * Zamknięcie panelu obsługiwane przez Event Delegation w _initEvents()
     * (delegacja na .np-mm__cal-close) — brak lokalnego addEventListener.
     *
     * @param {HTMLElement} card  Element .np-mm__card
     */
    _toggleCalendar( card ) {
        const panel = card.querySelector( '.np-mm__inline-cal' );
        if ( ! panel ) return;

        // Zamknij inne otwarte panele kalendarza
        this.el.querySelectorAll( '.np-mm__inline-cal:not([hidden])' ).forEach( p => {
            if ( p !== panel ) {
                p.hidden    = true;
                p.innerHTML = '';
                const otherBtn = p.closest( '.np-mm__card' )?.querySelector( '[data-action="open-cal"]' );
                if ( otherBtn ) otherBtn.textContent = 'Umów wizytę';
            }
        } );

        // Toggle — zamknij jeśli już otwarty
        if ( ! panel.hidden ) {
            panel.hidden    = true;
            panel.innerHTML = '';
            card.querySelector( '[data-action="open-cal"]' ).textContent = 'Umów wizytę';
            return;
        }

        const pricing  = this.state.pricing;
        const isPelno  = pricing !== 'nisko';
        const bkPelno  = parseInt( card.dataset.bkPelno ) || 0;
        const bkNisko  = parseInt( card.dataset.bkNisko ) || 0;

        let workerId = isPelno ? bkPelno : bkNisko;
        if ( ! workerId && pricing === 'both' ) workerId = bkPelno || bkNisko;

        // Brak worker ID — przekieruj na profil
        if ( ! workerId ) {
            window.location.href = card.dataset.link;
            return;
        }

        const pluginId = ( isPelno || pricing === 'both' )
            ? ( MM_DATA.pelnoPluginId || MM_DATA.niskoPluginId )
            : ( MM_DATA.niskoPluginId || MM_DATA.pelnoPluginId );

        const containerId = `np-mm-bk-${ card.dataset.pid }`;

        panel.hidden    = false;
        panel.innerHTML = `
            <div class="np-mm__cal-header">
                <strong>Zarezerwuj wizytę — ${ esc( card.querySelector( '.np-mm__card-name' ).textContent ) }</strong>
                <button class="np-mm__cal-close" aria-label="Zamknij kalendarz">✕</button>
            </div>
            <div class="bookero-preloader-wrapper">
                <div class="bookero-preloader"><div class="bookero-preloader-spinner"></div></div>
            </div>
            <div id="${ containerId }" style="display:none!important;visibility:hidden!important;"></div>`;

        card.querySelector( '[data-action="open-cal"]' ).textContent = 'Zamknij kalendarz';

        // Konfiguracja globalna — aktualizowana PRZED każdą inicjalizacją widgetu
        window.bookero_config = {
            id:            pluginId,
            container:     containerId,
            type:          'calendar',
            position:      '',
            plugin_css:    false,
            lang:          ( window.niepodzielniBookero || {} ).lang || 'pl',
            custom_config: { use_worker_id: workerId, hide_worker_info: 1 },
        };

        const BOOKERO_SRC    = 'https://cdn.bookero.pl/plugin/v2/js/bookero-compiled.js';
        const existingScript = document.querySelector( 'script[data-bk-mm]' );

        if ( ! existingScript ) {
            // Pierwsze otwarcie — załaduj raz (bez ?t= → browser/CDN cache aktywne)
            const script        = document.createElement( 'script' );
            script.src          = BOOKERO_SRC;
            script.dataset.bkMm = '1';
            document.body.appendChild( script );

        } else {
            // Kolejne otwarcia — skrypt już w DOM.
            // Jeśli dostawca udostępnia publiczne API reinit: użyj go.
            // Jeśli nie: re-execute przez replaceWith() — przeglądarka użyje cache CDN.
            const reinit = window.BookeroCalendar?.init
                        ?? window.Bookero?.init
                        ?? window.bookeroInit;

            if ( typeof reinit === 'function' ) {
                reinit( window.bookero_config );
            } else {
                const clone         = document.createElement( 'script' );
                clone.src           = BOOKERO_SRC;
                clone.dataset.bkMm  = '1';
                existingScript.replaceWith( clone );
            }
        }

        setTimeout( () => panel.scrollIntoView( { behavior: 'smooth', block: 'nearest' } ), 100 );
    }
}

// ─── Bootstrap ────────────────────────────────────────────────────────────────

function npMatchmakerInit() {
    const el = document.getElementById( 'np-matchmaker' );
    if ( el && window.NP_MATCHMAKER && ! el.dataset.mmLoaded ) {
        el.dataset.mmLoaded = '1';
        new NpMatchmaker( el );
    }
}

if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', npMatchmakerInit );
} else {
    npMatchmakerInit();
}
