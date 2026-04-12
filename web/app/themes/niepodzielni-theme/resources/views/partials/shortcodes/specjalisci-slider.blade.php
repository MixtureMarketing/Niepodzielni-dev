@if(!empty($slides))
<div class="specialists-slider-container">
    <div class="swiper specialists-slider">
        <div class="swiper-wrapper">
            @foreach($slides as $slide)
            <div class="swiper-slide">
                <div class="specialist-card">
                    <div class="specialist-image-container">
                        {!! $slide['thumb'] !!}
                    </div>
                    <div class="specialist-info">
                        <h3 class="specialist-name">{{ $slide['title'] }}</h3>
                        {!! $slide['rodzaj_wizyty_html'] !!}
                        <div class="psy-availability-box" style="margin-bottom: 10px; margin-top: 10px; padding: 5px 10px; align-self: flex-start; border-radius: 10px; font-size: 0.85rem;">
                            <span class="availability-label">Termin:</span>
                            <span class="availability-date">{{ $slide['termin'] }}</span>
                        </div>
                        <div class="specialist-info-spacer"></div>
                        {!! $slide['specjalizacje_html'] !!}
                        <a href="{{ $slide['link'] }}" class="specialist-link stretched-link">Umów się {!! get_niepodzielni_svg_icon('arrow_link') !!}</a>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    <div class="slider-controls">
        <div class="swiper-pagination"></div>
        <div class="slider-nav-control">
            <div class="swiper-button-prev"></div>
            <div class="swiper-button-next"></div>
        </div>
    </div>
</div>
@endif
