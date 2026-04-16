/**
 * Testy ScoringEngine — silnik dopasowania matchmakera
 *
 * Framework: Vitest (vite-native, environment: 'node')
 * Plik: resources/js/matchmaker/ScoringEngine.js
 *
 * Testy pokrywają:
 *   1. PRIMARY_MULT — obszar główny podwaja wynik
 *   2. AREA_CLUSTER — fuzzy match przez grupy tematyczne
 *   3. Hard filter visitType — wyklucza psychologów bez wymaganego trybu
 *   4. PRECISION_W — bonus precyzji dla wyspecjalizowanych
 *   5. countWith — poprawne zliczanie po filtrach
 *   6. getRelaxedSuggestions — propozycje poluzowania przy zerowych wynikach
 */

import { describe, it, expect } from 'vitest';
import { W, runMatchmakerWith, countWith, getRelaxedSuggestions } from '../matchmaker/ScoringEngine.js';

// ─── Fabryki danych testowych ─────────────────────────────────────────────────

/**
 * Minimalny rekord psychologa — tylko pola używane przez ScoringEngine.
 */
function makePsy( overrides = {} ) {
    return {
        id:         1,
        title:      'Anna Kowalska',
        obszary:    [],
        spec:       [],
        nurty:      [],
        wizyta:     'Online, Stacjonarnie',
        jezyki:     [],
        has_pelno:  true,
        has_nisko:  false,
        sort_date:  '',
        ...overrides,
    };
}

/**
 * Minimalny stan matchmakera — wszystkie opcjonalne filtry wyłączone.
 */
function makeState( overrides = {} ) {
    return {
        who:           'adult',
        visitType:     '',
        pricing:       'both',
        lang:          '',
        onlyAvailable: false,
        areas:         [],
        primaryArea:   '',
        nurt:          '',
        ...overrides,
    };
}

/**
 * Minimalne dane matchmakera.
 */
function makeData( psychologists = [], overrides = {} ) {
    return {
        psychologists,
        obszary:      [],
        areaClusters: {},
        nurtFamilies: {},
        specBonuses:  {},
        ...overrides,
    };
}

// ─── Stałe wagi ───────────────────────────────────────────────────────────────

describe( 'Stałe wag (W)', () => {
    it( 'PRIMARY_MULT = 2.0', () => {
        expect( W.PRIMARY_MULT ).toBe( 2.0 );
    } );

    it( 'AREA_EXACT = 1.0', () => {
        expect( W.AREA_EXACT ).toBe( 1.0 );
    } );

    it( 'AREA_CLUSTER = 0.5', () => {
        expect( W.AREA_CLUSTER ).toBe( 0.5 );
    } );

    it( 'PRECISION_W = 0.8', () => {
        expect( W.PRECISION_W ).toBe( 0.8 );
    } );
} );

// ─── PRIMARY_MULT ─────────────────────────────────────────────────────────────

describe( 'PRIMARY_MULT — mnożnik dla obszaru głównego', () => {
    it( 'podwaja wynik gdy obszar = primaryArea', () => {
        // Psycholog ma dokładnie jeden obszar 'depresja'
        // Użytkownik wybrał 'depresja' jako areas[0] i primaryArea
        // Oczekiwany wynik: AREA_EXACT * PRIMARY_MULT + PRECISION_W * (1/1)
        //                 = 1.0 * 2.0 + 0.8 * 1.0 = 2.8
        const psy  = makePsy( { obszary: [ 'depresja' ], id: 1 } );
        const data = makeData( [ psy ], {
            obszary: [ { slug: 'depresja', name: 'Depresja' } ],
        } );
        const st   = makeState( { areas: [ 'depresja' ], primaryArea: 'depresja' } );

        const results = runMatchmakerWith( data, st );

        expect( results ).toHaveLength( 1 );
        expect( results[ 0 ].matchScore ).toBeCloseTo( 2.8, 5 );
    } );

    it( 'nie stosuje mnożnika gdy obszar NIE jest primaryArea', () => {
        // Identyczna sytuacja, ale primaryArea = ''
        // Oczekiwany wynik: AREA_EXACT * 1.0 + PRECISION_W * (1/1)
        //                 = 1.0 + 0.8 = 1.8
        const psy  = makePsy( { obszary: [ 'depresja' ], id: 2 } );
        const data = makeData( [ psy ], {
            obszary: [ { slug: 'depresja', name: 'Depresja' } ],
        } );
        const st   = makeState( { areas: [ 'depresja' ], primaryArea: '' } );

        const results = runMatchmakerWith( data, st );

        expect( results[ 0 ].matchScore ).toBeCloseTo( 1.8, 5 );
    } );

    it( 'psycholog z primaryArea ma wyższy wynik niż bez', () => {
        // Porównanie tych samych danych ze/bez primaryArea
        const psy  = makePsy( { obszary: [ 'lęk' ] } );
        const data = makeData( [ psy ], { obszary: [ { slug: 'lęk', name: 'Lęk' } ] } );

        const withPrimary    = runMatchmakerWith( data, makeState( { areas: [ 'lęk' ], primaryArea: 'lęk' } ) );
        const withoutPrimary = runMatchmakerWith( data, makeState( { areas: [ 'lęk' ], primaryArea: '' } ) );

        expect( withPrimary[ 0 ].matchScore ).toBeGreaterThan( withoutPrimary[ 0 ].matchScore );
    } );
} );

// ─── AREA_CLUSTER ─────────────────────────────────────────────────────────────

describe( 'AREA_CLUSTER — fuzzy matching przez grupy tematyczne', () => {
    it( 'przyznaje 0.5 pkt gdy obszar należy do tej samej grupy', () => {
        // Użytkownik wybrał 'ptsd' (cluster 1), psycholog ma 'trauma' (cluster 1)
        // Brak exact match → fuzzy cluster match
        // Oczekiwany wynik: AREA_CLUSTER * 1.0 = 0.5 (bez precyzji bo exactCount = 0)
        const psy  = makePsy( { obszary: [ 'trauma' ], id: 3 } );
        const data = makeData( [ psy ], {
            obszary:      [ { slug: 'ptsd', name: 'PTSD' }, { slug: 'trauma', name: 'Trauma' } ],
            areaClusters: { ptsd: 1, trauma: 1 },
        } );
        const st   = makeState( { areas: [ 'ptsd' ], primaryArea: '' } );

        const results = runMatchmakerWith( data, st );

        expect( results[ 0 ].matchScore ).toBeCloseTo( 0.5, 5 );
    } );

    it( 'dodaje nazwę powiązanego obszaru do clusterAreas', () => {
        const psy  = makePsy( { obszary: [ 'trauma' ], id: 4 } );
        const data = makeData( [ psy ], {
            obszary:      [ { slug: 'ptsd', name: 'PTSD' }, { slug: 'trauma', name: 'Trauma' } ],
            areaClusters: { ptsd: 1, trauma: 1 },
        } );
        const st   = makeState( { areas: [ 'ptsd' ] } );

        const results = runMatchmakerWith( data, st );

        expect( results[ 0 ].clusterAreas ).toContain( 'Trauma' );
        expect( results[ 0 ].matchedAreas ).toHaveLength( 0 ); // brak exact match
    } );

    it( 'nie przyznaje punktów cluster gdy obszary należą do różnych grup', () => {
        // cluster 1 = trauma, cluster 2 = relacje
        const psy  = makePsy( { obszary: [ 'relacje' ], id: 5 } );
        const data = makeData( [ psy ], {
            areaClusters: { ptsd: 1, relacje: 2 },
        } );
        const st   = makeState( { areas: [ 'ptsd' ] } );

        const results = runMatchmakerWith( data, st );

        expect( results[ 0 ].matchScore ).toBe( 0 );
    } );

    it( 'exact match ma wyższy wynik niż cluster match', () => {
        const psyExact   = makePsy( { obszary: [ 'ptsd' ], id: 10 } );
        const psyCluster = makePsy( { obszary: [ 'trauma' ], id: 11 } );
        const data       = makeData( [ psyExact, psyCluster ], {
            obszary:      [
                { slug: 'ptsd', name: 'PTSD' },
                { slug: 'trauma', name: 'Trauma' },
            ],
            areaClusters: { ptsd: 1, trauma: 1 },
        } );
        const st = makeState( { areas: [ 'ptsd' ] } );

        const results = runMatchmakerWith( data, st );

        // Pierwszy wynik powinien być psyExact (exact > cluster)
        expect( results[ 0 ].id ).toBe( 10 );
        expect( results[ 0 ].matchScore ).toBeGreaterThan( results[ 1 ].matchScore );
    } );
} );

// ─── Hard filter: visitType ───────────────────────────────────────────────────

describe( 'Hard filter — visitType', () => {
    it( 'wyklucza psychologa bez Online gdy visitType = Online', () => {
        const psyOnline      = makePsy( { id: 1, wizyta: 'Online' } );
        const psyStacjonarny = makePsy( { id: 2, wizyta: 'Stacjonarnie' } );
        const data           = makeData( [ psyOnline, psyStacjonarny ] );
        const st             = makeState( { visitType: 'Online' } );

        const results = runMatchmakerWith( data, st );

        expect( results ).toHaveLength( 1 );
        expect( results[ 0 ].id ).toBe( 1 );
    } );

    it( 'uwzględnia psychologa z Online, Stacjonarnie gdy visitType = Online', () => {
        const psy  = makePsy( { id: 1, wizyta: 'Online, Stacjonarnie' } );
        const data = makeData( [ psy ] );
        const st   = makeState( { visitType: 'Online' } );

        const results = runMatchmakerWith( data, st );

        expect( results ).toHaveLength( 1 );
    } );

    it( 'wyklucza psychologa bez Stacjonarnie gdy visitType = Stacjonarnie', () => {
        const psyOnline = makePsy( { id: 1, wizyta: 'Online' } );
        const psyOba    = makePsy( { id: 2, wizyta: 'Online, Stacjonarnie' } );
        const data      = makeData( [ psyOnline, psyOba ] );
        const st        = makeState( { visitType: 'Stacjonarnie' } );

        const results = runMatchmakerWith( data, st );

        expect( results ).toHaveLength( 1 );
        expect( results[ 0 ].id ).toBe( 2 );
    } );

    it( 'nie filtruje gdy visitType = "" (brak preferencji)', () => {
        const psyOnline      = makePsy( { id: 1, wizyta: 'Online' } );
        const psyStacjonarny = makePsy( { id: 2, wizyta: 'Stacjonarnie' } );
        const data           = makeData( [ psyOnline, psyStacjonarny ] );
        const st             = makeState( { visitType: '' } );

        const results = runMatchmakerWith( data, st );

        expect( results ).toHaveLength( 2 );
    } );
} );

// ─── PRECISION_W ──────────────────────────────────────────────────────────────

describe( 'PRECISION_W — bonus precyzji', () => {
    it( 'specjalista (1/1 obszarów pasuje) dostaje wyższy precision bonus niż generalista (1/5)', () => {
        const specialist = makePsy( { id: 1, obszary: [ 'depresja' ] } );
        const generalist = makePsy( { id: 2, obszary: [ 'depresja', 'lęk', 'trauma', 'relacje', 'stres' ] } );
        const data       = makeData( [ specialist, generalist ], {
            obszary: [ { slug: 'depresja', name: 'Depresja' } ],
        } );
        const st = makeState( { areas: [ 'depresja' ] } );

        const results   = runMatchmakerWith( data, st );
        const specScore = results.find( r => r.id === 1 ).matchScore;
        const genScore  = results.find( r => r.id === 2 ).matchScore;

        // specialist: 1.0 + (1/1)*0.8 = 1.8
        // generalist: 1.0 + (1/5)*0.8 = 1.16
        expect( specScore ).toBeCloseTo( 1.8, 5 );
        expect( genScore ).toBeCloseTo( 1.16, 5 );
        expect( specScore ).toBeGreaterThan( genScore );
    } );

    it( 'precision bonus = 0 gdy brak exact match (tylko cluster)', () => {
        const psy  = makePsy( { obszary: [ 'trauma' ] } );
        const data = makeData( [ psy ], {
            areaClusters: { ptsd: 1, trauma: 1 },
        } );
        const st = makeState( { areas: [ 'ptsd' ] } );

        const results = runMatchmakerWith( data, st );

        // Tylko AREA_CLUSTER = 0.5, brak precision bonus
        expect( results[ 0 ].matchScore ).toBeCloseTo( 0.5, 5 );
    } );
} );

// ─── countWith ────────────────────────────────────────────────────────────────

describe( 'countWith — liczenie psychologów po filtrach', () => {
    it( 'zlicza wszystkich bez filtrów', () => {
        const data = makeData( [ makePsy( { id: 1 } ), makePsy( { id: 2 } ), makePsy( { id: 3 } ) ] );
        const st   = makeState();

        expect( countWith( data, st ) ).toBe( 3 );
    } );

    it( 'filtruje po visitType', () => {
        const data = makeData( [
            makePsy( { id: 1, wizyta: 'Online' } ),
            makePsy( { id: 2, wizyta: 'Stacjonarnie' } ),
        ] );

        expect( countWith( data, makeState( { visitType: 'Online' } ) ) ).toBe( 1 );
    } );

    it( 'filtruje pary (who = couple)', () => {
        const data = makeData( [
            makePsy( { id: 1, spec: [ 'terapia-par' ] } ),
            makePsy( { id: 2, spec: [] } ),
        ] );

        expect( countWith( data, makeState( { who: 'couple' } ) ) ).toBe( 1 );
    } );

    it( 'zwraca 0 dla pustej bazy', () => {
        expect( countWith( makeData( [] ), makeState() ) ).toBe( 0 );
    } );
} );

// ─── getRelaxedSuggestions ────────────────────────────────────────────────────

describe( 'getRelaxedSuggestions — propozycje poluzowania filtrów', () => {
    it( 'sugeruje poluzowanie visitType gdy brak wyników z filtrem Online', () => {
        const data = makeData( [ makePsy( { id: 1, wizyta: 'Stacjonarnie' } ) ] );
        const st   = makeState( { visitType: 'Online' } );

        const suggestions = getRelaxedSuggestions( data, st );

        expect( suggestions.length ).toBeGreaterThan( 0 );
        const patch = suggestions.find( s => 'visitType' in s.patch );
        expect( patch ).toBeDefined();
        expect( patch.patch.visitType ).toBe( '' );
    } );

    it( 'nie sugeruje poluzowania gdy jest więcej wyników bez filtra', () => {
        // Brak psychologów = zero wyników nawet po poluzowaniu = brak sugestii
        const data = makeData( [] );
        const st   = makeState( { visitType: 'Online' } );

        const suggestions = getRelaxedSuggestions( data, st );

        expect( suggestions ).toHaveLength( 0 );
    } );

    it( 'każda sugestia zawiera label i patch', () => {
        const data = makeData( [
            makePsy( { id: 1, wizyta: 'Stacjonarnie', has_pelno: true, has_nisko: true } ),
        ] );
        const st = makeState( { visitType: 'Online', pricing: 'nisko' } );

        const suggestions = getRelaxedSuggestions( data, st );

        suggestions.forEach( s => {
            expect( s ).toHaveProperty( 'label' );
            expect( s ).toHaveProperty( 'patch' );
            expect( typeof s.label ).toBe( 'string' );
            expect( typeof s.patch ).toBe( 'object' );
        } );
    } );
} );

// ─── Sortowanie wyników ───────────────────────────────────────────────────────

describe( 'Sortowanie — wyższy matchScore pierwszy', () => {
    it( 'psycholog z wyższym wynikiem jest na pierwszej pozycji', () => {
        const psyWeak   = makePsy( { id: 1, obszary: [] } );        // score = 0
        const psyStrong = makePsy( { id: 2, obszary: [ 'lęk' ] } ); // score > 0
        const data      = makeData( [ psyWeak, psyStrong ], {
            obszary: [ { slug: 'lęk', name: 'Lęk' } ],
        } );
        const st = makeState( { areas: [ 'lęk' ] } );

        const results = runMatchmakerWith( data, st );

        expect( results[ 0 ].id ).toBe( 2 );
    } );

    it( 'zwraca maksymalnie W.MAX_FULL wyników', () => {
        const many = Array.from( { length: 20 }, ( _, i ) => makePsy( { id: i + 1 } ) );
        const data = makeData( many );

        const results = runMatchmakerWith( data, makeState() );

        expect( results.length ).toBeLessThanOrEqual( W.MAX_FULL );
    } );
} );
