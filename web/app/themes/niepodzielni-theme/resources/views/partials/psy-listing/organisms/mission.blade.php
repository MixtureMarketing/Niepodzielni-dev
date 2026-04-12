<section class="psy-section psy-mission-section">
    <div class="psy-container">
        <div class="psy-mission-grid">
            <div class="psy-mission-text">
                <h2>{{ $title1 ?? '' }}</h2>
                <p>{!! $desc1 ?? '' !!}</p>

                <h2 style="margin-top:50px;">{{ $title2 ?? '' }}</h2>
                <p>{!! $desc2 ?? '' !!}</p>
            </div>
            <div class="psy-mission-action">
                <div class="psy-contact-box">
                    <h3>Nie możesz zapłacić?<br>Napisz do nas</h3>
                    <p style="font-size:22px; font-weight:800; margin-top:10px;">{{ $email ?? 'kontakt@niepodzielni.pl' }}</p>
                </div>
            </div>
        </div>
    </div>
</section>
