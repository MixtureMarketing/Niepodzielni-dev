<section class="np-crisis__map" aria-labelledby="np-crisis-map-title">
  <div class="np-crisis__container">
    <h2 id="np-crisis-map-title" class="np-crisis__h2">Ośrodki pomocy w Polsce</h2>
    <p class="np-crisis__map-intro">
      Telefony zaufania, ośrodki interwencji kryzysowej i oddziały psychiatryczne.
      W razie bezpośredniego zagrożenia życia zadzwoń pod 112.
    </p>

    {{-- data-term-id="" => crisis-map.js pokaże wszystkie ośrodki (fallback do psychomapy).
         Aby wrócić do filtrowania po taksonomii "interwencja-kryzysowa", podstaw np_crisis_term_id(). --}}
    <div class="np-crisis__map-wrap">
      <div
        id="np-crisis-map"
        class="np-crisis__map-canvas"
        role="region"
        aria-label="Mapa ośrodków pomocy"
        data-np-crisis-map
        data-term-id=""
        data-api-url="{{ esc_url(rest_url('niepodzielni/v1/psychomapa')) }}"
      ></div>
      <p id="np-crisis-map-loading" class="np-crisis__map-loading">Ładowanie mapy…</p>
    </div>
    <ul id="np-crisis-map-list" class="np-crisis__map-list" role="list" aria-label="Lista ośrodków na mapie"></ul>
  </div>
</section>
