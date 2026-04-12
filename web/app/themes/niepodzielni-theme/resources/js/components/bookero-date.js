/**
 * bookero-date.js
 * Odświeżanie terminu Bookero na żywo (Live Refresh) dla strony produktu.
 */
document.addEventListener('DOMContentLoaded', function () {
    const dateValueEl = document.querySelector('.bookero-date-value');
    if (!dateValueEl || typeof jQuery === 'undefined') return;

    const productId   = dateValueEl.dataset.productId;
    const accountType = dateValueEl.dataset.type; // 'pelnoplatny' lub 'niskoplatny'

    const wrapper    = document.getElementById('bookero_wrapper');
    const workerId   = wrapper
        ? (accountType === 'niskoplatny' ? wrapper.dataset.idNisko : wrapper.dataset.idPelno)
        : null;
    const apiAccountId = (accountType === 'niskoplatny') ? 2 : 1;

    if (!productId || !workerId) return;

    jQuery.ajax({
        url:  (window.niepodzielniBookero || {}).ajaxUrl || '/wp-admin/admin-ajax.php',
        type: 'POST',
        data: {
            action:              'bookero_update_date',
            woo_product_id:      productId,
            worker_id_bookero:   workerId,
            api_account_id:      apiAccountId,
            account_type_slug:   accountType,
            security:            (window.niepodzielniBookero || {}).nonce || '',
        },
        success: function (response) {
            if (response.success && response.data.date) {
                dateValueEl.textContent = response.data.date;
                dateValueEl.classList.add('date-updated');
            }
        },
    });
});
