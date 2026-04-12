<div class="jezyki_contener">
    <div class="jezyki_lewa_czesc">
        <span class="takie_jezyki tekst_duzy">Języki: &nbsp;</span>
    </div>
    <div class="jezyki_prawa_czesc">
        @foreach($terms as $term)
            <span class="jeden_jezyk" style="display:inline-flex; align-items:center; margin-right:15px;">
                @if(isset($flagMap[$term->slug]))
                    <span class="fi fi-{{ $flagMap[$term->slug] }}" style="margin-right:8px; border-radius:2px;"></span>
                @endif
                <div class="opis_jezyka_przy_obrazie">{{ $term->name }}</div>
            </span>
        @endforeach
    </div>
</div>
