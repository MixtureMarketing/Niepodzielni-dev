/**
 * reviews.js — Opinie psychologów
 * Vanilla JS ES2022+. Obsługa gwiazdek, submit Fetch API, CF Turnstile.
 */

const cfg = window.NpReviewsConfig ?? {};

// ── Star rating widget ────────────────────────────────────────────────────────

function initStarPicker(container) {
    const stars  = container.querySelectorAll('.rvw-star');
    const hidden = container.querySelector('input[name="rating"]');
    if (! stars.length || ! hidden) return;

    const setRating = (value, preview = false) => {
        stars.forEach((s, i) => {
            s.classList.toggle('is-active',   i < value);
            s.classList.toggle('is-preview',  preview && i < value);
        });
        if (! preview) hidden.value = value;
    };

    stars.forEach((star, idx) => {
        star.addEventListener('mouseenter', () => setRating(idx + 1, true));
        star.addEventListener('mouseleave', () => setRating(Number(hidden.value), false));
        star.addEventListener('click',      () => {
            setRating(idx + 1, false);
            star.closest('.rvw-star-group')?.querySelector('.field-error')?.textContent && (
                star.closest('.rvw-star-group').querySelector('.field-error').textContent = ''
            );
        });
        star.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setRating(idx + 1); }
        });
    });
}

// ── Turnstile init ────────────────────────────────────────────────────────────

function renderTurnstile(form) {
    const container = form.querySelector('.rvw-turnstile-container');
    if (! container || ! cfg.turnstileSiteKey) return;
    if (container.dataset.rendered) return;

    container.dataset.rendered = '1';

    if (typeof turnstile !== 'undefined') {
        const widgetId = turnstile.render(container, {
            sitekey:  cfg.turnstileSiteKey,
            callback: token => {
                form.querySelector('input[name="cf-turnstile-response"]').value = token;
            },
        });
        container.dataset.widgetId = widgetId;
    }
}

function resetTurnstile(form) {
    if (typeof turnstile === 'undefined') return;
    const container = form.querySelector('.rvw-turnstile-container');
    const id = container?.dataset?.widgetId;
    if (id !== undefined) turnstile.reset(id);
    else turnstile.reset();
    if (form.querySelector('input[name="cf-turnstile-response"]')) {
        form.querySelector('input[name="cf-turnstile-response"]').value = '';
    }
}

// ── Form validation ───────────────────────────────────────────────────────────

function validateReviewForm(form) {
    let valid = true;

    // Rating
    const rating = Number(form.querySelector('input[name="rating"]')?.value ?? 0);
    const starGroup = form.querySelector('.rvw-star-group');
    const starError = starGroup?.querySelector('.field-error');
    if (rating < 1 || rating > 5) {
        if (starError) starError.textContent = 'Wybierz ocenę (1–5 gwiazdek).';
        valid = false;
    } else {
        if (starError) starError.textContent = '';
    }

    // Email (only when not magic mode)
    if (! cfg.magicToken) {
        const emailField = form.querySelector('input[name="email"]');
        const emailError = emailField?.closest('.form-field')?.querySelector('.field-error');
        if (emailField && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailField.value.trim())) {
            if (emailError) emailError.textContent = 'Podaj prawidłowy adres e-mail.';
            valid = false;
        } else if (emailError) {
            emailError.textContent = '';
        }
    }

    return valid;
}

// ── Submit ────────────────────────────────────────────────────────────────────

async function handleReviewSubmit(form) {
    // Clear general error
    const genError = form.querySelector('.rvw-general-error');
    if (genError) { genError.textContent = ''; genError.hidden = true; }

    if (! validateReviewForm(form)) return;

    const btn = form.querySelector('[type="submit"]');
    btn.disabled    = true;
    btn.textContent = 'Wysyłanie…';

    const data = {
        post_id:    Number(form.dataset.postId),
        rating:     Number(form.querySelector('input[name="rating"]')?.value),
        email:      form.querySelector('input[name="email"]')?.value?.trim() ?? '',
        author_name: form.querySelector('input[name="author_name"]')?.value?.trim() ?? '',
        content:    form.querySelector('textarea[name="content"]')?.value?.trim() ?? '',
        'cf-turnstile-response': form.querySelector('input[name="cf-turnstile-response"]')?.value ?? '',
    };

    if (cfg.magicToken) {
        data.magic_token = cfg.magicToken;
        data.rvw_email   = cfg.rvwEmail;
        data.email       = cfg.rvwEmail;
    }

    try {
        const res  = await fetch(cfg.apiUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(data),
        });
        const json = await res.json();

        if (json.status === 'success') {
            form.innerHTML = `<div class="rvw-success" role="status">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="16 8 10 14 7 11"/></svg>
                <p>Dziękujemy za Twoją opinię!</p>
            </div>`;
            // Odśwież listę opinii bez przeładowania strony
            refreshReviewsList(Number(form.dataset.postId));
        } else {
            if (json.errors) {
                Object.entries(json.errors).forEach(([name, msg]) => {
                    const field   = form.querySelector(`[name="${name}"]`);
                    const wrapper = field?.closest('.form-field, .rvw-star-group');
                    const err     = wrapper?.querySelector('.field-error');
                    if (err) err.textContent = msg;
                });
            }
            if (genError) {
                genError.textContent = json.message ?? 'Wystąpił błąd. Spróbuj ponownie.';
                genError.hidden      = false;
            }
            resetTurnstile(form);
            btn.disabled    = false;
            btn.textContent = 'Wyślij opinię';
        }
    } catch {
        if (genError) {
            genError.textContent = 'Błąd połączenia. Sprawdź internet i spróbuj ponownie.';
            genError.hidden      = false;
        }
        resetTurnstile(form);
        btn.disabled    = false;
        btn.textContent = 'Wyślij opinię';
    }
}

// ── Reviews list refresh ──────────────────────────────────────────────────────

function refreshReviewsList(postId) {
    // Simple reload after 800ms to show new review
    setTimeout(() => window.location.reload(), 800);
}

// ── Inline validation ─────────────────────────────────────────────────────────

function bindInlineValidation(form) {
    form.querySelectorAll('input[required], textarea[required]').forEach(field => {
        field.addEventListener('blur', () => {
            const wrapper = field.closest('.form-field');
            const err     = wrapper?.querySelector('.field-error');
            if (! err) return;
            err.textContent = field.checkValidity() ? '' : (
                field.validity.valueMissing   ? 'To pole jest wymagane.' :
                field.validity.typeMismatch   ? 'Nieprawidłowa wartość.' : field.validationMessage
            );
            field.classList.toggle('is-invalid', ! field.checkValidity());
        });
        field.addEventListener('input', () => {
            if (field.checkValidity()) {
                field.classList.remove('is-invalid');
                const err = field.closest('.form-field')?.querySelector('.field-error');
                if (err) err.textContent = '';
            }
        });
    });
}

// ── Init ──────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('.rvw-form');
    if (! form) return;

    // Magic mode: prefill email, hide email + turnstile
    if (cfg.magicToken && cfg.rvwEmail) {
        form.querySelector('.rvw-email-field')?.setAttribute('hidden', '');
        form.querySelector('.rvw-turnstile-field')?.setAttribute('hidden', '');
        const hiddenEmail = form.querySelector('input[name="email"]');
        if (hiddenEmail) hiddenEmail.value = cfg.rvwEmail;
    } else {
        // Render Turnstile only when needed
        if (typeof turnstile !== 'undefined') {
            renderTurnstile(form);
        } else {
            window.addEventListener('load', () => renderTurnstile(form));
        }
    }

    initStarPicker(form);
    bindInlineValidation(form);

    form.addEventListener('submit', e => {
        e.preventDefault();
        handleReviewSubmit(form);
    });
});
