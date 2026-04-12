<div class="help-areas-list" data-help-areas-container>
    @foreach($terms as $i => $term)
        <span class="help-area-tag{{ $i >= $limit ? ' is-hidden' : '' }}">{{ $term->name }}</span>
    @endforeach
    @if($total > $limit)
        <button type="button" class="help-area-tag more-tag" data-show-more-tags>+{{ $total - $limit }}</button>
    @endif
</div>
