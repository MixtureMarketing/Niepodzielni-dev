@php
    $slug    = $slug ?? 'polski';
    $flagMap = ['polski' => 'flag-pl', 'angielski' => 'flag-en', 'ukrainski' => 'flag-uk'];
    $class   = $flagMap[$slug] ?? '';
@endphp
@if($class)
    <span class="flag-icon {{ $class }}"></span>
@endif
