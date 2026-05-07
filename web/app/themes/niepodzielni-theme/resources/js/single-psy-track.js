/**
 * single-psy-track.js
 *
 * GA4-style `view_item` event dla pojedynczego profilu psychologa.
 * Emit przy załadowaniu strony — TYLKO po stronie single-psycholog.
 *
 * Dane czytane z DOM (renderowane przez single-psycholog.blade.php):
 *   - item_id       → postId z body class (postid-N), via getPageContext
 *   - item_name     → .psy-name-h1 / [tytul_wyrozniony] (psychName z context)
 *   - item_category → 'psycholog'
 *   - price         → .dynamic-rate-price (z [dynamiczna_stawka_konsultacji])
 *
 * UWAGA: NIE używać na /pomoc-w-kryzysie/ (privacy — zero trackingu).
 *        Skrypt jest enqueue'owany tylko gdy is_singular('psycholog').
 */

import { npTrack, getPageContext } from './lib/track.js';

(function () {
    'use strict';

    function emitViewItem() {
        const ctx = getPageContext();
        if (!ctx.postId) return;

        const priceEl = document.querySelector('.dynamic-rate-price');
        const specEl  = document.querySelector('.psy-tag-profession');

        npTrack('view_item', {
            ...ctx,
            items: [{
                item_id:       String(ctx.postId),
                item_name:     ctx.psychName || document.title || '',
                item_category: 'psycholog',
                item_variant:  specEl ? specEl.textContent.trim() : '',
                price:         priceEl ? priceEl.textContent.trim() : '',
            }],
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', emitViewItem);
    } else {
        emitViewItem();
    }
})();
