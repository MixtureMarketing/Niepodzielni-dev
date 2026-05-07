{{--
  Bookero rezerwacja + JS fallback.

  Expected variables:
    $id_uslugi  — string|int|null  ID usługi Bookero (jeśli puste i $zapisy_off=false, blok się nie renderuje)
    $zapisy_off — bool             tryb "Zapisy zakończone"
--}}
@php
    $id_uslugi  = $id_uslugi  ?? '';
    $zapisy_off = $zapisy_off ?? false;
    $calendar_html = (! $zapisy_off && $id_uslugi) ? do_shortcode('[bookero_kalendarz]') : '';
@endphp

@if($id_uslugi || $zapisy_off)
<div id="bookero">
    @if($zapisy_off)
        <div class="nsingle-zapisy-zakonczone">
            <p class="nsingle-zapisy-zakonczone__icon">🔒</p>
            <p class="nsingle-zapisy-zakonczone__title">Zapisy zakończone</p>
            <p class="nsingle-zapisy-zakonczone__desc">Rejestracja na to wydarzenie jest już zamknięta.</p>
        </div>
    @else
        {!! $calendar_html !!}
        {{-- JS fallback: gdy widget Bookero nie wyrenderuje treści po 8s --}}
        <div id="bookero-fallback" class="nsingle-zapisy-zakonczone u-hidden-init">
            <p class="nsingle-zapisy-zakonczone__icon">🔒</p>
            <p class="nsingle-zapisy-zakonczone__title">Zapisy zakończone</p>
            <p class="nsingle-zapisy-zakonczone__desc">Rejestracja na to wydarzenie jest już zamknięta.</p>
        </div>
        <script>
        (function() {
            setTimeout(function() {
                var plugin   = document.getElementById('bookero-plugin');
                var wrapper  = document.getElementById('bookero_wrapper');
                var fallback = document.getElementById('bookero-fallback');
                if (!fallback) return;
                var hasContent = plugin && plugin.innerText && plugin.innerText.trim().length > 10;
                if (!hasContent) {
                    if (wrapper) wrapper.style.display = 'none';
                    fallback.style.display = 'block';
                }
            }, 8000);
        })();
        </script>
    @endif
</div>
@endif
