<section class="psy-section psy-steps-section">
    <div class="psy-container">
        <h2 style="font-size:42px; font-weight:800; margin-bottom:15px; text-align:center;">Zrób pierwszy krok</h2>
        <p style="text-align:center; font-size:20px; margin-bottom:60px; color:#555;">Zarezerwuj wizytę i pozwól sobie na pomoc, na którą zasługujesz.</p>
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
