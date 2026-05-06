<?php

declare(strict_types=1);

namespace Niepodzielni\Forms\Helpers;

/**
 * Globalna lista kierunkowych telefonicznych.
 *
 * Format: prefix => ['iso' => ISO 3166-1 alpha-2, 'label' => nazwa, 'min' => min cyfr, 'max' => max cyfr]
 * ISO alpha-2 służy do wyświetlania flag CSS (klasa "fi fi-{iso}").
 * min/max to liczba cyfr numeru abonenta (bez kierunkowego kraju).
 */
class PhonePrefixes
{
    /**
     * @return array<string, array{iso: string, label: string, min: int, max: int, placeholder: string}>
     */
    public static function getAll(): array
    {
        return [
            // Europa
            '+48'  => ['iso' => 'pl', 'label' => 'Polska',             'min' => 9,  'max' => 9,  'placeholder' => '111 111 111'],
            '+49'  => ['iso' => 'de', 'label' => 'Niemcy',             'min' => 3,  'max' => 12, 'placeholder' => '30 12345678'],
            '+44'  => ['iso' => 'gb', 'label' => 'Wielka Brytania',    'min' => 7,  'max' => 10, 'placeholder' => '7911 123456'],
            '+380' => ['iso' => 'ua', 'label' => 'Ukraina',            'min' => 9,  'max' => 9,  'placeholder' => '50 123 4567'],
            '+33'  => ['iso' => 'fr', 'label' => 'Francja',            'min' => 9,  'max' => 9,  'placeholder' => '6 12 34 56 78'],
            '+39'  => ['iso' => 'it', 'label' => 'Włochy',             'min' => 6,  'max' => 11, 'placeholder' => '312 345 6789'],
            '+34'  => ['iso' => 'es', 'label' => 'Hiszpania',          'min' => 9,  'max' => 9,  'placeholder' => '612 345 678'],
            '+31'  => ['iso' => 'nl', 'label' => 'Holandia',           'min' => 9,  'max' => 9,  'placeholder' => '6 12345678'],
            '+32'  => ['iso' => 'be', 'label' => 'Belgia',             'min' => 8,  'max' => 9,  'placeholder' => '470 12 34 56'],
            '+41'  => ['iso' => 'ch', 'label' => 'Szwajcaria',         'min' => 9,  'max' => 9,  'placeholder' => '78 123 45 67'],
            '+43'  => ['iso' => 'at', 'label' => 'Austria',            'min' => 4,  'max' => 13, 'placeholder' => '664 123456'],
            '+420' => ['iso' => 'cz', 'label' => 'Czechy',             'min' => 9,  'max' => 9,  'placeholder' => '601 123 456'],
            '+421' => ['iso' => 'sk', 'label' => 'Słowacja',           'min' => 9,  'max' => 9,  'placeholder' => '912 123 456'],
            '+36'  => ['iso' => 'hu', 'label' => 'Węgry',              'min' => 8,  'max' => 9,  'placeholder' => '20 123 4567'],
            '+40'  => ['iso' => 'ro', 'label' => 'Rumunia',            'min' => 9,  'max' => 9,  'placeholder' => '712 345 678'],
            '+359' => ['iso' => 'bg', 'label' => 'Bułgaria',           'min' => 8,  'max' => 9,  'placeholder' => '87 123 4567'],
            '+30'  => ['iso' => 'gr', 'label' => 'Grecja',             'min' => 10, 'max' => 10, 'placeholder' => '691 234 5678'],
            '+351' => ['iso' => 'pt', 'label' => 'Portugalia',         'min' => 9,  'max' => 9,  'placeholder' => '912 345 678'],
            '+46'  => ['iso' => 'se', 'label' => 'Szwecja',            'min' => 7,  'max' => 13, 'placeholder' => '70 123 45 67'],
            '+47'  => ['iso' => 'no', 'label' => 'Norwegia',           'min' => 8,  'max' => 8,  'placeholder' => '412 34 567'],
            '+45'  => ['iso' => 'dk', 'label' => 'Dania',              'min' => 8,  'max' => 8,  'placeholder' => '32 12 34 56'],
            '+358' => ['iso' => 'fi', 'label' => 'Finlandia',          'min' => 5,  'max' => 12, 'placeholder' => '40 123 4567'],
            '+372' => ['iso' => 'ee', 'label' => 'Estonia',            'min' => 7,  'max' => 8,  'placeholder' => '5123 4567'],
            '+371' => ['iso' => 'lv', 'label' => 'Łotwa',              'min' => 8,  'max' => 8,  'placeholder' => '21 234 567'],
            '+370' => ['iso' => 'lt', 'label' => 'Litwa',              'min' => 8,  'max' => 8,  'placeholder' => '61 234 567'],
            '+353' => ['iso' => 'ie', 'label' => 'Irlandia',           'min' => 7,  'max' => 9,  'placeholder' => '85 123 4567'],
            '+354' => ['iso' => 'is', 'label' => 'Islandia',           'min' => 7,  'max' => 7,  'placeholder' => '611 1234'],
            '+352' => ['iso' => 'lu', 'label' => 'Luksemburg',         'min' => 4,  'max' => 11, 'placeholder' => '628 123 456'],
            '+356' => ['iso' => 'mt', 'label' => 'Malta',              'min' => 8,  'max' => 8,  'placeholder' => '9912 3456'],
            '+357' => ['iso' => 'cy', 'label' => 'Cypr',               'min' => 8,  'max' => 8,  'placeholder' => '96 123456'],
            '+381' => ['iso' => 'rs', 'label' => 'Serbia',             'min' => 8,  'max' => 9,  'placeholder' => '60 1234567'],
            '+385' => ['iso' => 'hr', 'label' => 'Chorwacja',          'min' => 8,  'max' => 9,  'placeholder' => '91 234 5678'],
            '+386' => ['iso' => 'si', 'label' => 'Słowenia',           'min' => 8,  'max' => 8,  'placeholder' => '31 234 567'],
            '+387' => ['iso' => 'ba', 'label' => 'Bośnia i Herceg.',   'min' => 8,  'max' => 8,  'placeholder' => '61 123 456'],
            '+382' => ['iso' => 'me', 'label' => 'Czarnogóra',         'min' => 8,  'max' => 8,  'placeholder' => '67 123 456'],
            '+389' => ['iso' => 'mk', 'label' => 'Macedonia Płn.',     'min' => 8,  'max' => 8,  'placeholder' => '72 123 456'],
            '+355' => ['iso' => 'al', 'label' => 'Albania',            'min' => 9,  'max' => 9,  'placeholder' => '66 123 4567'],
            '+373' => ['iso' => 'md', 'label' => 'Mołdawia',           'min' => 8,  'max' => 8,  'placeholder' => '62 123 456'],
            '+374' => ['iso' => 'am', 'label' => 'Armenia',            'min' => 8,  'max' => 8,  'placeholder' => '77 123456'],
            '+375' => ['iso' => 'by', 'label' => 'Białoruś',           'min' => 9,  'max' => 9,  'placeholder' => '29 123 4567'],
            // Ameryka Północna
            '+1'   => ['iso' => 'us', 'label' => 'USA / Kanada',       'min' => 10, 'max' => 10, 'placeholder' => '202 555 0100'],
            '+52'  => ['iso' => 'mx', 'label' => 'Meksyk',             'min' => 10, 'max' => 10, 'placeholder' => '55 1234 5678'],
            // Ameryka Południowa
            '+55'  => ['iso' => 'br', 'label' => 'Brazylia',           'min' => 10, 'max' => 11, 'placeholder' => '11 91234 5678'],
            '+54'  => ['iso' => 'ar', 'label' => 'Argentyna',          'min' => 10, 'max' => 10, 'placeholder' => '11 1234 5678'],
            '+56'  => ['iso' => 'cl', 'label' => 'Chile',              'min' => 9,  'max' => 9,  'placeholder' => '9 1234 5678'],
            '+57'  => ['iso' => 'co', 'label' => 'Kolumbia',           'min' => 10, 'max' => 10, 'placeholder' => '300 123 4567'],
            '+51'  => ['iso' => 'pe', 'label' => 'Peru',               'min' => 9,  'max' => 9,  'placeholder' => '912 345 678'],
            // Oceania
            '+61'  => ['iso' => 'au', 'label' => 'Australia',          'min' => 9,  'max' => 9,  'placeholder' => '412 345 678'],
            '+64'  => ['iso' => 'nz', 'label' => 'Nowa Zelandia',      'min' => 8,  'max' => 9,  'placeholder' => '21 123 456'],
            // Azja Wschodnia
            '+81'  => ['iso' => 'jp', 'label' => 'Japonia',            'min' => 10, 'max' => 11, 'placeholder' => '90 1234 5678'],
            '+86'  => ['iso' => 'cn', 'label' => 'Chiny',              'min' => 11, 'max' => 11, 'placeholder' => '131 1234 5678'],
            '+82'  => ['iso' => 'kr', 'label' => 'Korea Płd.',         'min' => 9,  'max' => 10, 'placeholder' => '10 1234 5678'],
            // Azja Południowa
            '+91'  => ['iso' => 'in', 'label' => 'Indie',              'min' => 10, 'max' => 10, 'placeholder' => '98765 43210'],
            '+92'  => ['iso' => 'pk', 'label' => 'Pakistan',           'min' => 10, 'max' => 10, 'placeholder' => '301 2345678'],
            // Azja Południowo-Wschodnia
            '+62'  => ['iso' => 'id', 'label' => 'Indonezja',          'min' => 9,  'max' => 12, 'placeholder' => '812 345 6789'],
            '+66'  => ['iso' => 'th', 'label' => 'Tajlandia',          'min' => 9,  'max' => 9,  'placeholder' => '81 234 5678'],
            '+84'  => ['iso' => 'vn', 'label' => 'Wietnam',            'min' => 9,  'max' => 10, 'placeholder' => '91 234 5678'],
            '+63'  => ['iso' => 'ph', 'label' => 'Filipiny',           'min' => 10, 'max' => 10, 'placeholder' => '917 123 4567'],
            '+60'  => ['iso' => 'my', 'label' => 'Malezja',            'min' => 9,  'max' => 10, 'placeholder' => '12 345 6789'],
            '+65'  => ['iso' => 'sg', 'label' => 'Singapur',           'min' => 8,  'max' => 8,  'placeholder' => '8123 4567'],
            // Bliski Wschód
            '+972' => ['iso' => 'il', 'label' => 'Izrael',             'min' => 9,  'max' => 9,  'placeholder' => '50 123 4567'],
            '+90'  => ['iso' => 'tr', 'label' => 'Turcja',             'min' => 10, 'max' => 10, 'placeholder' => '501 234 5678'],
            '+966' => ['iso' => 'sa', 'label' => 'Arabia Saudyjska',   'min' => 9,  'max' => 9,  'placeholder' => '50 123 4567'],
            '+971' => ['iso' => 'ae', 'label' => 'Zjednoczone Emiraty','min' => 9,  'max' => 9,  'placeholder' => '50 123 4567'],
            // Afryka
            '+20'  => ['iso' => 'eg', 'label' => 'Egipt',              'min' => 10, 'max' => 10, 'placeholder' => '100 123 4567'],
            '+27'  => ['iso' => 'za', 'label' => 'RPA',                'min' => 9,  'max' => 9,  'placeholder' => '71 234 5678'],
            '+212' => ['iso' => 'ma', 'label' => 'Maroko',             'min' => 9,  'max' => 9,  'placeholder' => '612 345 678'],
            '+213' => ['iso' => 'dz', 'label' => 'Algieria',           'min' => 9,  'max' => 9,  'placeholder' => '551 23 45 67'],
            '+216' => ['iso' => 'tn', 'label' => 'Tunezja',            'min' => 8,  'max' => 8,  'placeholder' => '20 123 456'],
        ];
    }
}
