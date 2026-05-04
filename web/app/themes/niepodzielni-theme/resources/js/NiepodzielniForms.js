/**
 * NiepodzielniForms — Config-Driven Forms Framework
 * Vanilla JS ES2022+. Podpina się pod [data-niepodzielni-form].
 */

class NiepodzielniForms {
    /** @type {string} */
    #apiBase;

    constructor() {
        this.#apiBase = window.NpFormsConfig?.apiBase ?? '/wp-json/niepodzielni/v1/forms';
        this.#init();
    }

    #init() {
        document.querySelectorAll('[data-niepodzielni-form]').forEach(form => {
            this.#bindForm(form);
        });
    }

    // ── Podpięcie formularza ──────────────────────────────────────────────────

    #bindForm(form) {
        const formId = form.dataset.niepodzielniForm;
        if (! formId) return;

        // Walidacja inline przy opuszczeniu pola i w trakcie wpisywania
        form.querySelectorAll('input, textarea, select').forEach(field => {
            field.addEventListener('blur',  () => this.#validateField(field));
            field.addEventListener('input', () => this.#clearFieldError(field));
            field.addEventListener('change', () => this.#validateField(field));
        });

        form.addEventListener('submit', async e => {
            e.preventDefault();
            await this.#handleSubmit(form, formId);
        });
    }

    // ── Walidacja HTML5 ───────────────────────────────────────────────────────

    #validateField(field) {
        const wrapper  = field.closest('.form-field');
        const errorEl  = wrapper?.querySelector('.field-error');
        const isValid  = field.checkValidity();

        field.classList.toggle('is-invalid', ! isValid);
        field.classList.toggle('is-valid',     isValid && field.value !== '');

        if (errorEl) {
            errorEl.textContent = isValid ? '' : this.#getValidityMessage(field);
        }

        return isValid;
    }

    #clearFieldError(field) {
        if (field.checkValidity()) {
            field.classList.remove('is-invalid');
            field.classList.toggle('is-valid', field.value !== '');
            const errorEl = field.closest('.form-field')?.querySelector('.field-error');
            if (errorEl) errorEl.textContent = '';
        }
    }

    #getValidityMessage(field) {
        const { validity } = field;
        if (validity.valueMissing)   return 'To pole jest wymagane.';
        if (validity.typeMismatch)   return field.type === 'email' ? 'Podaj prawidłowy adres e-mail.' : 'Nieprawidłowa wartość.';
        if (validity.tooLong)        return `Maksymalnie ${field.maxLength} znaków.`;
        if (validity.tooShort)       return `Minimum ${field.minLength} znaków.`;
        if (validity.patternMismatch) return field.dataset.errorPattern ?? 'Nieprawidłowy format.';
        if (validity.rangeOverflow)  return `Maksymalna wartość: ${field.max}.`;
        if (validity.rangeUnderflow) return `Minimalna wartość: ${field.min}.`;
        return field.validationMessage || 'Nieprawidłowa wartość.';
    }

    // ── Submit formularza ─────────────────────────────────────────────────────

    async #handleSubmit(form, formId) {
        // Wyczyść poprzednie błędy ogólne
        this.#clearGeneralError(form);

        // Waliduj wszystkie pola
        let isValid = true;
        form.querySelectorAll('input, textarea, select').forEach(field => {
            if (! this.#validateField(field)) isValid = false;
        });

        if (! isValid) {
            form.querySelector('.is-invalid')?.focus();
            return;
        }

        const submitBtn = form.querySelector('[type="submit"]');
        this.#setLoading(form, submitBtn, true);

        try {
            const payload = this.#collectFormData(form);

            const response = await fetch(`${this.#apiBase}/${formId}/submit`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': this.#getNonce() },
                body:    JSON.stringify(payload),
            });

            const result = await response.json();

            if (result.status === 'success') {
                this.#showSuccess(form, result.message);
            } else if (result.status === 'requires_verification') {
                this.#showVerificationStep(form, formId, result.submission_id, result.message);
            } else {
                // Błędy walidacji z serwera
                if (result.errors && typeof result.errors === 'object') {
                    this.#applyServerErrors(form, result.errors);
                } else {
                    this.#showGeneralError(form, result.message ?? 'Wystąpił błąd. Spróbuj ponownie.');
                }
                this.#resetTurnstile(form);
            }
        } catch {
            this.#showGeneralError(form, 'Błąd połączenia. Sprawdź internet i spróbuj ponownie.');
            this.#resetTurnstile(form);
        } finally {
            this.#setLoading(form, submitBtn, false);
        }
    }

    // ── Krok weryfikacji OTP ──────────────────────────────────────────────────

    #showVerificationStep(form, formId, submissionId, hint) {
        // Ukryj główne pola
        const fieldsWrapper = form.querySelector('.form-fields');
        if (fieldsWrapper) {
            fieldsWrapper.hidden = true;
        } else {
            form.querySelectorAll('.form-field, [type="submit"]').forEach(el => { el.hidden = true; });
        }

        // Ukryj CF Turnstile
        form.querySelector('.cf-turnstile')?.closest('.form-field, div')?.setAttribute('hidden', '');

        // Usuń ewentualny poprzedni krok OTP
        form.querySelector('.form-otp-section')?.remove();

        const section = document.createElement('div');
        section.className = 'form-otp-section';
        section.innerHTML = `
            <p class="form-otp-hint">${hint ?? 'Na Twój adres e-mail wysłaliśmy 6-cyfrowy kod. Wprowadź go poniżej.'}</p>
            <div class="form-field">
                <label class="form-field__label" for="np_otp_code">Kod weryfikacyjny</label>
                <input
                    class="form-field__input"
                    type="text"
                    id="np_otp_code"
                    name="otp_code"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    required
                    autocomplete="one-time-code"
                    placeholder="123456"
                />
                <span class="field-error" role="alert"></span>
            </div>
            <div class="form-general-error" hidden></div>
            <button type="button" class="btn btn--primary otp-submit-btn">Zweryfikuj kod</button>
        `;

        form.appendChild(section);

        const otpInput = section.querySelector('#np_otp_code');
        const otpBtn   = section.querySelector('.otp-submit-btn');
        const otpError = section.querySelector('.form-general-error');

        otpInput.addEventListener('blur',  () => this.#validateField(otpInput));
        otpInput.addEventListener('input', () => this.#clearFieldError(otpInput));

        otpBtn.addEventListener('click', async () => {
            this.#validateField(otpInput);
            if (! otpInput.checkValidity()) { otpInput.focus(); return; }

            otpBtn.disabled    = true;
            otpBtn.textContent = 'Weryfikacja…';

            try {
                const res  = await fetch(`${this.#apiBase}/${formId}/verify`, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': this.#getNonce() },
                    body:    JSON.stringify({ submission_id: submissionId, otp_code: otpInput.value }),
                });
                const data = await res.json();

                if (data.status === 'success') {
                    section.remove();
                    this.#showSuccess(form, data.message);
                } else {
                    otpError.textContent = data.message ?? 'Nieprawidłowy kod.';
                    otpError.hidden      = false;
                    otpInput.classList.add('is-invalid');
                    otpBtn.disabled    = false;
                    otpBtn.textContent = 'Zweryfikuj kod';
                }
            } catch {
                otpError.textContent = 'Błąd połączenia. Spróbuj ponownie.';
                otpError.hidden      = false;
                otpBtn.disabled    = false;
                otpBtn.textContent = 'Zweryfikuj kod';
            }
        });

        otpInput.focus();
    }

    // ── Helpers UI ────────────────────────────────────────────────────────────

    #collectFormData(form) {
        const data       = {};
        const formData   = new FormData(form);

        for (const [key, value] of formData.entries()) {
            data[key] = value;
        }

        // Checkboxy nie zaznaczone nie trafiają do FormData — dodaj jawnie jako false
        form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            if (! (cb.name in data)) {
                data[cb.name] = false;
            }
        });

        // Dołącz URL strony źródłowej
        data['_source_url'] = window.location.href;

        return data;
    }

    #applyServerErrors(form, errors) {
        Object.entries(errors).forEach(([name, message]) => {
            const field   = form.querySelector(`[name="${name}"]`);
            const wrapper = field?.closest('.form-field');
            const errorEl = wrapper?.querySelector('.field-error');

            field?.classList.add('is-invalid');
            if (errorEl) errorEl.textContent = message;
        });

        form.querySelector('.is-invalid')?.focus();
    }

    #showSuccess(form, message) {
        form.innerHTML = `<div class="form-success" role="status">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="16 8 10 14 7 11"/></svg>
            <p>${message ?? 'Dziękujemy! Twoje zgłoszenie zostało przyjęte.'}</p>
        </div>`;
    }

    #showGeneralError(form, message) {
        let el = form.querySelector('.form-general-error');
        if (! el) {
            el = document.createElement('div');
            el.className = 'form-general-error';
            el.setAttribute('role', 'alert');
            form.prepend(el);
        }
        el.textContent = message;
        el.hidden       = false;
    }

    #clearGeneralError(form) {
        const el = form.querySelector('.form-general-error');
        if (el) { el.textContent = ''; el.hidden = true; }
    }

    #setLoading(form, btn, loading) {
        form.classList.toggle('is-loading', loading);
        if (btn) {
            btn.disabled                   = loading;
            btn.dataset.originalText     ??= btn.textContent;
            btn.textContent                = loading ? 'Wysyłanie…' : btn.dataset.originalText;
        }
    }

    #resetTurnstile(form) {
        if (typeof turnstile === 'undefined') return;
        const widget = form.querySelector('.cf-turnstile');
        if (! widget) return;
        const id = widget.dataset.turnstileWidgetId;
        id ? turnstile.reset(id) : turnstile.reset();
    }

    #getNonce() {
        return window.NpFormsConfig?.nonce ?? '';
    }
}

document.addEventListener('DOMContentLoaded', () => new NiepodzielniForms());
