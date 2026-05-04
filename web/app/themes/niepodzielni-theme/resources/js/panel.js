/**
 * Panel psychologa — frontend logic.
 *
 * Wymaga window.npPanel = { ajaxUrl, nonce } (localize via setup.php).
 *
 * Trzy formularze, każdy submituje osobny endpoint AJAX:
 *   - panel-profile-form    → np_panel_save_profile      (biogram + tryb)
 *   - panel-taxonomies-form → np_panel_save_taxonomies   (4× checkbox group)
 *   - panel-photo-form      → np_panel_upload_photo      (FormData z plikiem)
 *
 * Toasty zielone/czerwone przez #panel-toast.
 */

import '../css/templates/panel.css';

const cfg = window.npPanel || {};
const panel = document.getElementById('np-panel');

if (panel && cfg.ajaxUrl && cfg.nonce) {
    initPanel();
}

function initPanel() {
    const postId = panel.dataset.postId;

    // ─── Toast helpers ─────────────────────────────────────────────────────
    const toastEl = document.getElementById('panel-toast');
    let toastTimer = null;

    function toast(message, type = 'success') {
        if (!toastEl) return;
        toastEl.textContent = message;
        toastEl.className = `panel-toast panel-toast--${type} panel-toast--visible`;
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            toastEl.classList.remove('panel-toast--visible');
            setTimeout(() => { toastEl.hidden = true; }, 300);
        }, 4000);
    }

    // ─── Wspólny helper do POST ────────────────────────────────────────────
    function postAjax(action, formData) {
        formData.append('action', action);
        formData.append('nonce', cfg.nonce);
        formData.append('post_id', postId);
        return fetch(cfg.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(r => r.json());
    }

    function handleSubmit(form, action, beforeSubmit) {
        if (!form) return;
        form.addEventListener('submit', e => {
            e.preventDefault();
            const submitBtn = form.querySelector('[type=submit]');
            const origLabel = submitBtn ? submitBtn.textContent : null;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Zapisuję…';
            }

            const fd = beforeSubmit ? beforeSubmit(form) : new FormData(form);

            postAjax(action, fd)
                .then(res => {
                    if (res.success) {
                        toast(res.data?.message || 'Zapisano.', 'success');
                        if (typeof onSuccess === 'function') onSuccess(res, form);
                        return res;
                    }
                    toast(res.data?.message || 'Błąd zapisu.', 'error');
                })
                .catch(err => {
                    console.error('panel ajax error', err);
                    toast('Błąd sieci. Spróbuj ponownie.', 'error');
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        if (origLabel) submitBtn.textContent = origLabel;
                    }
                });
        });
    }

    // ─── Profile form ──────────────────────────────────────────────────────
    handleSubmit(
        document.getElementById('panel-profile-form'),
        'np_panel_save_profile',
    );

    // ─── Taxonomies form ──────────────────────────────────────────────────
    const taxForm = document.getElementById('panel-taxonomies-form');
    handleSubmit(taxForm, 'np_panel_save_taxonomies');

    // Wizualne podświetlenie zaznaczonych tagów (toggle .panel-tag--checked)
    if (taxForm) {
        taxForm.addEventListener('change', e => {
            if (e.target.matches('input[type=checkbox]')) {
                e.target.closest('.panel-tag')?.classList.toggle('panel-tag--checked', e.target.checked);
            }
        });
    }

    // ─── Photo upload form ─────────────────────────────────────────────────
    const photoForm   = document.getElementById('panel-photo-form');
    const photoInput  = document.getElementById('panel-photo-input');
    const photoBtn    = photoForm?.querySelector('button[type=submit]');
    const photoPreview = document.getElementById('panel-photo-preview');

    if (photoInput && photoBtn) {
        photoInput.addEventListener('change', () => {
            const file = photoInput.files?.[0];
            photoBtn.disabled = !file;
            if (file && photoPreview) {
                // Pokaż lokalny preview natychmiast
                const reader = new FileReader();
                reader.onload = ev => {
                    photoPreview.innerHTML = `<img src="${ev.target.result}" alt="preview">`;
                };
                reader.readAsDataURL(file);
            }
        });
    }

    if (photoForm) {
        photoForm.addEventListener('submit', e => {
            e.preventDefault();
            const file = photoInput?.files?.[0];
            if (!file) {
                toast('Wybierz plik.', 'error');
                return;
            }

            const fd = new FormData();
            fd.append('photo', file);

            photoBtn.disabled = true;
            const orig = photoBtn.textContent;
            photoBtn.textContent = 'Wgrywam…';

            postAjax('np_panel_upload_photo', fd)
                .then(res => {
                    if (res.success) {
                        toast(res.data?.message || 'Zdjęcie zapisane.', 'success');
                        if (res.data?.url && photoPreview) {
                            photoPreview.innerHTML = `<img src="${res.data.url}" alt="zdjęcie profilowe">`;
                        }
                        photoInput.value = '';
                    } else {
                        toast(res.data?.message || 'Błąd uploadu.', 'error');
                    }
                })
                .catch(err => {
                    console.error('photo upload error', err);
                    toast('Błąd sieci.', 'error');
                })
                .finally(() => {
                    photoBtn.textContent = orig;
                    photoBtn.disabled = true; // dopiero po wybraniu nowego pliku
                });
        });
    }
}

// ── Panel: Opinie ────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const reviewsList = document.getElementById('panel-reviews-list');
    if (! reviewsList || ! cfg.ajaxUrl || ! cfg.nonce) return;

    const postId = document.getElementById('np-panel')?.dataset?.postId;
    if (! postId) return;

    const stars = n => '★'.repeat(n) + '☆'.repeat(5 - n);

    // ── Load reviews ────────────────────────────────────────────────────────
    async function loadReviews() {
        const fd = new FormData();
        fd.append('action', 'np_panel_get_reviews');
        fd.append('nonce',   cfg.nonce);
        fd.append('post_id', postId);

        try {
            const r    = await fetch(cfg.ajaxUrl, { method: 'POST', body: fd });
            const data = await r.json();
            if (data.success && data.data.reviews) {
                renderReviews(data.data.reviews);
            } else {
                reviewsList.innerHTML = '<p class="panel-help">Brak opinii lub błąd ładowania.</p>';
            }
        } catch {
            reviewsList.innerHTML = '<p class="panel-help">Błąd sieci.</p>';
        }
    }

    // ── Render ──────────────────────────────────────────────────────────────
    function renderReviews(reviews) {
        if (! reviews.length) {
            reviewsList.innerHTML = '<p class="panel-help">Brak opinii do wyświetlenia.</p>';
            return;
        }

        reviewsList.innerHTML = reviews.map(rv => `
            <div class="panel-review-item" data-id="${rv.id}">
                <div class="panel-review-item__header">
                    <strong>${escHtml(rv.author)}</strong>
                    <span class="panel-review-item__stars">${stars(rv.rating)}</span>
                    ${rv.verified_visit ? '<span class="rvw-badge">✓ Zweryfikowana wizyta</span>' : ''}
                    <span style="margin-left:auto;font-size:0.8rem;color:#888;">${escHtml(rv.date)}</span>
                </div>
                ${rv.content ? `<p style="font-size:0.9rem;margin:8px 0 12px">${escHtml(rv.content)}</p>` : ''}
                ${rv.reply
                    ? `<div class="rvw-reply">
                           <p class="rvw-reply__label">Twoja odpowiedź</p>
                           <p class="panel-review-item__reply-text">${escHtml(rv.reply.content)}</p>
                       </div>`
                    : ''}
                <div class="panel-review-item__reply-form">
                    <textarea class="panel-textarea panel-textarea--short" placeholder="${rv.reply ? 'Zmień odpowiedź…' : 'Napisz odpowiedź…'}" rows="2">${rv.reply ? escHtml(rv.reply.content) : ''}</textarea>
                    <button type="button" class="panel-button panel-button--primary reply-btn" style="margin-top:8px">
                        ${rv.reply ? 'Zaktualizuj odpowiedź' : 'Wyślij odpowiedź'}
                    </button>
                    <span class="reply-status" style="font-size:0.85rem;margin-left:10px;"></span>
                </div>
            </div>
        `).join('');

        // Bind reply buttons
        reviewsList.querySelectorAll('.reply-btn').forEach(btn => {
            btn.addEventListener('click', () => sendReply(btn));
        });
    }

    // ── Send reply ──────────────────────────────────────────────────────────
    async function sendReply(btn) {
        const item       = btn.closest('.panel-review-item');
        const commentId  = item?.dataset?.id;
        const textarea   = item?.querySelector('textarea');
        const status     = item?.querySelector('.reply-status');
        const content    = textarea?.value?.trim();

        if (! content) { if (status) status.textContent = 'Wpisz treść odpowiedzi.'; return; }

        btn.disabled = true;
        if (status) status.textContent = 'Zapisywanie…';

        const fd = new FormData();
        fd.append('action',     'np_panel_reply_review');
        fd.append('nonce',      cfg.nonce);
        fd.append('post_id',    postId);
        fd.append('comment_id', commentId);
        fd.append('content',    content);

        try {
            const r    = await fetch(cfg.ajaxUrl, { method: 'POST', body: fd });
            const data = await r.json();
            if (data.success) {
                if (status) { status.style.color = 'green'; status.textContent = 'Zapisano!'; }
                setTimeout(loadReviews, 800);
            } else {
                if (status) { status.style.color = 'red'; status.textContent = data.data?.message ?? 'Błąd.'; }
                btn.disabled = false;
            }
        } catch {
            if (status) { status.style.color = 'red'; status.textContent = 'Błąd sieci.'; }
            btn.disabled = false;
        }
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    loadReviews();
});
