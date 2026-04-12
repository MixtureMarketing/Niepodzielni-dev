{{-- Template Name: Konsultacje Pełnopłatne --}}

@extends('layouts.app')

@section('content')

<script>
    window.allPsycholodzy = {!! json_encode($all_psy_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
</script>

<div class="psy-page-wrapper">

    @include('partials.psy-listing.organisms.hero', [
        'rodzaj' => 'pelno',
        'title'  => 'Konsultacje pełnopłatne',
        'desc'   => 'Profesjonalne wsparcie psychoterapeutyczne dopasowane do Twoich potrzeb. Spotkania prowadzone przez doświadczonych specjalistów w standardowej cenie rynkowej.',
    ])

    @include('partials.psy-listing.organisms.steps', [
        'steps' => [
            [
                'icon'  => '<svg width="31" height="31"><rect width="31" height="31" rx="15.5" fill="#1500BB"></rect><path d="M17.5 6.8V24H14.6V10.2L10.4 11.6V9.3L17.1 6.8H17.5Z" fill="#fff"></path></svg>',
                'title' => 'Wybierz terapeutę',
                'desc'  => 'Zapoznaj się z profilami naszych specjalistów i wybierz osobę, która najlepiej odpowiada Twoim potrzebom.',
            ],
            [
                'icon'  => '<svg width="31" height="31"><rect width="31" height="31" rx="15.5" fill="#1500BB"></rect><path d="M21.2 21.7V24H9.7V22L15.3 16C15.9 15.3 16.4 14.7 16.7 14.2C17.1 13.7 17.3 13.2 17.5 12.8V11.6C17.7 11.1 17.6 10.6 17.4 10.2C17.2 9.8 16.9 9.5 16.5 9.3C16.1 9 15.7 8.9 15.1 8.9C14.5 8.9 14 9 13.5 9.3C13.1 9.6 12.8 10 12.6 10.4V12.1H9.4V11.1C9.4 10.2 9.6 9.4 10.1 8.5C10.5 7.9 11.2 7.4 12.1 6.9C12.9 6.7 14 6.7 15.2 6.7C16.3 6.7 17.3 6.8 18.1 7.2C18.9 7.6 19.5 8.2 19.9 8.9C20.3 9.6 20.5 10.4 20.5 11.4V12.9C20.3 13.5 20.1 14 19.5 14.5C19.2 15 18.8 15.5 18.4 16.1L13.3 21.7H21.2Z" fill="#fff"></path></svg>',
                'title' => 'Zarezerwuj termin',
                'desc'  => 'Wybierz dogodny termin w kalendarzu terapeuty i opłać wizytę online (karta, BLIK, przelew).',
            ],
            [
                'icon'  => '<svg width="31" height="31"><rect width="31" height="31" rx="15.5" fill="#1500BB"></rect><path d="M13.1 14.1H14.7C15.4 14.1 15.9 14 16.4 13.8C16.8 13.6 17.1 13.3 17.3 12.9V11.5C17.7 11 17.6 10.5 17.4 10.1C17.2 9.7 16.9 9.4 16.5 9.2C16.1 9 15.6 8.9 15 8.9C14.5 8.9 14.1 9 13.7 9.2C13.3 9.4 13 9.7 12.7 10V11.3H9.5V10.4C9.5 9.6 9.8 8.9 10.3 8.2C10.7 7.7 11.4 7.3 12.2 6.9C13 6.7 14 6.7 15 6.7C16.1 6.7 17.1 6.8 17.9 7.2C18.7 7.6 19.3 8.1 19.8 8.8C20.3 9.5 20.5 10.4 20.5 11.5V13C20.2 13.5 19.9 13.9 19.5 14.3C19.1 14.7 18.7 15.1 18.1 15.3C17.5 15.6 16.8 15.7 16.1 15.7H13.1V14.1ZM13.1 16.3V14.8H15.2C16.2 14.8 17 14.9 17.7 15.1C18.4 15.4 19 15.7 19.5 16.1C19.9 16.5 20.2 17 20.4 17.5C20.6 18 20.8 18.6 20.8 19.2V20C20.8 20.7 20.6 21.3 20.3 21.9C20 22.5 19.6 22.9 19.1 23.3C18.6 23.6 18 23.9 17.3 24.1C16.6 24.3 15.8 24.4 15 24.4C14.3 24.4 13.6 24.3 12.9 24.1C12.2 23.9 11.6 23.6 11.1 23.3C10.6 22.9 10.1 22.5 9.8 21.9C9.5 21.3 9.4 20.7 9.4 20H12.2V19.9C12.2 20.3 12.3 20.7 12.5 21.1C12.8 21.4 13.1 21.6 13.5 21.8C14 22 14.5 22.1 15 22.1C15.6 22.1 16.2 22 16.6 21.8C17 21.6 17.3 21.4 17.6 21.1C17.8 20.7 17.9 20.3 17.9 19.9C17.9 19.4 17.8 19 17.6 18.6C17.3 18.3 17 18 16.6 17.8C16.2 17.6 15.6 17.5 15 17.5H13.1V16.3Z" fill="#fff"></path></svg>',
                'title' => 'Rozpocznij terapię',
                'desc'  => 'Spotkaj się ze specjalistą stacjonarnie w Poznaniu lub online z dowolnego miejsca na świecie.',
            ],
        ],
    ])

    @include('partials.psy-listing.organisms.mission', [
        'title1' => 'Profesjonalizm i zaufanie',
        'desc1'  => 'Współpracujemy wyłącznie z wykwalifikowanymi psychoterapeutami, zapewniając najwyższy standard opieki psychologicznej i pełną poufność Twoich danych.',
        'title2' => 'Wspierasz naszą misję',
        'desc2'  => 'Korzystając z konsultacji pełnopłatnych, pomagasz nam utrzymywać i rozwijać programy niskopłatne dla osób w trudnej sytuacji życiowej.',
        'email'  => 'kontakt@niepodzielni.pl',
    ])

    @include('partials.psy-listing.organisms.bk-shared-calendar', ['rodzaj' => 'pelno'])

    @include('partials.psy-listing.organisms.filter-bar', ['rodzaj' => 'pelno'])

</div>
@endsection
