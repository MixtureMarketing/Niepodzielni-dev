/**
 * debounce — uniwersalny wrapper opóźniający wykonanie funkcji.
 *
 * Resetuje timer przy każdym kolejnym wywołaniu. Docelowa funkcja
 * zostaje wykonana dopiero po upływie `wait` ms od OSTATNIEGO wywołania.
 *
 * Zastosowania w projekcie:
 *   - psy-listing-atomic.js : wyszukiwarka #psy-search (300 ms)
 *   - psy-listing-atomic.js : wewnętrzne wyszukiwarki dropdownów (150 ms)
 *   - matchmaker.js         : wyszukiwarka kafelków obszarów (200 ms)
 *
 * Kontekst (this) i argumenty oryginalne są przekazywane przez
 * Function.prototype.apply, więc działa poprawnie zarówno w klasach
 * jak i zwykłych funkcjach. Arrow function jako `func` i tak zignoruje
 * kontekst zewnętrzny — to bezpieczne zachowanie.
 *
 * @param  {Function} func  Funkcja do zdebouncelowania
 * @param  {number}   wait  Opóźnienie w milisekundach
 * @returns {Function}      Nowa funkcja ze wbudowanym timerem
 *
 * @example
 * import { debounce } from './utils/debounce.js';
 *
 * input.addEventListener('input', debounce((e) => {
 *     fetchResults(e.target.value);
 * }, 300));
 */
export function debounce( func, wait ) {
    let timerId;

    return function ( ...args ) {
        // Anuluj poprzedni timer — resetujemy odliczanie
        clearTimeout( timerId );

        // Uruchom docelową funkcję dopiero po upływie `wait` ms bez kolejnego wywołania
        timerId = setTimeout( () => {
            func.apply( this, args );
        }, wait );
    };
}
