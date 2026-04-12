@php
    $name    = $name ?? '';
    $options = $options ?? [];
    $i       = 0;
@endphp
<div class="psy-filter-toggle">
    @foreach($options as $val => $optLabel)
        <label class="psy-toggle-label">
            <input type="radio" name="{{ $name }}" value="{{ $val }}" {{ $i === 0 ? 'checked' : '' }}>
            <span>{{ $optLabel }}</span>
        </label>
        @php($i++)
    @endforeach
</div>
