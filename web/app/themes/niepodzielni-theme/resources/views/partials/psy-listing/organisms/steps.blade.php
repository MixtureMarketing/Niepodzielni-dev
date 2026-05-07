<section class="psy-section psy-steps-section">
    <div class="psy-container">
        <h2 class="psy-steps-section__title">Zrób pierwszy krok</h2>
        <p class="psy-steps-section__subtitle">Zarezerwuj wizytę i pozwól sobie na pomoc, na którą zasługujesz.</p>
        <div class="psy-steps-grid">
            @foreach($steps ?? [] as $step)
                <div class="psy-step-col">
                    <div class="psy-step-header">
                        {!! $step['icon'] !!}
                        <h3>{{ $step['title'] }}</h3>
                    </div>
                    <p>{!! $step['desc'] !!}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
