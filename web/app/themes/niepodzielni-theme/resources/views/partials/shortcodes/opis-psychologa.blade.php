@if($is_short)
<div class="sdesc-wrapper">{!! $content !!}</div>
@else
<div class="sdesc-wrapper" data-sdesc-container>
    <div class="sdesc-content-wrapper">
        <div class="sdesc-short is-visible">
            {!! $short_description !!}
            <button type="button" class="sdesc-toggle-btn sdesc-show-more">Pokaż więcej</button>
        </div>
        <div class="sdesc-full">
            {!! $content !!}
            <button type="button" class="sdesc-toggle-btn sdesc-collapse">Zwiń</button>
        </div>
    </div>
</div>
@endif
