/**
 * appointment-widget.js
 * Obsługa widgetu "UMÓW SIĘ" w headerze — hover i kliknięcie mobilne.
 */
document.addEventListener('DOMContentLoaded', function () {
    const widget = document.getElementById('appointmentWidget');
    if (!widget) return;

    widget.addEventListener('mouseenter', () => widget.classList.add('is-open'));
    widget.addEventListener('mouseleave', () => widget.classList.remove('is-open'));

    widget.addEventListener('click', (e) => {
        if (window.innerWidth <= 1024) {
            e.stopPropagation();
            widget.classList.toggle('is-open');
        }
    });

    document.addEventListener('click', () => widget.classList.remove('is-open'));
});
