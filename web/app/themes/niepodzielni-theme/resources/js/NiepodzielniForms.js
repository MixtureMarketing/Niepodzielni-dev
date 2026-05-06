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

        document.querySelectorAll('[data-prefix-select]').forEach(widget => {
            this.#initPrefixSelect(widget);
        });
    }

    // ── Podpięcie formularza ──────────────────────────────────────────────────

    #bindForm(form) {
        const formId = form.dataset.niepodzielniForm;
        if (! formId) return;

        form.querySelectorAll('input, textarea, select').forEach(field => {
            field.addEventListener('blur',  () => this.#validateField(field));
            field.addEventListener('input', () => {
                this.#applyMask(field);
                this.#clearFieldError(field);
            });
            field.addEventListener('change', () => this.#validateField(field));
        });

        form.addEventListener('submit', async e => {
            e.preventDefault();
            await this.#handleSubmit(form, formId);
        });
    }

    // ── Custom prefix select ──────────────────────────────────────────────────

    #initPrefixSelect(widget) {
        const trigger   = widget.querySelector('[data-prefix-trigger]');
        const dropdown  = widget.querySelector('[data-prefix-dropdown]');
        const search    = widget.querySelector('[data-prefix-search]');
        const list      = widget.querySelector('[data-prefix-list]');
        const hidden    = widget.querySelector('[data-prefix-input]');
        const flagEl    = widget.querySelector('[data-prefix-flag]');
        const labelEl   = widget.querySelector('[data-prefix-label]');

        if (! trigger || ! dropdown) return;

        // Powiąż wejście telefonu z tym widgetem
        const phoneInput = widget.closest('.form-field__phone-wrapper')
                               ?.querySelector('[data-phone-input]');

        const open = () => {
            dropdown.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            search?.focus();
        };

        const close = () => {
            dropdown.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            if (search) search.value = '';
            this.#filterOptions(list, '');
        };

        const selectOption = (li) => {
            const val   = li.dataset.value;
            const iso   = li.dataset.iso;
            const min   = parseInt(li.dataset.min, 10);
            const max   = parseInt(li.dataset.max, 10);
            const label = li.dataset.value; // e.g. "+48"

            hidden.value = val;

            if (flagEl) {
                flagEl.className = `fi fi-${iso}`;
            }
            if (labelEl) {
                labelEl.textContent = label;
            }

            // Zaktualizuj minlength/maxlength i placeholder pola telefonu
            if (phoneInput) {
                phoneInput.minLength = min;
                phoneInput.maxLength = max;
                const ph = li.dataset.placeholder;
                if (ph) phoneInput.placeholder = ph;
                // Przytnij wartość jeśli przekracza nowe max
                if (phoneInput.value.length > max) {
                    phoneInput.value = phoneInput.value.substring(0, max);
                }
                // Wyczyść błąd walidacji przy zmianie prefiksu
                this.#clearFieldError(phoneInput);
            }

            list.querySelectorAll('.prefix-select__option').forEach(opt => {
                opt.classList.toggle('is-selected', opt === li);
                opt.setAttribute('aria-selected', String(opt === li));
            });

            close();
            trigger.focus();
        };

        // Inicjalizacja — synchronizuj placeholder i minlength z domyślnie zaznaczoną opcją
        const initSelected = list.querySelector('.prefix-select__option.is-selected');
        if (initSelected && phoneInput) {
            const initPh = initSelected.dataset.placeholder;
            if (initPh) phoneInput.placeholder = initPh;
            phoneInput.minLength = parseInt(initSelected.dataset.min, 10) || 1;
            phoneInput.maxLength = parseInt(initSelected.dataset.max, 10) || 15;
        }

        // Toggle
        trigger.addEventListener('click', () => {
            dropdown.hidden ? open() : close();
        });

        // Zamknij po kliknięciu poza widgetem
        document.addEventListener('click', (e) => {
            if (! widget.contains(e.target)) close();
        });

        // Zamknij po Escape
        widget.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { close(); trigger.focus(); }
        });

        // Wyszukiwarka
        search?.addEventListener('input', () => {
            this.#filterOptions(list, search.value);
        });

        // Wybór opcji kliknięciem
        list.addEventListener('click', (e) => {
            const li = e.target.closest('.prefix-select__option');
            if (li && ! li.hidden) selectOption(li);
        });

        // Nawigacja klawiaturą w liście
        list.addEventListener('keydown', (e) => {
            const visible  = [...list.querySelectorAll('.prefix-select__option:not([hidden])')];
            const focused  = list.querySelector('.prefix-select__option.is-focused');
            const idx      = visible.indexOf(focused);

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                const next = visible[idx + 1] ?? visible[0];
                this.#focusOption(list, next);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                const prev = visible[idx - 1] ?? visible[visible.length - 1];
                this.#focusOption(list, prev);
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (focused) selectOption(focused);
            }
        });

        // Obsługa Tab w search → przenieś focus na listę
        search?.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                const first = list.querySelector('.prefix-select__option:not([hidden])');
                if (first) this.#focusOption(list, first);
            }
        });
    }

    #filterOptions(list, query) {
        const q = query.toLowerCase().trim();
        list.querySelectorAll('.prefix-select__option').forEach(li => {
            const label = (li.dataset.label ?? '').toLowerCase();
            const code  = (li.dataset.value ?? '').toLowerCase();
            li.hidden   = q !== '' && ! label.includes(q) && ! code.includes(q);
            li.classList.remove('is-focused');
        });
    }

    #focusOption(list, li) {
        list.querySelectorAll('.prefix-select__option').forEach(o => o.classList.remove('is-focused'));
        if (li) {
            li.classList.add('is-focused');
            li.focus();
        }
    }

    // ── Maski ─────────────────────────────────────────────────────────────────

    #applyMask(field) {
        const mask = field.dataset.mask;
        if (! mask) return;

        let val = field.value;

        if (mask === '00-000') {
            val = val.replace(/\D/g, '').substring(0, 5);
            if (val.length > 2) {
                val = val.substring(0, 2) + '-' + val.substring(2);
            }
            field.value = val;
        }

        if (mask === 'phone') {
            const max = field.maxLength > 0 ? field.maxLength : 15;
            const min = field.minLength > 0 ? field.minLength : 1;
            const cleaned = val.replace(/\D/g, '').substring(0, max);
            field.value = cleaned;
            if (cleaned.length > 0 && cleaned.length < min) {
                field.setCustomValidity(`Numer musi mieć co najmniej ${min} cyfr.`);
            } else {
                field.setCustomValidity('');
            }
        }

        if (mask === 'no-digits') {
            const cleaned = val.replace(/[0-9]/g, '');
            if (cleaned !== val) {
                field.value = cleaned;
                field.setCustomValidity(field.dataset.errorNoDigits ?? 'To pole nie może zawierać cyfr.');
            } else {
                field.setCustomValidity('');
            }
        }
    }

    // ── Walidacja HTML5 ───────────────────────────────────────────────────────

    #validateField(field) {
        // Pomijaj ukryte inputy (np. [data-prefix-input])
        if (field.type === 'hidden') return true;

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
        if (validity.valueMissing)    return 'To pole jest wymagane.';
        if (validity.typeMismatch)    return field.type === 'email' ? 'Podaj prawidłowy adres e-mail.' : 'Nieprawidłowa wartość.';
        if (validity.tooLong)         return `Maksymalnie ${field.maxLength} znaków.`;
        if (validity.tooShort)        return `Minimum ${field.minLength} cyfr.`;
        if (validity.patternMismatch) return field.dataset.errorPattern ?? 'Nieprawidłowy format.';
        if (validity.rangeOverflow)   return `Maksymalna wartość: ${field.max}.`;
        if (validity.rangeUnderflow)  return `Minimalna wartość: ${field.min}.`;
        return field.validationMessage || 'Nieprawidłowa wartość.';
    }

    // ── Submit formularza ─────────────────────────────────────────────────────

    async #handleSubmit(form, formId) {
        this.#clearGeneralError(form);

        let isValid = true;
        form.querySelectorAll('input, textarea, select').forEach(field => {
            if (field.type === 'hidden') return;
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
        const fieldsWrapper = form.querySelector('.form-fields');
        if (fieldsWrapper) {
            fieldsWrapper.hidden = true;
        } else {
            form.querySelectorAll('.form-field, [type="submit"]').forEach(el => { el.hidden = true; });
        }

        form.querySelector('.cf-turnstile')?.closest('.form-field, div')?.setAttribute('hidden', '');
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
        const data     = {};
        const formData = new FormData(form);

        for (const [key, value] of formData.entries()) {
            data[key] = value;
        }

        form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            if (! (cb.name in data)) {
                data[cb.name] = false;
            }
        });

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
            btn.disabled               = loading;
            btn.dataset.originalText ??= btn.textContent;
            btn.textContent            = loading ? 'Wysyłanie…' : btn.dataset.originalText;
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
