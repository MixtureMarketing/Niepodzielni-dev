@php
  $crisisTermId = function_exists('np_crisis_term_id') ? np_crisis_term_id() : null;
@endphp

<section class="np-crisis__map" aria-labelledby="np-crisis-map-title">
  <div class="np-crisis__container">
    <h2 id="np-crisis-map-title" class="np-crisis__h2">Ośrodki interwencji kryzysowej</h2>
    <p class="np-crisis__map-intro">
      Wybrane ośrodki, telefony zaufania i oddziały psychiatryczne w Polsce.
      Lista jest niepełna — w razie wątpliwości zadzwoń pod 112.
    </p>

    @if($crisisTermId === null)
      <p class="np-crisis__map-empty">
        Lista ośrodków będzie dostępna wkrótce. W tej chwili zadzwoń pod jeden z numerów powyżej lub udaj się
        na najbliższy oddział ratunkowy.
      </p>
    @else
      <div class="np-crisis__map-wrap">
        <div
          id="np-crisis-map"
          class="np-crisis__map-canvas"
          role="region"
          aria-label="Mapa ośrodków interwencji kryzysowej"
          data-np-crisis-map
          data-term-id="{{ $crisisTermId }}"
          data-api-url="{{ esc_url(rest_url('niepodzielni/v1/psychomapa')) }}"
        ></div>
        <p id="np-crisis-map-loading" class="np-crisis__map-loading">Ładowanie mapy…</p>
      </div>
      <ul id="np-crisis-map-list" class="np-crisis__map-list" role="list" aria-label="Lista ośrodków na mapie"></ul>
    @endif
  </div>
</section>
