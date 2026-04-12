{{--
  Listing tabs bar — horizontal blue bar with radio-based pill tabs.
  Props:
    $tabs    (array)  — [['value' => 'all', 'label' => 'Wszystkie'], ...]
    $active  (string) — value of the initially active tab (default: 'all')
    $name    (string) — radio input name attribute (default: 'listing-tab')
--}}
@php $active = $active ?? 'all'; $name = $name ?? 'listing-tab'; @endphp

<div class="nlisting-tabs" role="tablist">
    <div class="nlisting-container">
        <div class="nlisting-tabs__inner">
            @foreach( $tabs as $tab )
                <label class="nlisting-tabs__tab {{ ($active === $tab['value']) ? 'is-active' : '' }}">
                    <input
                        type="radio"
                        name="{{ $name }}"
                        value="{{ $tab['value'] }}"
                        {{ ($active === $tab['value']) ? 'checked' : '' }}
                        class="nlisting-tabs__radio"
                        role="tab"
                        aria-selected="{{ ($active === $tab['value']) ? 'true' : 'false' }}"
                    >
                    {{ $tab['label'] }}
                </label>
            @endforeach
        </div>
    </div>
</div>
