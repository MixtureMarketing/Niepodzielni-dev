{{-- Shared Bookero Calendar --}}
{{-- @param string $rodzaj  'pelno' | 'nisko' --}}

<div class="psy-wspolny-kalendarz-section">
    <div class="psy-container">
        <div class="psy-wspolny-header">
            <h2>Najbliższy dostępny termin</h2>
            <p>Zarezerwuj wizytę u pierwszego dostępnego specjalisty</p>
        </div>
        <div class="bk-shared-cal" data-typ="{{ $rodzaj }}"></div>
    </div>
</div>
