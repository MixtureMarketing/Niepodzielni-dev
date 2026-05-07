@php
  // Fallback: gdy nie ma dedykowanego terminu "kryzys" — pokazujemy pełną psychomapę
  // (wszystkie ośrodki pomocy). Decyzja: tymczasowo rezygnujemy z filtrowania per-term,
  // bo kategoria "kryzys" w taksonomii nie jest jeszcze otagowana w bazie.
  $crisisTermId = function_exists('np_crisis_term_id') ? np_crisis_term_id() : null;
@endphp

<section class="np-crisis__map" aria-labelledby="np-crisis-map-title">
  <div class="np-crisis__container">
    <h2 id="np-crisis-map-title" class="np-crisis__h2">Ośrodki pomocy w Polsce</h2>
    <p class="np-crisis__map-intro">
      Telefony zaufania, ośrodki interwencji kryzysowej i oddziały psychiatryczne.
      W razie bezpośredniego zagrożenia życia zadzwoń pod 112.
    </p>

    <div class="np-crisis__map-wrap">
      <div
        id="np-crisis-map"
        class="np-crisis__map-canvas"
        role="region"
        aria-label="Mapa ośrodków pomocy"
        data-np-crisis-map
        data-term-id="{{ $crisisTermId ?: '' }}"
        data-api-url="{{ esc_url(rest_url('niepodzielni/v1/psychomapa')) }}"
      ></div>
      <p id="np-crisis-map-loading" class="np-crisis__map-loading">Ładowanie mapy…</p>
    </div>
    <ul id="np-crisis-map-list" class="np-crisis__map-list" role="list" aria-label="Lista ośrodków na mapie"></ul>
  </div>
</section>
