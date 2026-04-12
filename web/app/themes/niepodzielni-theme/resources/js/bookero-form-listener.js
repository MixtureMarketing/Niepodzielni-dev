/**
 * bookero-form-listener.js
 * Po udanej rezerwacji w Bookero: aktualizuje datę w bazie przez WP AJAX.
 *
 * Słucha natywnego eventu Bookero zamiast pollować DOM.
 */
document.addEventListener('DOMContentLoaded', function () {
    const bookeroWrapper = document.getElementById('bookero_wrapper');
    if (!bookeroWrapper) return;

    const urlParams   = new URLSearchParams(window.location.search);
    const consultType = urlParams.get('konsultacje');

    let workerID, metaKeySlug, accountID;

    if (consultType === 'pelno') {
        workerID    = bookeroWrapper.dataset.idPelno;
        metaKeySlug = 'pelnoplatny';
        accountID   = 1;
    } else if (consultType === 'nisko') {
        workerID    = bookeroWrapper.dataset.idNisko;
        metaKeySlug = 'niskoplatny';
        accountID   = 2;
    }

    if (!workerID || !metaKeySlug) return;

    const bodyClasses  = document.body.className.match(/postid-(\d+)/);
    const wooProductId = bodyClasses ? parseInt(bodyClasses[1]) : 0;
    if (!wooProductId) return;

    document.body.addEventListener('bookero-plugin:tracking:purchase', function () {
        const bkr = window.niepodzielniBookero || {};
        fetch(bkr.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:            'bookero_update_date',
                worker_id_bookero:  workerID,
                woo_product_id:     wooProductId,
                api_account_id:     accountID,
                account_type_slug:  metaKeySlug,
                security:           bkr.nonce || '',
            }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                console.log('✅ Bookero: data zaktualizowana →', data.data.date);
            }
        })
        .catch(() => {});
    }, { once: true });
});
