@php
    $label = $label ?? 'Przycisk';
    $link  = $link ?? '#';
    $class = $class ?? 'psy-btn-green';
    $type  = $type ?? 'a';
@endphp
@if($type === 'a')
    <a href="{{ $link }}" class="psy-btn {{ $class }}">{{ $label }}</a>
@else
    <button type="button" class="psy-btn {{ $class }}">{{ $label }}</button>
@endif
