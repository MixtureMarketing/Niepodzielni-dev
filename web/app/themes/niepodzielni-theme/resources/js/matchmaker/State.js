/**
 * Matchmaker — State
 *
 * Zarządza domyślnym stanem, persystencją w sessionStorage
 * oraz serializacją/deserializacją URL query string.
 *
 * Wszystkie funkcje są czyste — nie mają dostępu do DOM ani window.NP_MATCHMAKER.
 */

export const SESSION_KEY = 'np_mm_state';

export const DEFAULT_STATE = {
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

// ─── sessionStorage ────────────────────────────────────────────────────────────

/**
 * Zapisuje bieżący stan do sessionStorage.
 * @param {object} state
 */
export function saveToSession( state ) {
    try {
        sessionStorage.setItem( SESSION_KEY, JSON.stringify( state ) );
    } catch ( e ) {}
}

/**
 * Odczytuje stan z sessionStorage.
 * @returns {object|null}  Stan scalony z DEFAULT_STATE lub null gdy brak.
 */
export function restoreFromSession() {
    try {
        const raw = sessionStorage.getItem( SESSION_KEY );
        return raw ? { ...DEFAULT_STATE, ...JSON.parse( raw ) } : null;
    } catch ( e ) {
        return null;
    }
}

// ─── URL params ────────────────────────────────────────────────────────────────

/**
 * Serializuje stan do URL query string (history.replaceState — bez przeładowania).
 * Pomija wartości domyślne, żeby URL był czysty.
 * @param {object} state
 */
export function saveToUrl( state ) {
    const { who, visitType, pricing, lang, onlyAvailable, areas, primaryArea, nurt } = state;
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

/**
 * Deserializuje stan z URL query string.
 * Aktywuje się tylko gdy URL zawiera przynajmniej jeden z kluczowych parametrów.
 * @returns {object|null}  Stan scalony z DEFAULT_STATE lub null gdy brak parametrów.
 */
export function restoreFromUrl() {
    const p = new URLSearchParams( window.location.search );
    if ( ! p.has( 'areas' ) && ! p.has( 'pricing' ) && ! p.has( 'who' ) && ! p.has( 'type' ) ) {
        return null;
    }

    const areas = p.get( 'areas' )
        ? p.get( 'areas' ).split( ',' ).filter( Boolean )
        : [];

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

// ─── Czyszczenie ───────────────────────────────────────────────────────────────

/**
 * Usuwa persystencję — czyści sessionStorage i resetuje URL do czystej ścieżki.
 */
export function clearPersistence() {
    try { sessionStorage.removeItem( SESSION_KEY ); } catch ( e ) {}
    history.replaceState( null, '', window.location.pathname );
}
