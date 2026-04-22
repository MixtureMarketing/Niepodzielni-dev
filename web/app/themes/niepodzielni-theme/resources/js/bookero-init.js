/**
 * bookero-init.js v5.1
 * Reads plugin IDs from window.niepodzielniBookero (via wp_localize_script).
 * Adds GTM dataLayer forwarding for Bookero tracking events.
 *
 * This file is loaded as type="module" (see filters.php → script_loader_tag).
 * ES modules are implicitly deferred — the browser executes them after DOM
 * parsing but BEFORE DOMContentLoaded fires. DOM is therefore available
 * immediately; no DOMContentLoaded wrapper is needed.
 */

// ─── BOOKERO API INTERCEPTOR ─────────────────────────────────────────────────
// Przechwytuje odpowiedzi Bookero API i zapisuje dane do WP — fire-and-forget.
//
// getMonth  → wyciąga najbliższą dostępną datę → bk_ingest_month
//   Dzięki temu "Najbliższy termin" w profilu psychologa aktualizuje się
//   automatycznie przy każdym wyświetleniu kalendarza — zero dodatkowych
//   requestów do Bookero, zero crona na stronie psychologa.
//
// getMonthDay → godziny dla wybranego dnia → bk_ingest_day_slots
//   Używane przez wspólny kalendarz listingu.

(function () {
    const _origFetch   = window.fetch.bind( window );
    const _origXHROpen = XMLHttpRequest.prototype.open;
    const _origXHRSend = XMLHttpRequest.prototype.send;

    function isGetMonth( url ) {
        return typeof url === 'string'
            && url.includes( 'plugin.bookero.pl' )
            && url.includes( 'getMonth' )
            && ! url.includes( 'getMonthDay' );
    }

    function isGetMonthDay( url ) {
        return typeof url === 'string'
            && url.includes( 'plugin.bookero.pl' )
            && url.includes( 'getMonthDay' );
    }

    // Wyciąga najbliższą datę z odpowiedzi getMonth
    // Struktura: { result:1, days: { "1":{date:"YYYY-MM-DD", valid_day:1, ...}, ... } }
    function extractNearestDate( data ) {
        try {
            const d    = typeof data === 'string' ? JSON.parse( data ) : data;
            const days = Object.values( d?.days || {} );
            const now  = Date.now();
            for ( const day of days ) {
                if ( day.valid_day > 0 && day.date ) {
                    const ts = new Date( day.date ).getTime();
                    if ( ts >= now - 86400000 ) { // od wczoraj (uwzględnia strefę)
                        return day.date; // "YYYY-MM-DD"
                    }
                }
            }
        } catch ( e ) {}
        return null;
    }

    // Wyciąga godziny z odpowiedzi getMonthDay
    function parseHours( data ) {
        try {
            const d = typeof data === 'string' ? JSON.parse( data ) : data;
            return ( d?.data?.hours || [] ).filter( h => h.valid ).map( h => h.hour );
        } catch ( e ) { return []; }
    }

    function postToWP( action, params ) {
        const cfg = window.niepodzielniBookero || {};
        const fd  = new FormData();
        fd.append( 'action', action );
        fd.append( 'nonce',  cfg.nonce || '' );
        for ( const [ k, v ] of Object.entries( params ) ) {
            fd.append( k, v );
        }
        _origFetch( cfg.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd } )
            .catch( () => {} );
    }

    function ingestMonth( urlStr, data ) {
        try {
            const nearestDate = extractNearestDate( data );
            if ( ! nearestDate ) return;

            const u    = new URL( urlStr );
            const bkId = u.searchParams.get( 'worker' );
            const cal  = u.searchParams.get( 'bookero_id' );
            if ( ! bkId || ! cal ) return;

            postToWP( 'bk_ingest_month', {
                worker_bk_id: bkId,
                cal_hash:     cal,
                nearest_date: nearestDate,
            } );
        } catch ( e ) {}
    }

    function ingestDaySlots( urlStr, hours ) {
        if ( ! hours.length ) return;
        try {
            const u    = new URL( urlStr );
            const bkId = u.searchParams.get( 'worker' );
            const date = u.searchParams.get( 'date' );
            const cal  = u.searchParams.get( 'bookero_id' );
            if ( ! bkId || ! date || ! cal ) return;

            postToWP( 'bk_ingest_day_slots', {
                worker_bk_id: bkId,
                cal_hash:     cal,
                date:         date,
                hours:        JSON.stringify( hours ),
            } );
        } catch ( e ) {}
    }

    // ── fetch interceptor ──────────────────────────────────────────────────────
    window.fetch = function ( input, init ) {
        const url = typeof input === 'string' ? input : ( input?.url || '' );
        const p   = _origFetch( input, init );

        if ( isGetMonth( url ) ) {
            p.then( r => r.clone().json()
                .then( d => ingestMonth( url, d ) )
                .catch( () => {} )
            ).catch( () => {} );
        } else if ( isGetMonthDay( url ) ) {
            p.then( r => r.clone().json()
                .then( d => ingestDaySlots( url, parseHours( d ) ) )
                .catch( () => {} )
            ).catch( () => {} );
        }
        return p;
    };

    // ── XHR interceptor (Bookero używa axios, który może fallować na XHR) ────
    XMLHttpRequest.prototype.open = function ( method, url ) {
        this._bk_getmonth = isGetMonth( url )    ? url : null;
        this._bk_url      = isGetMonthDay( url ) ? url : null;
        return _origXHROpen.apply( this, arguments );
    };
    XMLHttpRequest.prototype.send = function () {
        if ( this._bk_getmonth ) {
            const url = this._bk_getmonth;
            this._bk_getmonth = null;
            this.addEventListener( 'load', function () {
                ingestMonth( url, this.responseText );
            } );
        }
        if ( this._bk_url ) {
            const url = this._bk_url;
            this._bk_url = null;
            this.addEventListener( 'load', function () {
                ingestDaySlots( url, parseHours( this.responseText ) );
            } );
        }
        return _origXHRSend.apply( this, arguments );
    };
} )();

// ─── TRACKING (Cloudflare Zaraz + GTM fallback) ──────────────────────────────
// Primary:  zaraz.track() — server-side, zero JS w przeglądarce
// Fallback: window.dataLayer.push() — działa podczas migracji gdy Zaraz
//           jeszcze nie jest skonfigurowany w dashboardzie Cloudflare.
//
// Migracja GTM → Zaraz: gdy Zaraz jest aktywny zaraz.track() wygrywa;
// GTM można dezaktywować w WP Admin bez ryzyka utraty danych.

const trackingEvents = [
    'bookero-plugin:tracking:form-loaded',
    'bookero-plugin:tracking:add-to-cart',
    'bookero-plugin:tracking:purchase',
    'bookero-plugin:tracking:start-checkout',
    'bookero-plugin:tracking:failed-purchase',
    'bookero-plugin:tracking:waiting-purchase',
];

function getPageContext() {
    const postIdMatch = document.body.className.match(/postid-(\d+)/);
    const urlParams   = new URLSearchParams(window.location.search);
    const consultType = urlParams.get('konsultacje') || 'pelno';
    const nameEl      = document.querySelector('.psy-name-h1');
    return {
        postId:      postIdMatch ? parseInt(postIdMatch[1]) : null,
        consultType: consultType,
        psychName:   nameEl ? nameEl.textContent.trim() : null,
    };
}

/**
 * Wysyła event do Cloudflare Zaraz (primary) lub GTM dataLayer (fallback).
 * @param {string} name  Nazwa eventu, np. 'purchase', 'bookero_add_to_cart'
 * @param {Object} props Właściwości eventu
 */
function npTrack(name, props) {
    if (window.zaraz && typeof window.zaraz.track === 'function') {
        window.zaraz.track(name, props);
    } else if (window.dataLayer) {
        window.dataLayer.push(Object.assign({ event: name }, props));
    }
}

trackingEvents.forEach(function (eventName) {
    document.body.addEventListener(eventName, function (e) {
        const data    = (e.detail && e.detail.data) || {};
        const eventGA = eventName.replace('bookero-plugin:tracking:', 'bookero_');

        if (eventName === 'bookero-plugin:tracking:purchase') {
            const ctx      = getPageContext();
            const price    = parseFloat(data.value || data.price || data.total || data.amount || 0);
            const currency = data.currency || 'PLN';
            const txId     = data.transaction_id || data.order_id || data.booking_id
                          || (data.id ? String(data.id) : null)
                          || ('BKR-' + Date.now());

            // Mapuj cartItems Bookero → GA4 items.
            // Fallback na dane z kontekstu strony gdy cartItems brak.
            const items = Array.isArray(data.cartItems) && data.cartItems.length
                ? data.cartItems.map((item) => ({
                    item_id:       item.id || item.sku || String(ctx.postId || 'unknown'),
                    item_name:     item.itemName || ctx.psychName || 'Psycholog',
                    item_category: ctx.consultType === 'nisko' ? 'Konsultacja niskopłatna' : 'Konsultacja pełnopłatna',
                    price:         parseFloat(item.price || 0),
                    discount:      parseFloat(item.discount) || undefined,
                    quantity:      1,
                }))
                : [{
                    item_id:       ctx.postId ? String(ctx.postId) : 'unknown',
                    item_name:     ctx.psychName || 'Psycholog',
                    item_category: ctx.consultType === 'nisko' ? 'Konsultacja niskopłatna' : 'Konsultacja pełnopłatna',
                    price:         price,
                    quantity:      1,
                }];

            npTrack('purchase', {
                transaction_id:       txId,
                value:                price,
                currency:             currency,
                items,
                bookero_consult_type: ctx.consultType,
                bookero_psychologist: ctx.psychName,
            });
        } else {
            npTrack(eventGA, Object.assign(
                { bookeroInstanceId: e.detail && e.detail.instanceID },
                data,
            ));
        }
    });
});

// ─── CALENDAR INIT ───────────────────────────────────────────────────────────
// Runs synchronously — DOM is ready because this module is deferred.

const calendarWrapper  = document.getElementById('bookero_wrapper');
const preloaderWrapper = calendarWrapper ? calendarWrapper.querySelector('.bookero-preloader-wrapper') : null;
const whatCalendarEl   = document.getElementById('what_calendar');
const bkr              = window.niepodzielniBookero || {};

function showCalendarError(userMessage, techDetails) {
    console.error('Bookero Error:', userMessage, techDetails);
    if (preloaderWrapper) {
        preloaderWrapper.innerHTML = `
            <div class="bookero-error-notice" style="padding:20px;border:1px dashed #ff4d4d;color:#b30000;background:#fff5f5;border-radius:8px;text-align:center;margin:20px 0;">
                <p style="font-weight:bold;margin-bottom:5px;">${userMessage}</p>
                ${techDetails ? `<small style="display:block;opacity:0.7;">Szczegóły: ${techDetails}</small>` : ''}
            </div>`;
    }
}

if (calendarWrapper) {
    const calendarType = calendarWrapper.dataset.calendarType;
    const lang = bkr.lang || 'pl';
    let config = null;

    if (!calendarType) {
        showCalendarError('Nie rozpoznano typu kalendarza.', 'Brak atrybutu data-calendar-type w kontenerze #bookero_wrapper');
    } else {
        try {
            if (calendarType === 'product' || calendarType === 'osoby') {
                const id_pel = calendarWrapper.dataset.idPelno || '';
                const id_nis = calendarWrapper.dataset.idNisko || '';
                const urlParams = new URLSearchParams(window.location.search);
                let lp = urlParams.get('konsultacje');

                function getCookie(name) {
                    const v = `; ${document.cookie}`;
                    const p = v.split(`; ${name}=`);
                    if (p.length === 2) return p.pop().split(';').shift();
                    return '';
                }

                if (lp !== 'pelno' && lp !== 'nisko') lp = getCookie('rodzaj_konsultacji');
                if (lp !== 'pelno' && lp !== 'nisko') lp = 'pelno';

                if (lp === 'pelno' && id_pel === '') {
                    lp = (id_nis === '') ? 'error' : 'nisko';
                    console.warn('Brak ID dla konsultacji pełnopłatnych, przełączam na niskopłatne.');
                } else if (lp === 'nisko' && id_nis === '') {
                    lp = (id_pel === '') ? 'error' : 'pelno';
                    console.warn('Brak ID dla konsultacji niskopłatnych, przełączam na pełnopłatne.');
                }

                if (lp === 'pelno' || lp === 'nisko') {
                    const isPelno = lp === 'pelno';
                    config = {
                        id: isPelno ? (bkr.pelnoId || '') : (bkr.niskoId || ''),
                        container: 'bookero_render_target', type: 'calendar', position: '',
                        plugin_css: false, lang,
                        custom_config: { use_worker_id: isPelno ? id_pel : id_nis, hide_worker_info: 1 }
                    };
                    if (whatCalendarEl) whatCalendarEl.innerHTML = 'Umów wizytę';
                } else {
                    showCalendarError('Ten specjalista nie posiada przypisanych usług.', `Puste ID dla obu typów konsultacji (id_pel: "${id_pel}", id_nis: "${id_nis}")`);
                }
            } else if (calendarType === 'wspolny') {
                // [bookero_wspolny_kalendarz] — shared calendar across multiple workers
                const workerIds = (calendarWrapper.dataset.workerIds || '')
                    .split(',').map(Number).filter(Boolean);
                const calTyp    = calendarWrapper.dataset.calendarTyp || 'pelno';
                const widok     = calendarWrapper.dataset.calendarWidok || 'calendar';

                if (workerIds.length === 0) {
                    showCalendarError('Brak ID pracowników dla wspólnego kalendarza.', 'data-worker-ids jest pusty');
                } else {
                    config = {
                        id:        calTyp === 'nisko' ? (bkr.niskoId || '') : (bkr.pelnoId || ''),
                        container: 'bookero_render_target',
                        type:      widok,
                        position:  '',
                        plugin_css: false,
                        lang,
                        custom_config: { use_worker_ids: workerIds, hide_worker_info: 1 },
                    };
                    if (whatCalendarEl) whatCalendarEl.innerHTML = 'Umów wizytę';
                }

            } else {
                // Wydarzenie / warsztaty / grupy-wsparcia
                const serviceId = calendarWrapper.dataset.calendarId;

                if (calendarType && serviceId) {
                    config = {
                        id: calendarType,
                        container: 'bookero_render_target',
                        type: 'calendar',
                        position: '',
                        plugin_css: false,
                        lang: lang,
                        custom_config: { use_worker_id: serviceId, hide_worker_info: 1 }
                    };
                    if (whatCalendarEl) whatCalendarEl.innerHTML = 'Zapisz się';
                } else {
                    showCalendarError(
                        'Brak danych do załadowania kalendarza.',
                        `Upewnij się, że ID usługi (id_uslugi) jest wpisane w panelu bocznym. (Obecne ID: "${serviceId}", Hash: "${calendarType}")`
                    );
                }
            }

            if (config) {
                window.bookero_config = config;
                const script = document.createElement('script');
                script.src = 'https://cdn.bookero.pl/plugin/v2/js/bookero-compiled.js';
                script.defer = true;
                script.onerror = () => showCalendarError('Nie udało się załadować zewnętrznego skryptu Bookero.', 'Błąd ładowania pliku cdn.bookero.pl');
                document.body.appendChild(script);
            }

        } catch (err) {
            showCalendarError('Wystąpił nieoczekiwany błąd podczas inicjalizacji kalendarza.', err.message);
        }
    }
}
