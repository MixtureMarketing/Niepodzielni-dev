@php
    $tax          = $tax ?? '';
    $label        = $label ?? 'Wybierz';
    $hierarchical = $hierarchical ?? false;
    $terms        = get_terms(['taxonomy' => $tax, 'hide_empty' => true]);
    $terms        = is_wp_error($terms) ? [] : $terms;
@endphp
<div class="psy-multiselect-dropdown" data-tax="{{ $tax }}" data-label="{{ $label }}" data-hierarchical="{{ $hierarchical ? '1' : '0' }}">
    <div class="multiselect-label">{{ $label }}</div>
    <div class="multiselect-content">
        <div class="multiselect-search-wrapper">
            <input type="text" class="multiselect-inner-search" placeholder="Szukaj...">
        </div>
        <div class="multiselect-options-list">
            @if($hierarchical)
                @php
                    $parents  = [];
                    $children = [];
                    foreach ($terms as $term) {
                        if ($term->parent == 0) $parents[$term->term_id] = $term;
                        else $children[$term->parent][] = $term;
                    }
                @endphp
                @foreach($parents as $pid => $parent)
                    <div class="multiselect-group-header">{{ $parent->name }}</div>
                    @if(isset($children[$pid]))
                        @foreach($children[$pid] as $child)
                            @include('partials.psy-listing.atoms.option', ['term' => $child, 'tax' => $tax])
                        @endforeach
                    @endif
                @endforeach
            @else
                @foreach($terms as $term)
                    @include('partials.psy-listing.atoms.option', ['term' => $term, 'tax' => $tax])
                @endforeach
            @endif
        </div>
    </div>
</div>
