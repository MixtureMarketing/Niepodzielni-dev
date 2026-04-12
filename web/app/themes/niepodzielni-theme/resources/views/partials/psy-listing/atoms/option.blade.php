@php
    $term    = $term ?? null;
    $tax     = $tax ?? '';
    $flagMap = [
        'polski' => 'pl', 'angielski' => 'gb', 'ukrainski' => 'ua',
        'niemiecki' => 'de', 'rosyjski' => 'ru', 'francuski' => 'fr',
        'hiszpanski' => 'es', 'wloski' => 'it', 'czeski' => 'cz'
    ];
@endphp
@if($term)
    <label class="multiselect-option {{ $term->parent != 0 ? 'is-child' : '' }}">
        <input type="checkbox" name="{{ $tax }}" value="{{ $term->slug }}">
        <span class="opt-label-text">
            @if($tax === 'jezyk' && isset($flagMap[$term->slug]))
                <span class="fi fi-{{ $flagMap[$term->slug] }}" style="margin-right: 10px;"></span>
            @endif
            <span class="opt-name">{{ $term->name }}</span>
        </span>
    </label>
@endif
