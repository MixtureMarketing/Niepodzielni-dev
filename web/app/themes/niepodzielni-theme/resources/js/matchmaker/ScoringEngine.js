/**
 * Matchmaker — ScoringEngine
 *
 * Czyste funkcje do filtrowania i punktowania psychologów.
 * Żadnych zależności od DOM ani sessionStorage — w pełni testowalny moduł.
 *
 * Przyjmuje dane z window.NP_MATCHMAKER jako argument `data`,
 * nigdy nie odwołuje się do window bezpośrednio.
 */

// ─── Wagi scoringowe ───────────────────────────────────────────────────────────

/**
 * Wagi silnika dopasowania. Eksportowane, żeby Templates mogły obliczyć maxPossible.
 */
export const W = {
    AREA_EXACT:   1.0,   // obszar pasuje dokładnie
    AREA_CLUSTER: 0.5,   // obszar w tej samej rodzinie tematycznej co wybrany
    NURT_EXACT:   1.0,   // nurt terapeutyczny pasuje dokładnie
    NURT_FAMILY:  0.5,   // pokrewny nurt (ta sama rodzina)
    PRIMARY_MULT: 2.0,   // mnożnik dla obszaru oznaczonego jako główny (★)
    PRECISION_W:  0.8,   // bonus za specjalizację: exactMatches / totalAreas
    AVAIL_7:      0.5,   // termin dostępny w ciągu 7 dni
    AVAIL_14:     0.25,  // termin dostępny w ciągu 14 dni
    AVAIL_30:     0.1,   // termin dostępny w ciągu 30 dni
    SPEC_BONUS:   0.5,   // max bonus za jedną specjalizację
    MAX_RESULTS:  5,     // kart na ekranie wyników (przed "pokaż więcej")
    MAX_FULL:     10,    // maksymalna pula po pełnym scoringu
};

// ─── Hard filter ───────────────────────────────────────────────────────────────

/**
 * Liczy psychologów przechodzących przez twarde filtry (bez scoringu).
 * Używany do live-countera w kroku 2 — szybkie, bez alokacji nowych obiektów.
 *
 * @param {object} data  Dane z window.NP_MATCHMAKER
 * @param {object} st    Stan matchmakera
 * @returns {number}
 */
export function countWith( data, st ) {
    const psy = data.psychologists || [];
    const { who, visitType, pricing, lang, onlyAvailable } = st;

    return psy.filter( p => {
        if ( who === 'couple'             && ! p.spec.includes( 'terapia-par' ) )      return false;
        if ( visitType === 'Online'       && ! p.wizyta.includes( 'Online' ) )         return false;
        if ( visitType === 'Stacjonarnie' && ! p.wizyta.includes( 'Stacjonarnie' ) )   return false;
        if ( pricing === 'pelno'          && ! p.has_pelno )                           return false;
        if ( pricing === 'nisko'          && ! p.has_nisko )                           return false;
        if ( lang                         && ! ( p.jezyki || [] ).includes( lang ) )   return false;
        if ( onlyAvailable                && ! p.sort_date )                           return false;
        return true;
    } ).length;
}

// ─── Główny silnik scoringowy ──────────────────────────────────────────────────

/**
 * Trzy-fazowy silnik dopasowania: hard filter → scoring → sort.
 * Zwraca max W.MAX_FULL psychologów z pełnymi metadanymi scoringu.
 *
 * @param {object} data  Dane z window.NP_MATCHMAKER
 * @param {object} st    Stan matchmakera
 * @returns {Array<object>}  Psycholodzy z polami: matchScore, matchedAreas, clusterAreas, nurtMatch
 */
export function runMatchmakerWith( data, st ) {
    const psy      = data.psychologists || [];
    const areasMeta = data.obszary      || [];
    const clusters  = data.areaClusters || {};
    const families  = data.nurtFamilies || {};
    const specBon   = data.specBonuses  || {};

    const { who, visitType, pricing, lang, onlyAvailable, areas, primaryArea, nurt } = st;

    // ── Faza 1: Hard filters ─────────────────────────────────────────────────
    let pool = psy.filter( p => {
        if ( who === 'couple'             && ! p.spec.includes( 'terapia-par' ) )      return false;
        if ( visitType === 'Online'       && ! p.wizyta.includes( 'Online' ) )         return false;
        if ( visitType === 'Stacjonarnie' && ! p.wizyta.includes( 'Stacjonarnie' ) )   return false;
        if ( pricing === 'pelno'          && ! p.has_pelno )                           return false;
        if ( pricing === 'nisko'          && ! p.has_nisko )                           return false;
        if ( lang                         && ! ( p.jezyki || [] ).includes( lang ) )   return false;
        if ( onlyAvailable                && ! p.sort_date )                           return false;
        return true;
    } );

    const areaNameMap = Object.fromEntries( areasMeta.map( a => [ a.slug, a.name ] ) );
    const today = new Date();
    today.setHours( 0, 0, 0, 0 );

    // ── Faza 2: Scoring ──────────────────────────────────────────────────────
    pool = pool.map( p => {
        let score          = 0;
        const matchedAreas = [];
        const clusterAreas = [];
        let nurtMatch      = 'none';

        // Obszary: exact match + cluster fuzzy match
        areas.forEach( slug => {
            const mult = ( slug === primaryArea && primaryArea ) ? W.PRIMARY_MULT : 1.0;

            if ( p.obszary.includes( slug ) ) {
                score += W.AREA_EXACT * mult;
                matchedAreas.push( areaNameMap[ slug ] || slug );
            } else {
                const userCluster = clusters[ slug ];
                if ( userCluster > 0 ) {
                    const relSlug = p.obszary.find( s => clusters[ s ] === userCluster );
                    if ( relSlug ) {
                        score += W.AREA_CLUSTER * mult;
                        const relName = areaNameMap[ relSlug ] || relSlug;
                        if ( ! clusterAreas.includes( relName ) ) clusterAreas.push( relName );
                    }
                }
            }
        } );

        // Bonus za specjalizację — wzmacnia dopasowanie obszarowe
        p.spec.forEach( specSlug => {
            const bonuses = specBon[ specSlug ];
            if ( ! bonuses ) return;
            areas.forEach( areaSlug => {
                if ( bonuses[ areaSlug ] !== undefined ) {
                    score += bonuses[ areaSlug ];
                }
            } );
        } );

        // Bonus precyzji — wyspecjalizowani wyżej niż generaliści
        const exactCount = matchedAreas.length;
        if ( exactCount > 0 && p.obszary.length > 0 ) {
            score += ( exactCount / p.obszary.length ) * W.PRECISION_W;
        }

        // Nurt terapeutyczny: exact + family fuzzy
        if ( nurt ) {
            if ( p.nurty.includes( nurt ) ) {
                score    += W.NURT_EXACT;
                nurtMatch = 'exact';
            } else {
                const userFamily = families[ nurt ];
                if ( userFamily && p.nurty.some( n => families[ n ] === userFamily ) ) {
                    score    += W.NURT_FAMILY;
                    nurtMatch = 'family';
                }
            }
        }

        // Bonus dostępności — premiuje terapeutów z bliskim terminem
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

    // ── Faza 3: Sort ─────────────────────────────────────────────────────────
    pool.sort( ( a, b ) => {
        if ( b.matchScore !== a.matchScore ) return b.matchScore - a.matchScore;
        if ( a.sort_date && b.sort_date )    return a.sort_date.localeCompare( b.sort_date );
        if ( a.sort_date )                   return -1;
        if ( b.sort_date )                   return  1;
        return Math.random() - 0.5;
    } );

    return pool.slice( 0, W.MAX_FULL );
}

// ─── Relaxed suggestions ───────────────────────────────────────────────────────

/**
 * Generuje propozycje poluzowania filtrów gdy wyniki = 0.
 * Każda propozycja zawiera label (co odblokuje) i patch (jaką zmianę stanu zastosować).
 *
 * @param {object} data   Dane z window.NP_MATCHMAKER
 * @param {object} state  Stan matchmakera
 * @returns {Array<{label: string, patch: object}>}
 */
export function getRelaxedSuggestions( data, state ) {
    const s           = state;
    const suggestions = [];
    const plural      = n => n === 1 ? 'ę' : 'ów';

    if ( s.visitType ) {
        const count = countWith( data, { ...s, visitType: '' } );
        if ( count > 0 ) suggestions.push( {
            label: `${ count } specjalist${ plural( count ) } online i stacjonarnie`,
            patch: { visitType: '' },
        } );
    }
    if ( s.pricing !== 'both' ) {
        const count = countWith( data, { ...s, pricing: 'both' } );
        if ( count > 0 ) suggestions.push( {
            label: `${ count } specjalist${ plural( count ) } (obie opcje cenowe)`,
            patch: { pricing: 'both' },
        } );
    }
    if ( s.lang ) {
        const count = countWith( data, { ...s, lang: '' } );
        if ( count > 0 ) suggestions.push( {
            label: `${ count } specjalist${ plural( count ) } (wszystkie języki)`,
            patch: { lang: '' },
        } );
    }
    if ( s.onlyAvailable ) {
        const count = countWith( data, { ...s, onlyAvailable: false } );
        if ( count > 0 ) suggestions.push( {
            label: `${ count } specjalist${ plural( count ) } (w tym bez terminu w kalendarzu)`,
            patch: { onlyAvailable: false },
        } );
    }
    if ( s.who === 'couple' ) {
        const count = countWith( data, { ...s, who: 'adult' } );
        if ( count > 0 ) suggestions.push( {
            label: `${ count } specjalist${ plural( count ) } w terapii indywidualnej`,
            patch: { who: 'adult' },
        } );
    }

    return suggestions;
}
