/**
 * Matchmaker — Templates
 *
 * Czyste funkcje generujące HTML. Żadnego stanu, żadnego DOM — każda funkcja
 * przyjmuje potrzebne dane jako argumenty i zwraca string HTML.
 *
 * Zależności zewnętrzne: W (wagi) z ScoringEngine — tylko do obliczeń maxPossible.
 */

import { W } from './ScoringEngine.js';

// ─── Helper ────────────────────────────────────────────────────────────────────

/**
 * Escapuje znaki specjalne HTML. Używaj wszędzie tam, gdzie wstawiasz dane użytkownika.
 * @param {*} str
 * @returns {string}
 */
export function esc( str ) {
    return String( str )
        .replace( /&/g, '&amp;' )
        .replace( /</g, '&lt;'  )
        .replace( />/g, '&gt;'  )
        .replace( /"/g, '&quot;' );
}

/**
 * Formatuje datę z formatu Ymd (np. "20260422") do "DD.MM.YYYY".
 * @param {string} s
 * @returns {string}
 */
export function formatDate( s ) {
    if ( ! s || s.length !== 8 ) return s;
    return `${ s.slice( 6, 8 ) }.${ s.slice( 4, 6 ) }.${ s.slice( 0, 4 ) }`;
}

// ─── Progress bar ──────────────────────────────────────────────────────────────

/**
 * Renderuje pasek postępu kroków (krok 1/2).
 * @param {object} state
 * @returns {string}
 */
export function tplProgress( state ) {
    const { step } = state;
    const steps    = [ 'Filtry', 'Czego szukasz?' ];

    const dots = steps.map( ( label, i ) => {
        const n   = i + 1;
        const cls = n < step ? 'done' : n === step ? 'active' : '';
        return `<div class="np-mm__prog-step ${ cls }">
                    <div class="np-mm__prog-dot">${ n < step ? '✓' : n }</div>
                    <span>${ label }</span>
                </div>`;
    } ).join( '<div class="np-mm__prog-line"></div>' );

    return `<div class="np-mm__progress">${ dots }</div>`;
}

// ─── Shared button helpers ─────────────────────────────────────────────────────

/**
 * Renderuje przycisk wyboru (step 1) z aktywnym stanem.
 * @param {string} field    Klucz stanu (np. 'who', 'visitType')
 * @param {string} value    Wartość po kliknięciu
 * @param {string} label    Główna etykieta
 * @param {string} desc     Podpis (opcjonalny)
 * @param {string} current  Bieżąca wartość pola — decyduje o klasie active
 * @returns {string}
 */
function choiceBtn( field, value, label, desc, current ) {
    const active = current === value ? 'np-mm__choice-btn--active' : '';
    return `<button class="np-mm__choice-btn ${ active }" data-field="${ field }" data-value="${ value }">
                <span class="np-mm__choice-label">${ label }</span>
                ${ desc ? `<span class="np-mm__choice-desc">${ desc }</span>` : '' }
            </button>`;
}

// ─── Step 1 ────────────────────────────────────────────────────────────────────

/**
 * Renderuje krok 1 — filtry podstawowe (kto, typ wizyty, cennik, język, tylko z terminami).
 * @param {object} state  Stan matchmakera
 * @param {object} data   window.NP_MATCHMAKER (potrzebne: jezyki_list)
 * @returns {string}
 */
export function tplStep1( state, data ) {
    const { who, visitType, pricing, lang, onlyAvailable } = state;
    const langs = data.jezyki_list || [];

    const langSection = langs.length > 0 ? `
        <div class="np-mm__section">
            <p class="np-mm__label">Język wizyty?</p>
            <div class="np-mm__choice-group np-mm__choice-group--wrap">
                ${ choiceBtn( 'lang', '', 'Dowolny', 'Każdy język', lang ) }
                ${ langs.map( l => choiceBtn( 'lang', l.slug, l.name, '', lang ) ).join( '' ) }
            </div>
        </div>` : '';

    return `
    <div class="np-mm__step" data-step="1">
        <h2 class="np-mm__step-title" tabindex="-1">Powiedz nam o swoich potrzebach</h2>
        <p class="np-mm__step-desc">Kilka pytań pomoże nam znaleźć najlepszego specjalistę.</p>

        <div class="np-mm__section">
            <p class="np-mm__label">Dla kogo szukasz wsparcia?</p>
            <div class="np-mm__choice-group">
                ${ choiceBtn( 'who', 'adult',  'Dla mnie',  'Osoba dorosła',       who ) }
                ${ choiceBtn( 'who', 'couple', 'Dla pary',  'Pary / Małżeństwa',   who ) }
            </div>
        </div>

        <div class="np-mm__section">
            <p class="np-mm__label">Preferowana forma wizyty?</p>
            <div class="np-mm__choice-group">
                ${ choiceBtn( 'visitType', '',             'Wszędzie',    'Online lub stacjonarnie',  visitType ) }
                ${ choiceBtn( 'visitType', 'Online',       'Online',      'Spotkanie przez internet', visitType ) }
                ${ choiceBtn( 'visitType', 'Stacjonarnie', 'W gabinecie', 'Spotkanie w gabinecie',    visitType ) }
            </div>
        </div>

        <div class="np-mm__section">
            <p class="np-mm__label">Rodzaj konsultacji?</p>
            <div class="np-mm__choice-group">
                ${ choiceBtn( 'pricing', 'both',  'Obie opcje',  'Pokaż wszystkich specjalistów', pricing ) }
                ${ choiceBtn( 'pricing', 'pelno', 'Pełnopłatna', 'Standardowa cena (~145 zł)',    pricing ) }
                ${ choiceBtn( 'pricing', 'nisko', 'Niskopłatna', 'Obniżona cena (~55 zł)',         pricing ) }
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

// ─── Step 2 ────────────────────────────────────────────────────────────────────

/**
 * Renderuje krok 2 — wybór obszarów i nurtu.
 * @param {object} state          Stan matchmakera
 * @param {object} data           window.NP_MATCHMAKER (potrzebne: obszary, nurty, curated)
 * @param {number} filteredCount  Bieżąca liczba pasujących specjalistów (z hard filtrów)
 * @returns {string}
 */
export function tplStep2( state, data, filteredCount ) {
    const { areas, primaryArea, nurt, nurtOpen } = state;
    const selected  = areas.length;
    const areasMeta = data.obszary  || [];
    const nurty     = data.nurty    || [];
    const curated   = data.curated  || [];
    const count     = filteredCount;

    const areaMap  = Object.fromEntries( areasMeta.map( a => [ a.slug, a.name ] ) );
    const curatedFiltered = curated.filter( s => areaMap[ s ] );
    const extended        = areasMeta.filter( a => ! curated.includes( a.slug ) );

    const tileHtml = ( slug, name ) => {
        const isSelected = areas.includes( slug );
        const isPrimary  = isSelected && slug === primaryArea;
        const cls        = isPrimary ? 'np-mm__tile--primary' : ( isSelected ? 'np-mm__tile--selected' : '' );
        const label      = isPrimary ? `★ ${ esc( name ) }` : esc( name );
        return `<button class="np-mm__tile ${ cls }" data-area="${ slug }" aria-pressed="${ isSelected }">${ label }</button>`;
    };

    const nurtBtns        = nurty.map( n => {
        const active = nurt === n.slug ? 'np-mm__nurt-btn--active' : '';
        return `<button class="np-mm__nurt-btn ${ active }" data-nurt="${ n.slug }">${ esc( n.name ) }</button>`;
    } ).join( '' );
    const nurtNoneActive  = ! nurt ? 'np-mm__nurt-btn--active' : '';

    return `
    <div class="np-mm__step" data-step="2">
        <h2 class="np-mm__step-title" tabindex="-1">Co sprawia Ci trudność?</h2>
        <p class="np-mm__step-desc">Wybierz maksymalnie 3 obszary, które dotyczą Twojej sytuacji.</p>

        <div class="np-mm__live-counter" id="np-mm-counter" aria-live="polite" aria-atomic="true">
            ${ count > 0
                ? `Pasujących specjalistów: <strong>${ count }</strong>`
                : '<span class="np-mm__counter-warn">Brak specjalistów dla tych filtrów</span>' }
        </div>

        <div class="np-mm__area-counter" id="np-mm-area-counter" aria-live="polite" aria-atomic="true">
            ${ selected === 0 ? 'Wybierz 1–3 obszary' : `Wybrano: <strong>${ selected }/3</strong>` }
        </div>

        ${ selected >= 1
            ? `<p class="np-mm__primary-hint">Kliknij wybrany obszar ponownie, aby oznaczyć go jako główny problem (★ = podwójna waga).</p>`
            : '' }

        <div class="np-mm__tile-grid">
            ${ curatedFiltered.map( s => tileHtml( s, areaMap[ s ] ) ).join( '' ) }
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

// ─── Results ───────────────────────────────────────────────────────────────────

/**
 * Buduje zdanie uzasadniające dopasowanie psychologa.
 * @param {object} p      Psycholog z polami matchedAreas, clusterAreas, nurtMatch, sort_date
 * @param {object} state  Stan matchmakera (potrzebne: nurt)
 * @param {Array}  nurty  data.nurty — do mapowania slug → name
 * @returns {string}
 */
export function buildMatchReason( p, state, nurty ) {
    const nurtNameMap = Object.fromEntries( ( nurty || [] ).map( n => [ n.slug, n.name ] ) );
    const parts       = [];

    if ( p.matchedAreas.length > 0 ) {
        parts.push( 'Specjalizuje się w: ' + p.matchedAreas.join( ', ' ) );
    } else if ( p.clusterAreas.length > 0 ) {
        parts.push( 'Pokrewne obszary: ' + p.clusterAreas.join( ', ' ) );
    }

    if ( p.nurtMatch === 'exact' && state.nurt ) {
        parts.push( nurtNameMap[ state.nurt ] || state.nurt );
    } else if ( p.nurtMatch === 'family' && state.nurt ) {
        parts.push( 'Pokrewny nurt: ' + ( nurtNameMap[ state.nurt ] || state.nurt ) );
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

/**
 * Renderuje kartę pojedynczego psychologa.
 * @param {object} p            Psycholog z polami scoringowymi (matchScore, matchedAreas, ...)
 * @param {number} index        Pozycja na liście (0 = pierwszy)
 * @param {number} maxPossible  Maksymalny możliwy wynik (do obliczeń % dopasowania)
 * @param {string} pricing      Wybrany cennik ('both' | 'pelno' | 'nisko')
 * @param {object} state        Stan matchmakera (do buildMatchReason)
 * @param {Array}  nurty        data.nurty (do buildMatchReason)
 * @returns {string}
 */
export function tplCard( p, index, maxPossible, pricing, state, nurty ) {
    const score    = p.matchScore;
    const pct      = maxPossible > 0 ? score / maxPossible : 0;
    const badgeCls = pct >= 0.9 ? 'np-mm__badge--gold'
                   : pct >= 0.6 ? 'np-mm__badge--green'
                   : score > 0  ? 'np-mm__badge--blue' : 'np-mm__badge--grey';
    const badgeTxt = pct >= 0.9 ? 'Idealne dopasowanie'
                   : pct >= 0.6 ? 'Bardzo dobre dopasowanie'
                   : score > 0  ? 'Dobre dopasowanie' : 'Możliwe dopasowanie';

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
        p.wizyta    ? `<span class="np-mm__chip">${ esc( p.wizyta ) }</span>`          : '',
        stawka      ? `<span class="np-mm__chip">${ esc( stawka ) }</span>`             : '',
        p.sort_date ? `<span class="np-mm__chip">od ${ formatDate( p.sort_date ) }</span>` : '',
    ].filter( Boolean ).join( '' );

    const matchReason = buildMatchReason( p, state, nurty );
    const hasBookero  = isPelno
        ? p.has_pelno
        : ( pricing === 'nisko' ? p.has_nisko : ( p.has_pelno || p.has_nisko ) );
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

/**
 * Renderuje ekran wyników.
 * @param {object} state              Stan matchmakera
 * @param {Array}  results            Psycholodzy do wyświetlenia (już przycięci do MAX_RESULTS lub pełni)
 * @param {Array}  fullResults        Pełna lista (do liczenia "pokaż więcej X kolejnych")
 * @param {object} data               window.NP_MATCHMAKER (potrzebne: telefon, nurty)
 * @param {Array}  relaxedSuggestions Propozycje poluzowania filtrów (z ScoringEngine)
 * @param {boolean} showAll           Czy wyświetlić pełną listę
 * @returns {string}
 */
export function tplResults( state, results, fullResults, data, relaxedSuggestions, showAll ) {
    const { areas, nurt, pricing, primaryArea } = state;
    const telefon    = data.telefon || '';
    const nurty      = data.nurty   || [];
    const isFallback = areas.length === 0;

    // Brak wyników
    if ( results.length === 0 ) {
        return `<div class="np-mm__step np-mm__results-empty">
            <h2 class="np-mm__step-title" tabindex="-1">Nie znaleźliśmy specjalisty</h2>
            <p>Brak specjalistów dla wybranych kryteriów.</p>
            ${ relaxedSuggestions.length > 0 ? `
            <div class="np-mm__relaxed-suggestions">
                <p class="np-mm__relaxed-label">Znaleźliśmy jednak:</p>
                ${ relaxedSuggestions.map( ( s, i ) =>
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

    // Maksymalny możliwy wynik scoringowy (do procentowego obliczenia badgea)
    const maxPossible = areas.reduce( ( sum, slug ) => {
        const mult = slug === primaryArea ? W.PRIMARY_MULT : 1.0;
        return sum + W.AREA_EXACT * mult;
    }, 0 ) + ( nurt ? W.NURT_EXACT : 0 ) + W.PRECISION_W + W.AVAIL_7;

    const lowCount = results.length < 3;
    const hasMore  = ! showAll && fullResults.length > W.MAX_RESULTS;
    const cards    = results.map( ( p, i ) => tplCard( p, i, maxPossible, pricing, state, nurty ) ).join( '' );

    return `
    <div class="np-mm__step np-mm__results">
        <h2 class="np-mm__step-title" tabindex="-1">${ isFallback ? 'Specjaliści z najbliższymi terminami' : 'Twoi specjaliści' }</h2>
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
               <button class="np-mm__btn-show-more" data-action="show-more">Pokaż więcej specjalistów (${ fullResults.length - W.MAX_RESULTS } kolejnych)</button>
               </div>`
            : '' }
        <div class="np-mm__actions np-mm__actions--center">
            <button class="np-mm__btn-reset">Zmień kryteria</button>
        </div>
    </div>`;
}
