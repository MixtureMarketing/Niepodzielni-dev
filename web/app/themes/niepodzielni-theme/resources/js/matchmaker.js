/**
 * Matchmaker v3 — Dopasowanie Psychologa
 *
 * Formularz 2-krokowy + fuzzy scoring + inline kalendarz Bookero.
 * Nowe: filtr języka, "tylko z terminami", persistencja (session + URL),
 * "pokaż więcej", bonus za specjalizację.
 *
 * Mount point: <div id="np-matchmaker">
 * Data source:  window.NP_MATCHMAKER (inline script z PHP shortcode)
 */

const MM_DATA     = window.NP_MATCHMAKER || {};
const MM_PSY      = MM_DATA.psychologists || [];
const MM_AREAS    = MM_DATA.obszary       || [];
const MM_NURTY    = MM_DATA.nurty         || [];
const MM_CURATED  = MM_DATA.curated       || [];
const MM_CLUSTERS = MM_DATA.areaClusters  || {};
const MM_FAMILIES = MM_DATA.nurtFamilies  || {};
const MM_SPEC_BON = MM_DATA.specBonuses   || {};
const MM_LANGS    = MM_DATA.jezyki_list   || [];

// ─── Scoring weights ────────────────────────────────────────────────────────
const W = {
    AREA_EXACT:   1.0,
    AREA_CLUSTER: 0.5,
    NURT_EXACT:   1.0,
    NURT_FAMILY:  0.5,
    PRIMARY_MULT: 2.0,
    PRECISION_W:  0.8,
    AVAIL_7:      0.5,
    AVAIL_14:     0.25,
    AVAIL_30:     0.1,
    SPEC_BONUS:   0.5,  // max bonus za jedną specjalizację
    MAX_RESULTS:  5,
    MAX_FULL:     10,
};

const SESSION_KEY = 'np_mm_state';

const DEFAULT_STATE = {
    step:          1,
    who:           'adult',
    visitType:     '',
    pricing:       'both',
    lang:          '',
    onlyAvailable: false,
    areas:         [],
    primaryArea:   '',
    nurt:          '',
    nurtOpen:      false,
};

class NpMatchmaker {
    constructor( el ) {
        this.el               = el;
        this._fullResultsCache = null;
        this._relaxedCache    = null;

        // Restore priority: URL params > sessionStorage > default
        const urlState     = this._restoreFromUrl();
        const sessionState = urlState ? null : this._restoreFromSession();
        this.state = { ...DEFAULT_STATE, ...( urlState || sessionState || {} ) };

        this._render();
    }

    // ─── State ──────────────────────────────────────────────────────────────

    _set( patch ) {
        Object.assign( this.state, patch );
        this._saveToSession();
        this._render();
    }
    _go( step ) { this._set( { step } ); }

    // ─── Persistence: sessionStorage ────────────────────────────────────────

    _saveToSession() {
        try { sessionStorage.setItem( SESSION_KEY, JSON.stringify( this.state ) ); } catch ( e ) {}
    }

    _restoreFromSession() {
        try {
            const raw = sessionStorage.getItem( SESSION_KEY );
            return raw ? { ...DEFAULT_STATE, ...JSON.parse( raw ) } : null;
        } catch ( e ) { return null; }
    }

    // ─── Persistence: URL params ─────────────────────────────────────────────

    _saveToUrl() {
        const { who, visitType, pricing, lang, onlyAvailable, areas, primaryArea, nurt } = this.state;
        const p = new URLSearchParams();
        if ( who !== 'adult' )    p.set( 'who',       who );
        if ( visitType )          p.set( 'type',      visitType );
        if ( pricing !== 'both' ) p.set( 'pricing',   pricing );
        if ( lang )               p.set( 'lang',      lang );
        if ( onlyAvailable )      p.set( 'available', '1' );
        if ( areas.length )       p.set( 'areas',     areas.join( ',' ) );
        if ( primaryArea )        p.set( 'primary',   primaryArea );
        if ( nurt )               p.set( 'nurt',      nurt );
        const qs = p.toString();
        history.replaceState( null, '', qs ? '?' + qs : window.location.pathname );
    }

    _restoreFromUrl() {
        const p = new URLSearchParams( window.location.search );
        if ( ! p.has( 'areas' ) && ! p.has( 'pricing' ) && ! p.has( 'who' ) && ! p.has( 'type' ) ) return null;
        const areas = p.get( 'areas' ) ? p.get( 'areas' ).split( ',' ).filter( Boolean ) : [];
        return {
            ...DEFAULT_STATE,
            who:           p.get( 'who' )       || 'adult',
            visitType:     p.get( 'type' )       || '',
            pricing:       p.get( 'pricing' )    || 'both',
            lang:          p.get( 'lang' )        || '',
            onlyAvailable: p.get( 'available' ) === '1',
            areas,
            primaryArea:   p.get( 'primary' )    || ( areas[ 0 ] || '' ),
            nurt:          p.get( 'nurt' )        || '',
            nurtOpen:      !! p.get( 'nurt' ),
            step:          areas.length ? 4 : 1,
        };
    }

    _clearPersistence() {
        try { sessionStorage.removeItem( SESSION_KEY ); } catch ( e ) {}
        history.replaceState( null, '', window.location.pathname );
    }

    // ─── Hard filter count (live counter in Step 2) ──────────────────────────

    _countFiltered() {
        return this._countWith( this.state );
    }

    /** Liczy tylko przez hard filtry — szybkie, bez scoringu */
    _countWith( st ) {
        const { who, visitType, pricing, lang, onlyAvailable } = st;
        return MM_PSY.filter( p => {
            if ( who === 'couple'         && ! p.spec.includes( 'terapia-par' ) )      return false;
            if ( visitType === 'Online'       && ! p.wizyta.includes( 'Online' ) )       return false;
            if ( visitType === 'Stacjonarnie' && ! p.wizyta.includes( 'Stacjonarnie' ) ) return false;
            if ( pricing === 'pelno' && ! p.has_pelno ) return false;
            if ( pricing === 'nisko' && ! p.has_nisko ) return false;
            if ( lang && ! ( p.jezyki || [] ).includes( lang ) ) return false;
            if ( onlyAvailable && ! p.sort_date ) return false;
            return true;
        } ).length;
    }

    // ─── Core fuzzy scoring engine ───────────────────────────────────────────

    _runMatchmakerWith( st ) {
        const { who, visitType, pricing, lang, onlyAvailable, areas, primaryArea, nurt } = st;

        // Phase 1: Hard filters
        let pool = MM_PSY.filter( p => {
            if ( who === 'couple'         && ! p.spec.includes( 'terapia-par' ) )      return false;
            if ( visitType === 'Online'       && ! p.wizyta.includes( 'Online' ) )       return false;
            if ( visitType === 'Stacjonarnie' && ! p.wizyta.includes( 'Stacjonarnie' ) ) return false;
            if ( pricing === 'pelno' && ! p.has_pelno ) return false;
            if ( pricing === 'nisko' && ! p.has_nisko ) return false;
            if ( lang && ! ( p.jezyki || [] ).includes( lang ) ) return false;
            if ( onlyAvailable && ! p.sort_date ) return false;
            return true;
        } );

        const areaNameMap = Object.fromEntries( MM_AREAS.map( a => [ a.slug, a.name ] ) );
        const today = new Date();
        today.setHours( 0, 0, 0, 0 );

        // Phase 2: Scoring
        pool = pool.map( p => {
            let score = 0;
            const matchedAreas = [];
            const clusterAreas = [];
            let nurtMatch = 'none';

            // Area scoring — exact + cluster fuzzy
            areas.forEach( slug => {
                const mult = ( slug === primaryArea && primaryArea ) ? W.PRIMARY_MULT : 1.0;

                if ( p.obszary.includes( slug ) ) {
                    score += W.AREA_EXACT * mult;
                    matchedAreas.push( areaNameMap[ slug ] || slug );
                } else {
                    const userCluster = MM_CLUSTERS[ slug ];
                    if ( userCluster > 0 ) {
                        const relSlug = p.obszary.find( s => MM_CLUSTERS[ s ] === userCluster );
                        if ( relSlug ) {
                            score += W.AREA_CLUSTER * mult;
                            const relName = areaNameMap[ relSlug ] || relSlug;
                            if ( ! clusterAreas.includes( relName ) ) clusterAreas.push( relName );
                        }
                    }
                }
            } );

            // Specialization bonus — specjalizacja wzmacnia dopasowanie do obszaru
            p.spec.forEach( specSlug => {
                const bonuses = MM_SPEC_BON[ specSlug ];
                if ( ! bonuses ) return;
                areas.forEach( areaSlug => {
                    if ( bonuses[ areaSlug ] !== undefined ) {
                        score += bonuses[ areaSlug ];
                    }
                } );
            } );

            // Precision bonus — wyspecjalizowani wyżej niż generaliści
            const exactCount = matchedAreas.length;
            if ( exactCount > 0 && p.obszary.length > 0 ) {
                score += ( exactCount / p.obszary.length ) * W.PRECISION_W;
            }

            // Nurt scoring — exact + family fuzzy
            if ( nurt ) {
                if ( p.nurty.includes( nurt ) ) {
                    score += W.NURT_EXACT;
                    nurtMatch = 'exact';
                } else {
                    const userFamily = MM_FAMILIES[ nurt ];
                    if ( userFamily && p.nurty.some( n => MM_FAMILIES[ n ] === userFamily ) ) {
                        score += W.NURT_FAMILY;
                        nurtMatch = 'family';
                    }
                }
            }

            // Availability bonus
            if ( p.sort_date && p.sort_date.length === 8 ) {
                const d = new Date(
                    parseInt( p.sort_date.slice( 0, 4 ) ),
                    parseInt( p.sort_date.slice( 4, 6 ) ) - 1,
                    parseInt( p.sort_date.slice( 6, 8 ) )
                );
                const days = Math.round( ( d - today ) / 86400000 );
                if ( days >= 0 ) {
                    if      ( days < 7  ) score += W.AVAIL_7;
                    else if ( days < 14 ) score += W.AVAIL_14;
                    else if ( days < 30 ) score += W.AVAIL_30;
                }
            }

            return { ...p, matchScore: score, matchedAreas, clusterAreas, nurtMatch };
        } );

        // Phase 3: Sort
        pool.sort( ( a, b ) => {
            if ( b.matchScore !== a.matchScore ) return b.matchScore - a.matchScore;
            if ( a.sort_date && b.sort_date ) return a.sort_date.localeCompare( b.sort_date );
            if ( a.sort_date ) return -1;
            if ( b.sort_date ) return  1;
            return Math.random() - 0.5;
        } );

        return pool.slice( 0, W.MAX_FULL );
    }

    _runMatchmaker() {
        const full = this._runMatchmakerWith( this.state );
        this._fullResultsCache = full;
        return this._showAll ? full : full.slice( 0, W.MAX_RESULTS );
    }

    // ─── Relaxed suggestions (0 wyników) ────────────────────────────────────

    _getRelaxedSuggestions() {
        if ( this._relaxedCache ) return this._relaxedCache;
        const s = this.state;
        const suggestions = [];

        if ( s.visitType ) {
            const count = this._countWith( { ...s, visitType: '' } );
            if ( count > 0 ) suggestions.push( {
                label: `${ count } specjalist${ count === 1 ? 'ę' : 'ów' } online i stacjonarnie`,
                patch: { visitType: '' },
            } );
        }
        if ( s.pricing !== 'both' ) {
            const count = this._countWith( { ...s, pricing: 'both' } );
            if ( count > 0 ) suggestions.push( {
                label: `${ count } specjalist${ count === 1 ? 'ę' : 'ów' } (obie opcje cenowe)`,
                patch: { pricing: 'both' },
            } );
        }
        if ( s.lang ) {
            const count = this._countWith( { ...s, lang: '' } );
            if ( count > 0 ) suggestions.push( {
                label: `${ count } specjalist${ count === 1 ? 'ę' : 'ów' } (wszystkie języki)`,
                patch: { lang: '' },
            } );
        }
        if ( s.onlyAvailable ) {
            const count = this._countWith( { ...s, onlyAvailable: false } );
            if ( count > 0 ) suggestions.push( {
                label: `${ count } specjalist${ count === 1 ? 'ę' : 'ów' } (w tym bez terminu w kalendarzu)`,
                patch: { onlyAvailable: false },
            } );
        }
        if ( s.who === 'couple' ) {
            const count = this._countWith( { ...s, who: 'adult' } );
            if ( count > 0 ) suggestions.push( {
                label: `${ count } specjalist${ count === 1 ? 'ę' : 'ów' } w terapii indywidualnej`,
                patch: { who: 'adult' },
            } );
        }

        this._relaxedCache = suggestions;
        return suggestions;
    }

    // ─── Match reason sentence ───────────────────────────────────────────────

    _buildMatchReason( p ) {
        const nurtNameMap = Object.fromEntries( MM_NURTY.map( n => [ n.slug, n.name ] ) );
        const parts = [];

        if ( p.matchedAreas.length > 0 ) {
            parts.push( 'Specjalizuje się w: ' + p.matchedAreas.join( ', ' ) );
        } else if ( p.clusterAreas.length > 0 ) {
            parts.push( 'Pokrewne obszary: ' + p.clusterAreas.join( ', ' ) );
        }

        if ( p.nurtMatch === 'exact' && this.state.nurt ) {
            parts.push( nurtNameMap[ this.state.nurt ] || this.state.nurt );
        } else if ( p.nurtMatch === 'family' && this.state.nurt ) {
            parts.push( 'Pokrewny nurt: ' + ( nurtNameMap[ this.state.nurt ] || this.state.nurt ) );
        }

        if ( p.sort_date && p.sort_date.length === 8 ) {
            const today = new Date();
            today.setHours( 0, 0, 0, 0 );
            const d = new Date(
                parseInt( p.sort_date.slice( 0, 4 ) ),
                parseInt( p.sort_date.slice( 4, 6 ) ) - 1,
                parseInt( p.sort_date.slice( 6, 8 ) )
            );
            const days = Math.round( ( d - today ) / 86400000 );
            if ( days >= 0 ) {
                parts.push(
                    days === 0 ? 'Termin dziś'
                    : days === 1 ? 'Termin jutro'
                    : `Termin za ${ days } dni`
                );
            }
        }

        return parts.join( ' · ' );
    }

    // ─── Render dispatcher ───────────────────────────────────────────────────

    _render() {
        this._relaxedCache    = null;
        this._fullResultsCache = null;
        const { step } = this.state;
        let html = step < 4 ? this._tplProgress() : '';
        if      ( step === 1 ) html += this._tplStep1();
        else if ( step === 2 ) html += this._tplStep2();
        else if ( step === 4 ) { html += this._tplResults(); this._saveToUrl(); }
        this.el.innerHTML = html;
        this._attachEvents();
        this._relaxedCache = null;
    }

    // ─── Progress ───────────────────────────────────────────────────────────

    _tplProgress() {
        const { step } = this.state;
        const steps = [ 'Filtry', 'Czego szukasz?' ];
        const dots  = steps.map( ( label, i ) => {
            const n   = i + 1;
            const cls = n < step ? 'done' : n === step ? 'active' : '';
            return `<div class="np-mm__prog-step ${ cls }">
                        <div class="np-mm__prog-dot">${ n < step ? '✓' : n }</div>
                        <span>${ label }</span>
                    </div>`;
        } ).join( '<div class="np-mm__prog-line"></div>' );
        return `<div class="np-mm__progress">${ dots }</div>`;
    }

    // ─── Step 1 ─────────────────────────────────────────────────────────────

    _tplStep1() {
        const { who, visitType, pricing, lang, onlyAvailable } = this.state;

        const langSection = MM_LANGS.length > 0 ? `
            <div class="np-mm__section">
                <p class="np-mm__label">Język wizyty?</p>
                <div class="np-mm__choice-group np-mm__choice-group--wrap">
                    ${ this._choiceBtn( 'lang', '', 'Dowolny', 'Każdy język', lang ) }
                    ${ MM_LANGS.map( l => this._choiceBtn( 'lang', l.slug, l.name, '', lang ) ).join( '' ) }
                </div>
            </div>` : '';

        return `
        <div class="np-mm__step" data-step="1">
            <h2 class="np-mm__step-title">Powiedz nam o swoich potrzebach</h2>
            <p class="np-mm__step-desc">Kilka pytań pomoże nam znaleźć najlepszego specjalistę.</p>

            <div class="np-mm__section">
                <p class="np-mm__label">Dla kogo szukasz wsparcia?</p>
                <div class="np-mm__choice-group">
                    ${ this._choiceBtn( 'who', 'adult',  'Dla mnie',  'Osoba dorosła', who ) }
                    ${ this._choiceBtn( 'who', 'couple', 'Dla pary',  'Pary / Małżeństwa', who ) }
                </div>
            </div>

            <div class="np-mm__section">
                <p class="np-mm__label">Preferowana forma wizyty?</p>
                <div class="np-mm__choice-group">
                    ${ this._choiceBtn( 'visitType', '',             'Wszędzie',    'Online lub stacjonarnie', visitType ) }
                    ${ this._choiceBtn( 'visitType', 'Online',       'Online',      'Spotkanie przez internet', visitType ) }
                    ${ this._choiceBtn( 'visitType', 'Stacjonarnie', 'W gabinecie', 'Spotkanie w gabinecie',    visitType ) }
                </div>
            </div>

            <div class="np-mm__section">
                <p class="np-mm__label">Rodzaj konsultacji?</p>
                <div class="np-mm__choice-group">
                    ${ this._choiceBtn( 'pricing', 'both',  'Obie opcje',  'Pokaż wszystkich specjalistów', pricing ) }
                    ${ this._choiceBtn( 'pricing', 'pelno', 'Pełnopłatna', 'Standardowa cena (~145 zł)',    pricing ) }
                    ${ this._choiceBtn( 'pricing', 'nisko', 'Niskopłatna', 'Obniżona cena (~55 zł)',         pricing ) }
                </div>
            </div>

            ${ langSection }

            <div class="np-mm__section">
                <button class="np-mm__availability-toggle ${ onlyAvailable ? 'np-mm__availability-toggle--active' : '' }"
                        data-action="toggle-available">
                    ${ onlyAvailable ? '✓ ' : '' }Tylko specjaliści z wolnymi terminami
                </button>
            </div>

            <div class="np-mm__actions">
                <button class="np-mm__btn-next" data-next="2">Dalej →</button>
            </div>
        </div>`;
    }

    _choiceBtn( field, value, label, desc, current ) {
        const active = current === value ? 'np-mm__choice-btn--active' : '';
        return `<button class="np-mm__choice-btn ${ active }" data-field="${ field }" data-value="${ value }">
                    <span class="np-mm__choice-label">${ label }</span>
                    ${ desc ? `<span class="np-mm__choice-desc">${ desc }</span>` : '' }
                </button>`;
    }

    // ─── Step 2 ─────────────────────────────────────────────────────────────

    _tplStep2() {
        const { areas, primaryArea, nurt, nurtOpen } = this.state;
        const selected = areas.length;
        const areaMap  = Object.fromEntries( MM_AREAS.map( a => [ a.slug, a.name ] ) );
        const curated  = MM_CURATED.filter( s => areaMap[ s ] );
        const extended = MM_AREAS.filter( a => ! MM_CURATED.includes( a.slug ) );
        const count    = this._countFiltered();

        const tileHtml = ( slug, name ) => {
            const isSelected = areas.includes( slug );
            const isPrimary  = isSelected && slug === primaryArea;
            const cls        = isPrimary ? 'np-mm__tile--primary' : ( isSelected ? 'np-mm__tile--selected' : '' );
            const label      = isPrimary ? `★ ${ esc( name ) }` : esc( name );
            return `<button class="np-mm__tile ${ cls }" data-area="${ slug }" aria-pressed="${ isSelected }">${ label }</button>`;
        };

        const nurtBtns = MM_NURTY.map( n => {
            const active = nurt === n.slug ? 'np-mm__nurt-btn--active' : '';
            return `<button class="np-mm__nurt-btn ${ active }" data-nurt="${ n.slug }">${ esc( n.name ) }</button>`;
        } ).join( '' );

        const nurtNoneActive = ! nurt ? 'np-mm__nurt-btn--active' : '';

        return `
        <div class="np-mm__step" data-step="2">
            <h2 class="np-mm__step-title">Co sprawia Ci trudność?</h2>
            <p class="np-mm__step-desc">Wybierz maksymalnie 3 obszary, które dotyczą Twojej sytuacji.</p>

            <div class="np-mm__live-counter" id="np-mm-counter">
                ${ count > 0
                    ? `Pasujących specjalistów: <strong>${ count }</strong>`
                    : '<span class="np-mm__counter-warn">Brak specjalistów dla tych filtrów</span>' }
            </div>

            <div class="np-mm__area-counter" id="np-mm-area-counter">
                ${ selected === 0 ? 'Wybierz 1–3 obszary' : `Wybrano: <strong>${ selected }/3</strong>` }
            </div>

            ${ selected >= 1
                ? `<p class="np-mm__primary-hint">Kliknij wybrany obszar ponownie, aby oznaczyć go jako główny problem (★ = podwójna waga).</p>`
                : '' }

            <div class="np-mm__tile-grid">
                ${ curated.map( s => tileHtml( s, areaMap[ s ] ) ).join( '' ) }
            </div>

            <div class="np-mm__more-section" hidden>
                <input type="text" class="np-mm__search" placeholder="Szukaj obszaru..." aria-label="Szukaj obszaru pomocy">
                <div class="np-mm__tile-grid np-mm__tile-grid--extended">
                    ${ extended.map( a => tileHtml( a.slug, a.name ) ).join( '' ) }
                </div>
            </div>

            ${ extended.length > 0
                ? `<button class="np-mm__more-toggle" data-more="areas">Pokaż więcej obszarów (${ extended.length })</button>`
                : '' }

            <div class="np-mm__nurt-section">
                <button class="np-mm__nurt-toggle" data-toggle="nurt">
                    ${ nurtOpen ? '▲ Ukryj preferencje nurtu' : '▼ Mam preferencje co do nurtu (opcjonalne)' }
                </button>
                <div class="np-mm__nurt-panel" ${ nurtOpen ? '' : 'hidden' }>
                    <p class="np-mm__label">Preferowany nurt terapeutyczny:</p>
                    <div class="np-mm__nurt-grid">
                        <button class="np-mm__nurt-btn ${ nurtNoneActive }" data-nurt="">Nie wiem / Dowolny</button>
                        ${ nurtBtns }
                    </div>
                </div>
            </div>

            <div class="np-mm__fallback-section">
                <p>Nie wiesz, czego szukasz? To normalne.</p>
                <button class="np-mm__btn-fallback" data-action="fallback">Potrzebuję rozmowy — pomóżcie mi wybrać</button>
            </div>

            <div class="np-mm__actions np-mm__actions--split">
                <button class="np-mm__btn-back" data-back="1">← Wstecz</button>
                <button class="np-mm__btn-next ${ selected === 0 ? 'np-mm__btn-next--disabled' : '' }"
                        data-next="4" ${ selected === 0 ? 'disabled' : '' }>Znajdź specjalistę</button>
            </div>
        </div>`;
    }

    // ─── Results ────────────────────────────────────────────────────────────

    _tplResults() {
        const results  = this._runMatchmaker();
        const full     = this._fullResultsCache || [];
        const { areas, nurt, pricing } = this.state;
        const telefon  = MM_DATA.telefon || '';
        const isFallback = areas.length === 0;

        if ( results.length === 0 ) {
            const suggestions = this._getRelaxedSuggestions();
            return `<div class="np-mm__step np-mm__results-empty">
                <h2>Nie znaleźliśmy specjalisty</h2>
                <p>Brak specjalistów dla wybranych kryteriów.</p>
                ${ suggestions.length > 0 ? `
                <div class="np-mm__relaxed-suggestions">
                    <p class="np-mm__relaxed-label">Znaleźliśmy jednak:</p>
                    ${ suggestions.map( ( s, i ) =>
                        `<button class="np-mm__relaxed-btn" data-relax="${ i }">${ esc( s.label ) } →</button>`
                    ).join( '' ) }
                </div>` : '' }
                ${ telefon
                    ? `<p class="np-mm__fallback-contact">Możesz też zadzwonić — pomożemy ręcznie dopasować specjalistę:<br>
                       <a href="tel:${ esc( telefon.replace( /\s/g, '' ) ) }" class="np-mm__fallback-phone">${ esc( telefon ) }</a></p>`
                    : '' }
                <button class="np-mm__btn-reset">Zmień kryteria</button>
            </div>`;
        }

        const maxPossible = areas.reduce( ( sum, slug ) => {
            const mult = slug === this.state.primaryArea ? W.PRIMARY_MULT : 1.0;
            return sum + W.AREA_EXACT * mult;
        }, 0 ) + ( nurt ? W.NURT_EXACT : 0 ) + W.PRECISION_W + W.AVAIL_7;

        const lowCount = results.length < 3;
        const hasMore  = ! this._showAll && full.length > W.MAX_RESULTS;
        const cards    = results.map( ( p, i ) => this._tplCard( p, i, maxPossible, pricing ) ).join( '' );

        return `
        <div class="np-mm__step np-mm__results">
            <h2 class="np-mm__step-title">${ isFallback ? 'Specjaliści z najbliższymi terminami' : 'Twoi specjaliści' }</h2>
            ${ isFallback && telefon
                ? `<p class="np-mm__fallback-contact" style="margin-bottom:16px">Możesz też zadzwonić do nas:
                   <a href="tel:${ esc( telefon.replace( /\s/g, '' ) ) }" class="np-mm__fallback-phone" style="font-size:inherit;display:inline;margin:0 0 0 8px">${ esc( telefon ) }</a></p>`
                : '' }
            ${ lowCount
                ? `<p class="np-mm__info-warn">Znaleźliśmy tylko ${ results.length } specjalist${ results.length === 1 ? 'ę' : 'ów' }.
                   <button class="np-mm__link-btn np-mm__btn-reset">Zmień kryteria</button> by rozszerzyć wyniki.
                   ${ telefon ? `Lub zadzwoń: <a href="tel:${ esc( telefon.replace( /\s/g, '' ) ) }">${ esc( telefon ) }</a>` : '' }</p>`
                : '' }
            <div class="np-mm__cards" id="np-mm-cards">${ cards }</div>
            ${ hasMore
                ? `<div class="np-mm__actions np-mm__actions--center" style="margin-top:8px">
                   <button class="np-mm__btn-show-more" data-action="show-more">Pokaż więcej specjalistów (${ full.length - W.MAX_RESULTS } kolejnych)</button>
                   </div>`
                : '' }
            <div class="np-mm__actions np-mm__actions--center">
                <button class="np-mm__btn-reset">Zmień kryteria</button>
            </div>
        </div>`;
    }

    _tplCard( p, index, maxPossible, pricing ) {
        const score    = p.matchScore;
        const pct      = maxPossible > 0 ? score / maxPossible : 0;
        const badgeCls = pct >= 0.9  ? 'np-mm__badge--gold'
                       : pct >= 0.6  ? 'np-mm__badge--green'
                       : score > 0   ? 'np-mm__badge--blue' : 'np-mm__badge--grey';
        const badgeTxt = pct >= 0.9  ? 'Idealne dopasowanie'
                       : pct >= 0.6  ? 'Bardzo dobre dopasowanie'
                       : score > 0   ? 'Dobre dopasowanie' : 'Możliwe dopasowanie';

        const isPelno    = pricing !== 'nisko';
        const stawka     = pricing === 'nisko' ? p.stawka_nisko : p.stawka_pelno;
        const konsType   = pricing === 'nisko' ? 'nisko' : pricing === 'pelno' ? 'pelno' : '';
        const profileUrl = p.link + ( konsType ? `?konsultacje=${ konsType }` : '' );

        const thumb = p.thumb
            ? `<img src="${ esc( p.thumb ) }" alt="${ esc( p.title ) }" class="np-mm__card-img" loading="lazy">`
            : `<div class="np-mm__card-img np-mm__card-img--placeholder"></div>`;

        const matchTags = ( p.matchedAreas || [] ).map( name =>
            `<span class="np-mm__match-tag">${ esc( name ) } ✓</span>`
        ).join( '' );

        const clusterTags = ( p.clusterAreas || [] ).map( name =>
            `<span class="np-mm__match-tag np-mm__match-tag--cluster">${ esc( name ) } ~</span>`
        ).join( '' );

        const metaChips = [
            p.wizyta    ? `<span class="np-mm__chip">${ esc( p.wizyta ) }</span>` : '',
            stawka      ? `<span class="np-mm__chip">${ esc( stawka ) }</span>` : '',
            p.sort_date ? `<span class="np-mm__chip">od ${ this._formatDate( p.sort_date ) }</span>` : '',
        ].filter( Boolean ).join( '' );

        const matchReason = this._buildMatchReason( p );
        const hasBookero  = isPelno ? p.has_pelno : ( pricing === 'nisko' ? p.has_nisko : ( p.has_pelno || p.has_nisko ) );
        const isTopCard   = index === 0 && pct >= 0.6;

        return `<div class="np-mm__card${ isTopCard ? ' np-mm__card--top' : '' }"
                     data-pid="${ p.id }"
                     data-bk-pelno="${ p.bk_id_pelno || 0 }"
                     data-bk-nisko="${ p.bk_id_nisko || 0 }"
                     data-link="${ esc( profileUrl ) }">
            <div class="np-mm__card-photo">${ thumb }</div>
            <div class="np-mm__card-body">
                <span class="np-mm__badge ${ badgeCls }">${ esc( badgeTxt ) }</span>
                <h3 class="np-mm__card-name">${ esc( p.title ) }</h3>
                <p class="np-mm__card-role">${ esc( p.rola ) }</p>
                ${ matchReason ? `<p class="np-mm__card-reason">${ esc( matchReason ) }</p>` : '' }
                ${ ( matchTags || clusterTags ) ? `<div class="np-mm__match-tags">${ matchTags }${ clusterTags }</div>` : '' }
                ${ metaChips ? `<div class="np-mm__card-meta">${ metaChips }</div>` : '' }
            </div>
            <div class="np-mm__card-cta">
                ${ hasBookero
                    ? `<button class="np-mm__btn-book" data-action="open-cal">Umów wizytę</button>`
                    : `<a href="${ esc( profileUrl ) }" class="np-mm__btn-book np-mm__btn-book--link">Zobacz profil</a>` }
            </div>
            <div class="np-mm__inline-cal" hidden></div>
        </div>`;
    }

    // ─── Events ─────────────────────────────────────────────────────────────

    _attachEvents() {

        // Choice buttons (step 1)
        this.el.querySelectorAll( '.np-mm__choice-btn' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                this._set( { [ btn.dataset.field ]: btn.dataset.value } );
            } );
        } );

        // Availability toggle (step 1)
        const availToggle = this.el.querySelector( '[data-action="toggle-available"]' );
        if ( availToggle ) {
            availToggle.addEventListener( 'click', () => {
                this._set( { onlyAvailable: ! this.state.onlyAvailable } );
            } );
        }

        // Area tiles (step 2) — primary area logic
        this.el.querySelectorAll( '.np-mm__tile' ).forEach( tile => {
            tile.addEventListener( 'click', () => {
                const slug  = tile.dataset.area;
                const areas = [ ...this.state.areas ];
                const idx   = areas.indexOf( slug );
                let { primaryArea } = this.state;

                if ( idx >= 0 ) {
                    if ( slug === primaryArea ) {
                        // Deselect primary → reassign to next
                        areas.splice( idx, 1 );
                        primaryArea = areas[ 0 ] || '';
                    } else {
                        // Promote to primary
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

                this.state.areas       = areas;
                this.state.primaryArea = primaryArea;
                this._updateStep2UI();
            } );
        } );

        // Nurt buttons (step 2)
        this.el.querySelectorAll( '.np-mm__nurt-btn' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                const val = btn.dataset.nurt;
                this.state.nurt = val;
                this.el.querySelectorAll( '.np-mm__nurt-btn' ).forEach( b => {
                    b.classList.toggle( 'np-mm__nurt-btn--active', b.dataset.nurt === val );
                } );
            } );
        } );

        // Nurt toggle
        const nurtToggle = this.el.querySelector( '.np-mm__nurt-toggle' );
        if ( nurtToggle ) {
            nurtToggle.addEventListener( 'click', () => {
                const panel = this.el.querySelector( '.np-mm__nurt-panel' );
                const open  = panel.hidden;
                panel.hidden      = ! open;
                this.state.nurtOpen = open;
                nurtToggle.textContent = open
                    ? '▲ Ukryj preferencje nurtu'
                    : '▼ Mam preferencje co do nurtu (opcjonalne)';
            } );
        }

        // Pokaż więcej obszarów
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

        // Area search
        const search = this.el.querySelector( '.np-mm__search' );
        if ( search ) {
            search.addEventListener( 'input', () => {
                const q = search.value.toLowerCase();
                this.el.querySelectorAll( '.np-mm__tile-grid--extended .np-mm__tile' ).forEach( t => {
                    t.style.display = t.textContent.toLowerCase().includes( q ) ? '' : 'none';
                } );
            } );
        }

        // Navigation
        this.el.querySelectorAll( '[data-next]' ).forEach( btn => {
            btn.addEventListener( 'click', () => { if ( ! btn.disabled ) this._go( parseInt( btn.dataset.next ) ); } );
        } );
        this.el.querySelectorAll( '[data-back]' ).forEach( btn => {
            btn.addEventListener( 'click', () => this._go( parseInt( btn.dataset.back ) ) );
        } );

        // Reset
        this.el.querySelectorAll( '.np-mm__btn-reset' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                this._showAll = false;
                this._clearPersistence();
                this.state = { ...DEFAULT_STATE };
                this._render();
            } );
        } );

        // Inline calendar
        this.el.querySelectorAll( '[data-action="open-cal"]' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                const card = btn.closest( '.np-mm__card' );
                this._toggleCalendar( card );
            } );
        } );

        // "Nie wiem" fallback
        const fallbackBtn = this.el.querySelector( '[data-action="fallback"]' );
        if ( fallbackBtn ) {
            fallbackBtn.addEventListener( 'click', () => {
                this._set( { step: 4, areas: [], primaryArea: '', nurt: '' } );
            } );
        }

        // Pokaż więcej wyników
        const showMoreBtn = this.el.querySelector( '[data-action="show-more"]' );
        if ( showMoreBtn ) {
            showMoreBtn.addEventListener( 'click', () => {
                this._showAll = true;
                this._render();
            } );
        }

        // Relaxed filter suggestions
        const relaxedSuggestions = this._getRelaxedSuggestions();
        this.el.querySelectorAll( '[data-relax]' ).forEach( btn => {
            const idx = parseInt( btn.dataset.relax );
            if ( relaxedSuggestions[ idx ] ) {
                btn.addEventListener( 'click', () => {
                    this._set( relaxedSuggestions[ idx ].patch );
                } );
            }
        } );
    }

    // ─── Step 2 partial update ───────────────────────────────────────────────

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

        // Update tile classes + ★ label in-place
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

        // Show/hide primary hint
        let hint = this.el.querySelector( '.np-mm__primary-hint' );
        if ( selected >= 1 ) {
            if ( ! hint ) {
                hint = document.createElement( 'p' );
                hint.className   = 'np-mm__primary-hint';
                hint.textContent = 'Kliknij wybrany obszar ponownie, aby oznaczyć go jako główny problem (★ = podwójna waga).';
                const areaCounter = this.el.querySelector( '#np-mm-area-counter' );
                if ( areaCounter ) areaCounter.after( hint );
            }
        } else if ( hint ) {
            hint.remove();
        }

        this._saveToSession();
    }

    // ─── Inline Bookero calendar ─────────────────────────────────────────────

    _toggleCalendar( card ) {
        const panel = card.querySelector( '.np-mm__inline-cal' );
        if ( ! panel ) return;

        this.el.querySelectorAll( '.np-mm__inline-cal:not([hidden])' ).forEach( p => {
            if ( p !== panel ) {
                p.hidden = true;
                p.innerHTML = '';
                const otherCard = p.closest( '.np-mm__card' );
                const otherBtn  = otherCard && otherCard.querySelector( '[data-action="open-cal"]' );
                if ( otherBtn ) otherBtn.textContent = 'Umów wizytę';
            }
        } );

        if ( ! panel.hidden ) {
            panel.hidden = true;
            panel.innerHTML = '';
            card.querySelector( '[data-action="open-cal"]' ).textContent = 'Umów wizytę';
            return;
        }

        const pid      = card.dataset.pid;
        const pricing  = this.state.pricing;
        const isPelno  = pricing !== 'nisko';
        const bkPelno  = parseInt( card.dataset.bkPelno ) || 0;
        const bkNisko  = parseInt( card.dataset.bkNisko ) || 0;

        let workerId = isPelno ? bkPelno : bkNisko;
        if ( ! workerId && pricing === 'both' ) workerId = bkPelno || bkNisko;

        if ( ! workerId ) {
            window.location.href = card.dataset.link;
            return;
        }

        const pluginId    = isPelno || pricing === 'both'
            ? ( MM_DATA.pelnoPluginId || MM_DATA.niskoPluginId )
            : ( MM_DATA.niskoPluginId || MM_DATA.pelnoPluginId );
        const containerId = `np-mm-bk-${ pid }`;

        panel.hidden  = false;
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
            panel.hidden = true;
            panel.innerHTML = '';
            card.querySelector( '[data-action="open-cal"]' ).textContent = 'Umów wizytę';
        } );

        card.querySelector( '[data-action="open-cal"]' ).textContent = 'Zamknij kalendarz';

        window.bookero_config = {
            id:           pluginId,
            container:    containerId,
            type:         'calendar',
            position:     '',
            plugin_css:   false,
            lang:         ( window.niepodzielniBookero || {} ).lang || 'pl',
            custom_config: { use_worker_id: workerId, hide_worker_info: 1 },
        };

        const old = document.querySelector( 'script[data-bk-mm]' );
        if ( old ) old.remove();
        const script    = document.createElement( 'script' );
        script.src      = 'https://cdn.bookero.pl/plugin/v2/js/bookero-compiled.js?t=' + Date.now();
        script.dataset.bkMm = '1';
        script.defer    = false;
        document.body.appendChild( script );

        setTimeout( () => panel.scrollIntoView( { behavior: 'smooth', block: 'nearest' } ), 100 );
    }

    _formatDate( s ) {
        if ( ! s || s.length !== 8 ) return s;
        return `${ s.slice( 6, 8 ) }.${ s.slice( 4, 6 ) }.${ s.slice( 0, 4 ) }`;
    }
}

function esc( str ) {
    return String( str )
        .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
        .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
}

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
