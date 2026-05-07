@php
  $cards = [
      [
          'value' => $stats['psychologists'] ?? 0,
          'label' => 'specjalistów w sieci',
          'desc'  => 'Sprawdzonych psychologów i psychoterapeutów dostępnych w naszym katalogu.',
          'show'  => ($stats['psychologists'] ?? 0) > 0,
      ],
      [
          'value' => $stats['articles'] ?? 0,
          'label' => 'artykułów psychoedukacyjnych',
          'desc'  => 'Materiałów wspierających zdrowie psychiczne — bezpłatnych dla wszystkich.',
          'show'  => ($stats['articles'] ?? 0) > 0,
      ],
      [
          'value' => $stats['support_groups_this_month'] ?? 0,
          'label' => 'grup wsparcia w tym miesiącu',
          'desc'  => 'Cykli i spotkań wspierających, do których możesz dołączyć.',
          'show'  => ($stats['support_groups_this_month'] ?? 0) > 0,
      ],
      [
          'value'  => $stats['avg_rating'] ?? null,
          'label'  => 'średnia ocena specjalistów',
          'desc'   => 'Na podstawie ' . (int) ($stats['reviews_count'] ?? 0) . ' opinii pacjentów.',
          'show'   => ($stats['avg_rating'] ?? null) !== null && ($stats['reviews_count'] ?? 0) > 0,
          'is_decimal' => true,
      ],
  ];
  $visible = array_values(array_filter($cards, fn($c) => ! empty($c['show'])));
@endphp

@if(count($visible) > 0)
  <section class="np-wall" aria-labelledby="np-wall-title" data-np-wall>
    <div class="psy-container">
      <h2 id="np-wall-title" class="np-wall__title">Niepodzielni w liczbach</h2>
      <p class="np-wall__intro">
        Wsparcie, które dajemy, to konkretne osoby, materiały i spotkania. Aktualizacja co godzinę.
      </p>

      <ul class="np-wall__grid" role="list">
        @foreach($visible as $card)
          <li class="np-wall__card">
            <span
              class="np-wall__value"
              data-countup="{{ $card['value'] }}"
              data-countup-decimal="{{ ! empty($card['is_decimal']) ? 1 : 0 }}"
              aria-live="polite"
            >{{ ! empty($card['is_decimal']) ? number_format((float) $card['value'], 1, ',', ' ') : '0' }}</span>
            <span class="np-wall__label">{{ $card['label'] }}</span>
            <span class="np-wall__desc">{{ $card['desc'] }}</span>
          </li>
        @endforeach
      </ul>
    </div>
  </section>
@endif
