{{--
  Related articles — wyświetla 3 powiązane wpisy oparte o kategorie i tagi.
  Włączane po treści w single-aktualnosci.blade.php oraz partials/content-single-post.blade.php.
--}}
@php
    $related_current_id   = get_the_ID();
    $related_post_type    = get_post_type();
    $related_categories   = wp_get_post_categories($related_current_id);
    $related_tags         = wp_get_post_tags($related_current_id, ['fields' => 'ids']);

    $related_args = [
        'post_type'           => $related_post_type,
        'posts_per_page'      => 3,
        'post__not_in'        => [$related_current_id],
        'ignore_sticky_posts' => 1,
        'orderby'             => 'rand',
        'no_found_rows'       => true,
    ];

    // Tax queries — tylko gdy są kategorie/tagi (CPT aktualnosci ma własne taksonomie, więc fallback na typ).
    $tax_query = ['relation' => 'OR'];
    if (!empty($related_categories)) {
        $tax_query[] = [
            'taxonomy' => 'category',
            'field'    => 'term_id',
            'terms'    => $related_categories,
        ];
    }
    if (!empty($related_tags)) {
        $tax_query[] = [
            'taxonomy' => 'post_tag',
            'field'    => 'term_id',
            'terms'    => $related_tags,
        ];
    }
    if (count($tax_query) > 1) {
        $related_args['tax_query'] = $tax_query;
    }

    $related_query = new WP_Query($related_args);
@endphp

@if($related_query->have_posts())
<aside class="related-articles" aria-labelledby="related-articles-heading">
    <div class="related-articles__inner">
        <h2 id="related-articles-heading" class="related-articles__heading">Czytaj również</h2>
        <ul class="related-articles__list">
            @while($related_query->have_posts()) @php($related_query->the_post())
                <li class="related-articles__item">
                    <a href="{{ get_permalink() }}" class="related-articles__link">
                        @if(has_post_thumbnail())
                            {!! get_the_post_thumbnail(get_the_ID(), 'medium', ['loading' => 'lazy', 'class' => 'related-articles__thumb', 'alt' => esc_attr(get_the_title())]) !!}
                        @else
                            <span class="related-articles__thumb related-articles__thumb--placeholder" aria-hidden="true"></span>
                        @endif
                        <h3 class="related-articles__title">{{ get_the_title() }}</h3>
                    </a>
                </li>
            @endwhile
        </ul>
    </div>
</aside>
@php(wp_reset_postdata())
@endif
