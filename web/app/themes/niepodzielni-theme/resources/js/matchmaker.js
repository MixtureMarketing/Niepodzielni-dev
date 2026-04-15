/**
 * Matchmaker v4 — Dopasowanie Psychologa
 *
 * Orkiestrator: łączy State, ScoringEngine i Templates w spójny widget.
 * Logika i szablony żyją w podmodułach — ten plik odpowiada tylko za:
 *   - inicjalizację stanu (z URL / sessionStorage / default)
 *   - renderowanie (deleguje do Templates)
 *   - obsługę zdarzeń DOM (_attachEvents)
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

        // Priorytet przywracania stanu: URL > sessionStorage > domyślny
        const urlState     = restoreFromUrl();
        const sessionState = urlState ? null : restoreFromSession();
        this.state = { ...DEFAULT_STATE, ...( urlState || sessionState || {} ) };

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
            const results     = this._runMatchmaker();          // wypełnia _fullResultsCache
            const fullResults = this._fullResultsCache || [];
            const suggestions = this._getRelaxedSuggestions(); // wypełnia _relaxedCache
            html += tplResults( this.state, results, fullResults, MM_DATA, suggestions, this._showAll );
            saveToUrl( this.state );
        }

        this.el.innerHTML = html;
        this._attachEvents();
        this._relaxedCache = null; // wyczyść po podpięciu eventów — kolejny render odliczy od nowa
    }

    // ─── Event listeners ─────────────────────────────────────────────────────

    _attachEvents() {

        // Przyciski wyboru (krok 1) — who / visitType / pricing / lang
        this.el.querySelectorAll( '.np-mm__choice-btn' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                this._set( { [ btn.dataset.field ]: btn.dataset.value } );
            } );
        } );

        // Toggle "tylko z terminami" (krok 1)
        const availToggle = this.el.querySelector( '[data-action="toggle-available"]' );
        if ( availToggle ) {
            availToggle.addEventListener( 'click', () => {
                this._set( { onlyAvailable: ! this.state.onlyAvailable } );
            } );
        }

        // Kafelki obszarów (krok 2) — logika: zaznacz / promuj do primary / usuń
        this.el.querySelectorAll( '.np-mm__tile' ).forEach( tile => {
            tile.addEventListener( 'click', () => {
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
            } );
        } );

        // Przyciski nurtu (krok 2)
        this.el.querySelectorAll( '.np-mm__nurt-btn' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                const val = btn.dataset.nurt;
                this.state.nurt = val;
                this.el.querySelectorAll( '.np-mm__nurt-btn' ).forEach( b => {
                    b.classList.toggle( 'np-mm__nurt-btn--active', b.dataset.nurt === val );
                } );
            } );
        } );

        // Toggle panelu nurtu (krok 2)
        const nurtToggle = this.el.querySelector( '.np-mm__nurt-toggle' );
        if ( nurtToggle ) {
            nurtToggle.addEventListener( 'click', () => {
                const panel = this.el.querySelector( '.np-mm__nurt-panel' );
                const open  = panel.hidden;
                panel.hidden         = ! open;
                this.state.nurtOpen  = open;
                nurtToggle.textContent = open
                    ? '▲ Ukryj preferencje nurtu'
                    : '▼ Mam preferencje co do nurtu (opcjonalne)';
            } );
        }

        // "Pokaż więcej obszarów"
        const moreBtn = this.el.querySelector( '.np-mm__more-toggle' );
        if ( moreBtn ) {
            moreBtn.addEventListener( 'click', () => {
                const section = this.el.querySelector( '.np-mm__more-section' );
                section.hidden = ! section.hidden;
                const ext = MM_AREAS.filter( a => ! MM_CURATED.includes( a.slug ) ).length;
                moreBtn.textContent = section.hidden
                    ? `Pokaż więcej obszarów (${ ext })`
                    : 'Zwiń';
            } );
        }

        // Wyszukiwarka obszarów (krok 2 — rozwinięta lista)
        const search = this.el.querySelector( '.np-mm__search' );
        if ( search ) {
            search.addEventListener( 'input', () => {
                const q = search.value.toLowerCase();
                this.el.querySelectorAll( '.np-mm__tile-grid--extended .np-mm__tile' ).forEach( t => {
                    t.style.display = t.textContent.toLowerCase().includes( q ) ? '' : 'none';
                } );
            } );
        }

        // Nawigacja dalej / wstecz
        this.el.querySelectorAll( '[data-next]' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                if ( ! btn.disabled ) this._go( parseInt( btn.dataset.next ) );
            } );
        } );
        this.el.querySelectorAll( '[data-back]' ).forEach( btn => {
            btn.addEventListener( 'click', () => this._go( parseInt( btn.dataset.back ) ) );
        } );

        // Reset do początku
        this.el.querySelectorAll( '.np-mm__btn-reset' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                this._showAll = false;
                clearPersistence();
                this.state = { ...DEFAULT_STATE };
                this._render();
            } );
        } );

        // Inline kalendarz Bookero na kartach wyników
        this.el.querySelectorAll( '[data-action="open-cal"]' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                const card = btn.closest( '.np-mm__card' );
                this._toggleCalendar( card );
            } );
        } );

        // "Nie wiem, czego szukam" — fallback do wyników bez obszarów
        const fallbackBtn = this.el.querySelector( '[data-action="fallback"]' );
        if ( fallbackBtn ) {
            fallbackBtn.addEventListener( 'click', () => {
                this._set( { step: 4, areas: [], primaryArea: '', nurt: '' } );
            } );
        }

        // "Pokaż więcej wyników"
        const showMoreBtn = this.el.querySelector( '[data-action="show-more"]' );
        if ( showMoreBtn ) {
            showMoreBtn.addEventListener( 'click', () => {
                this._showAll = true;
                this._render();
            } );
        }

        // Propozycje poluzowania filtrów (ekran "brak wyników")
        // _relaxedCache jest jeszcze zapełniony w tym miejscu (zerowany PO _attachEvents)
        const suggestions = this._getRelaxedSuggestions();
        this.el.querySelectorAll( '[data-relax]' ).forEach( btn => {
            const idx = parseInt( btn.dataset.relax );
            if ( suggestions[ idx ] ) {
                btn.addEventListener( 'click', () => this._set( suggestions[ idx ].patch ) );
            }
        } );
    }

    // ─── Partial update kroku 2 ───────────────────────────────────────────────

    /**
     * Aktualizuje kafelki obszarów i liczniki w kroku 2 bez pełnego re-renderu.
     * Wywołany po każdym kliknięciu kafelka — szybszy niż innerHTML =.
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
     * Przy otwarciu dynamicznie ładuje bookero-compiled.js z CDN i inicjuje widget.
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

        panel.querySelector( '.np-mm__cal-close' ).addEventListener( 'click', () => {
            panel.hidden    = true;
            panel.innerHTML = '';
            card.querySelector( '[data-action="open-cal"]' ).textContent = 'Umów wizytę';
        } );

        card.querySelector( '[data-action="open-cal"]' ).textContent = 'Zamknij kalendarz';

        // Konfiguracja globalna wymagana przez bookero-compiled.js
        window.bookero_config = {
            id:            pluginId,
            container:     containerId,
            type:          'calendar',
            position:      '',
            plugin_css:    false,
            lang:          ( window.niepodzielniBookero || {} ).lang || 'pl',
            custom_config: { use_worker_id: workerId, hide_worker_info: 1 },
        };

        // Usuń poprzedni script i załaduj świeży (Bookero nie obsługuje wielokrotnej inicjalizacji)
        const old = document.querySelector( 'script[data-bk-mm]' );
        if ( old ) old.remove();

        const script       = document.createElement( 'script' );
        script.src         = 'https://cdn.bookero.pl/plugin/v2/js/bookero-compiled.js?t=' + Date.now();
        script.dataset.bkMm = '1';
        script.defer        = false;
        document.body.appendChild( script );

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
