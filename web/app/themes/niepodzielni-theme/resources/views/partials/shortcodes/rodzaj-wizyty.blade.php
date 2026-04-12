<div class="specialist-visit-types">
    @foreach($parts as $part)
        <span class="visit-type-tag">
            {!! get_niepodzielni_svg_icon(stripos($part, 'Online') !== false ? 'online' : 'stacjonarnie') !!}{{ $part }}
        </span>
    @endforeach
</div>
