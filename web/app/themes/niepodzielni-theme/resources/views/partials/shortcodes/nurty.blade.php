<div class="product-currents-wrapper">
    <span class="product-currents__label">Nurty: </span>
    @foreach(array_slice($terms, 0, 2) as $term)
        @php $link = get_term_link($term); @endphp
        <a href="{{ !is_wp_error($link) ? esc_url($link) : '#' }}" class="product-current__tag">{{ $term->name }}</a>
    @endforeach
</div>
