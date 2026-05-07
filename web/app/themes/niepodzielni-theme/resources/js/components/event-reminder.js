/**
 * Event reminder form — opt-in T-24h.
 *
 * POST do /niepodzielni/v1/calendar/reminder z email + event_post_id + Turnstile.
 * Sukces: zamiana formy na komunikat "ustawione".
 * Błąd: pokazanie message w status div, formularz zostaje.
 */

import { npTrack, getPageContext } from '../lib/track.js';

const REST_URL = '/wp-json/niepodzielni/v1/calendar/reminder';

const startedForms = new WeakSet();

function fireReminderStarted(form) {
    if (startedForms.has(form)) return;
    startedForms.add(form);
    const eventId = parseInt(form.dataset.eventId, 10) || null;
    npTrack('form_started', {
        ...getPageContext(),
        form_id:   'event_reminder',
        form_name: 'event_reminder',
        item_id:   eventId ? String(eventId) : null,
    });
}

function setStatus(form, message, type = 'info') {
    const el = form.querySelector('[data-np-event-reminder-status]');
    if (!el) return;
    el.textContent = message;
    el.dataset.state = type;
}

async function submit(form) {
    const eventId = parseInt(form.dataset.eventId, 10);
    if (!eventId) return;

    const email = (form.querySelector('input[name="email"]')?.value ?? '').trim();
    if (!email || !email.includes('@')) {
        setStatus(form, 'Wprowadź poprawny adres email.', 'error');
        return;
    }

    const turnstileResponse = (form.querySelector('input[name="cf-turnstile-response"]')?.value
        ?? document.querySelector('input[name="cf-turnstile-response"]')?.value
        ?? '');

    const submitBtn = form.querySelector('.np-event-reminder__submit');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Wysyłam…';
    }

    setStatus(form, 'Sprawdzam…', 'info');

    try {
        const res = await fetch(REST_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email,
                event_post_id: eventId,
                'cf-turnstile-response': turnstileResponse,
            }),
        });

        const json = await res.json().catch(() => ({}));

        if (res.ok && json.status === 'ok') {
            try {
                npTrack('generate_lead', {
                    ...getPageContext(),
                    form_id:   'event_reminder',
                    form_name: 'event_reminder',
                    item_id:   String(eventId),
                });
            } catch {
                // noop
            }
            // Zamień form na success message
            form.innerHTML = `
                <p class="np-event-reminder__success" role="status">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" stroke="#27ae60" stroke-width="2"/>
                        <path d="M8 12l3 3 5-6" stroke="#27ae60" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    ${escapeHtml(json.message ?? 'Przypomnienie ustawione.')}
                </p>
            `;
        } else {
            const message = (json.message ?? '') || `Błąd serwera (${res.status}).`;
            setStatus(form, message, 'error');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Przypomnij mi';
            }
        }
    } catch (err) {
        // eslint-disable-next-line no-console
        console.error('[EventReminder] fetch failed:', err);
        setStatus(form, 'Błąd sieci. Spróbuj ponownie za chwilę.', 'error');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Przypomnij mi';
        }
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function init() {
    document.querySelectorAll('[data-np-event-reminder]').forEach((form) => {
        const emailInput = form.querySelector('input[name="email"]');
        if (emailInput) {
            emailInput.addEventListener('focus', () => fireReminderStarted(form));
            emailInput.addEventListener('input', () => fireReminderStarted(form), { once: true });
        }
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            submit(form);
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
