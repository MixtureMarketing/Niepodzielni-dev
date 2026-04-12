{{--
  Generic listing hero — 2 columns: left text + CTAs, right illustration.
  Props:
    $title      (string)  — headline
    $desc       (string)  — body text, HTML allowed
    $image_url  (string)  — right-side SVG/image
    $image_alt  (string)  — alt text, optional
    $buttons    (array)   — [['label' => '', 'link' => '', 'class' => 'btn-primary|btn-outline'], ...]
    $badge      (string)  — optional pre-title label (e.g. "Warsztaty i grupy wsparcia")
--}}
<section class="nlisting-hero">
    <div class="nlisting-container">
        <div class="nlisting-hero__row">

            <div class="nlisting-hero__content">
                @if( !empty($badge) )
                    <span class="nlisting-hero__badge">{{ $badge }}</span>
                @endif

                <h1 class="nlisting-hero__title">{!! $title ?? '' !!}</h1>

                @if( !empty($desc) )
                    <div class="nlisting-hero__desc">{!! $desc !!}</div>
                @endif

                @if( !empty($buttons) )
                    <div class="nlisting-hero__btns">
                        @foreach( $buttons as $btn )
                            @include('partials.psy-listing.atoms.button', [
                                'label' => $btn['label'],
                                'link'  => $btn['link'],
                                'class' => $btn['class'] ?? 'psy-btn-green',
                            ])
                        @endforeach
                    </div>
                @endif
            </div>

            @if( !empty($image_url) )
                <div class="nlisting-hero__image">
                    <img src="{{ $image_url }}" alt="{{ $image_alt ?? $title ?? '' }}" loading="eager">
                </div>
            @endif

        </div>
    </div>
</section>
