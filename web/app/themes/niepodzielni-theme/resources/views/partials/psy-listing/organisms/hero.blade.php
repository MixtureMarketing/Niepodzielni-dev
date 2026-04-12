<section class="psy-section psy-hero-section">
    <div class="psy-container">
        <div class="psy-hero-row">
            <div class="psy-hero-content">
                <h1 class="psy-title-main">{{ $title ?? 'Konsultacje psychologiczne' }}</h1>
                <div class="psy-hero-description">
                    <p>{!! $desc ?? '' !!}</p>
                </div>
                <div class="psy-hero-btns">
                    @include('partials.psy-listing.atoms.button', [
                        'label' => 'ZNAJDŹ SPECJALISTĘ',
                        'link'  => '#listing',
                        'class' => 'psy-btn-green',
                    ])
                    @if(($rodzaj ?? 'nisko') === 'nisko')
                        @include('partials.psy-listing.atoms.button', [
                            'label' => 'KONSULTACJE PEŁNOPŁATNE',
                            'link'  => home_url('/konsultacje-psychologiczne-pelnoplatne/'),
                            'class' => 'psy-btn-outline',
                        ])
                    @else
                        @include('partials.psy-listing.atoms.button', [
                            'label' => 'KONSULTACJE NISKOPŁATNE',
                            'link'  => home_url('/konsultacje-niskoplatne/'),
                            'class' => 'psy-btn-outline',
                        ])
                    @endif
                </div>
            </div>
            <div class="psy-hero-image">
                <img src="https://niepodzielni.com/wp-content/uploads/2025/08/dd-1.svg" alt="Ilustracja">
            </div>
        </div>
    </div>
</section>
