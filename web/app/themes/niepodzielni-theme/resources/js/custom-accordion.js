document.addEventListener('DOMContentLoaded', function() {
    const accordionHeaders = document.querySelectorAll('.m_acordeon_head');

    accordionHeaders.forEach(function(head) {
        head.addEventListener('click', function() {
            // Przełącz klasę na nagłówku (dla strzałki)
            this.classList.toggle('m_acordeon_active');

            // === POCZĄTEK ZMIANY ===
            // Rozpocznij od pierwszego elementu za nagłówkiem
            let contentElement = this.nextElementSibling;

            // Pętla, która będzie działać dla wszystkich kolejnych elementów
            // z klasą .linkmenu LUB .linkmenu_w
            while (contentElement && (contentElement.classList.contains('linkmenu') || contentElement.classList.contains('linkmenu_w'))) {
                
                // Zastosuj logikę zwijania/rozwijania
                if (contentElement.style.maxHeight) {
                    contentElement.style.maxHeight = null;
                } else {
                    contentElement.style.maxHeight = contentElement.scrollHeight + 'px';
                }

                // Przejdź do następnego elementu, aby sprawdzić go w kolejnej iteracji
                contentElement = contentElement.nextElementSibling;
            }
            // === KONIEC ZMIANY ===
        });
    });
});
