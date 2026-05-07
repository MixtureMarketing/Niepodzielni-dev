/**
 * Calendar — subscribe button (webcal:// → schowek).
 *
 * Toggle list/calendar i nawigacja prev/next month są w 100% server-rendered
 * (linki HTML działają bez JS). JS dodaje tylko wygodę kopiowania URL feed'u.
 */

function showToast(message, parent) {
    const toast = document.createElement('div');
    toast.className = 'np-cal__toast';
    toast.textContent = message;
    toast.setAttribute('role', 'status');
    parent.appendChild(toast);
    setTimeout(() => toast.classList.add('is-visible'), 10);
    setTimeout(() => {
        toast.classList.remove('is-visible');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

async function handleSubscribe(button) {
    const url = button.dataset.webcalUrl;
    if (!url) return;

    // Próba 1: spróbuj otworzyć webcal:// — większość OS przekierowuje do
    // domyślnego klienta kalendarza (macOS/iOS, Outlook, niektóre Linuksy).
    const opened = window.open(url, '_blank');

    // Niezależnie od success'u open(), kopiujemy też URL na wszelki wypadek.
    try {
        await navigator.clipboard.writeText(url);
        showToast(opened ? 'Otwieram klienta kalendarza, link skopiowany' : 'Link skopiowany do schowka', button.parentElement);
    } catch {
        if (!opened) {
            // Brak Clipboard API i okno się nie otworzyło — pokaż URL do skopiowania ręcznie.
            window.prompt('Skopiuj poniższy adres do swojego klienta kalendarza:', url);
        } else {
            showToast('Otwieram klienta kalendarza', button.parentElement);
        }
    }
}

function init() {
    document.querySelectorAll('[data-np-calendar-subscribe]').forEach((btn) => {
        btn.addEventListener('click', () => handleSubscribe(btn));
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
