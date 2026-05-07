{{--
  Template Name: Pomoc w kryzysie
  Description: Crisis Help Hub — numery alarmowe, checklist, mapa interwencyjna, przycisk „Ukryj stronę".
--}}

@extends('layouts.app')

@section('content')
  <article class="np-crisis" data-np-crisis-page>
    <header class="np-crisis__hero">
      <div class="np-crisis__container">
        <p class="np-crisis__eyebrow">Pomoc w kryzysie</p>
        <h1 class="np-crisis__h1">Potrzebujesz pomocy teraz?</h1>
        <p class="np-crisis__lead">
          Jesteś w bezpiecznym miejscu. Poniżej znajdziesz numery, pod które możesz zadzwonić — bezpłatnie, anonimowo, 24/7.
          Jeśli czujesz, że Twoje życie jest zagrożone, zadzwoń pod <strong>112</strong>.
        </p>
      </div>
    </header>

    @include('partials.crisis.numbers')
    @include('partials.crisis.checklist')
    @include('partials.crisis.map')

    <section class="np-crisis__disclaimer" aria-labelledby="np-crisis-disclaimer-title">
      <div class="np-crisis__container">
        <h2 id="np-crisis-disclaimer-title" class="np-crisis__h2-sm">Ważne</h2>
        <p>
          Fundacja Niepodzielni nie świadczy interwencji kryzysowej i nie jest jednostką medyczną.
          W sytuacji bezpośredniego zagrożenia życia lub zdrowia skorzystaj z numerów powyżej lub udaj się
          na najbliższy oddział ratunkowy.
        </p>
      </div>
    </section>

    @include('partials.crisis.hide-button')
  </article>
@endsection
