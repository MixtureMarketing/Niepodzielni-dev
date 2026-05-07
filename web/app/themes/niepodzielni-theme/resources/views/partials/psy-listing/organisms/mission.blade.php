<section class="psy-section psy-mission-section">
    <div class="psy-container">
        <div class="psy-mission-grid">
            <div class="psy-mission-text">
                <h2>{{ $title1 ?? '' }}</h2>
                <p>{!! $desc1 ?? '' !!}</p>

                <h2 class="u-mt-50">{{ $title2 ?? '' }}</h2>
                <p>{!! $desc2 ?? '' !!}</p>
            </div>
            <div class="psy-mission-action">
                <div class="psy-contact-box">
                    <h3>Nie możesz zapłacić?<br>Napisz do nas</h3>
                    <p class="psy-contact-box__email">{{ $email ?? 'kontakt@niepodzielni.pl' }}</p>
                </div>
            </div>
        </div>
    </div>
</section>
