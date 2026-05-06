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
     * @return array<string, array{iso: string, label: string, min: int, max: int}>
     */
    public static function getAll(): array
    {
        return [
            // Europa
            '+48'  => ['iso' => 'pl', 'label' => 'Polska',             'min' => 9,  'max' => 9],
            '+49'  => ['iso' => 'de', 'label' => 'Niemcy',             'min' => 3,  'max' => 12],
            '+44'  => ['iso' => 'gb', 'label' => 'Wielka Brytania',    'min' => 7,  'max' => 10],
            '+380' => ['iso' => 'ua', 'label' => 'Ukraina',            'min' => 9,  'max' => 9],
            '+33'  => ['iso' => 'fr', 'label' => 'Francja',            'min' => 9,  'max' => 9],
            '+39'  => ['iso' => 'it', 'label' => 'Włochy',             'min' => 6,  'max' => 11],
            '+34'  => ['iso' => 'es', 'label' => 'Hiszpania',          'min' => 9,  'max' => 9],
            '+31'  => ['iso' => 'nl', 'label' => 'Holandia',           'min' => 9,  'max' => 9],
            '+32'  => ['iso' => 'be', 'label' => 'Belgia',             'min' => 8,  'max' => 9],
            '+41'  => ['iso' => 'ch', 'label' => 'Szwajcaria',         'min' => 9,  'max' => 9],
            '+43'  => ['iso' => 'at', 'label' => 'Austria',            'min' => 4,  'max' => 13],
            '+420' => ['iso' => 'cz', 'label' => 'Czechy',             'min' => 9,  'max' => 9],
            '+421' => ['iso' => 'sk', 'label' => 'Słowacja',           'min' => 9,  'max' => 9],
            '+36'  => ['iso' => 'hu', 'label' => 'Węgry',              'min' => 8,  'max' => 9],
            '+40'  => ['iso' => 'ro', 'label' => 'Rumunia',            'min' => 9,  'max' => 9],
            '+359' => ['iso' => 'bg', 'label' => 'Bułgaria',           'min' => 8,  'max' => 9],
            '+30'  => ['iso' => 'gr', 'label' => 'Grecja',             'min' => 10, 'max' => 10],
            '+351' => ['iso' => 'pt', 'label' => 'Portugalia',         'min' => 9,  'max' => 9],
            '+46'  => ['iso' => 'se', 'label' => 'Szwecja',            'min' => 7,  'max' => 13],
            '+47'  => ['iso' => 'no', 'label' => 'Norwegia',           'min' => 8,  'max' => 8],
            '+45'  => ['iso' => 'dk', 'label' => 'Dania',              'min' => 8,  'max' => 8],
            '+358' => ['iso' => 'fi', 'label' => 'Finlandia',          'min' => 5,  'max' => 12],
            '+372' => ['iso' => 'ee', 'label' => 'Estonia',            'min' => 7,  'max' => 8],
            '+371' => ['iso' => 'lv', 'label' => 'Łotwa',              'min' => 8,  'max' => 8],
            '+370' => ['iso' => 'lt', 'label' => 'Litwa',              'min' => 8,  'max' => 8],
            '+353' => ['iso' => 'ie', 'label' => 'Irlandia',           'min' => 7,  'max' => 9],
            '+354' => ['iso' => 'is', 'label' => 'Islandia',           'min' => 7,  'max' => 7],
            '+352' => ['iso' => 'lu', 'label' => 'Luksemburg',         'min' => 4,  'max' => 11],
            '+356' => ['iso' => 'mt', 'label' => 'Malta',              'min' => 8,  'max' => 8],
            '+357' => ['iso' => 'cy', 'label' => 'Cypr',               'min' => 8,  'max' => 8],
            '+381' => ['iso' => 'rs', 'label' => 'Serbia',             'min' => 8,  'max' => 9],
            '+385' => ['iso' => 'hr', 'label' => 'Chorwacja',          'min' => 8,  'max' => 9],
            '+386' => ['iso' => 'si', 'label' => 'Słowenia',           'min' => 8,  'max' => 8],
            '+387' => ['iso' => 'ba', 'label' => 'Bośnia i Herceg.',   'min' => 8,  'max' => 8],
            '+382' => ['iso' => 'me', 'label' => 'Czarnogóra',         'min' => 8,  'max' => 8],
            '+389' => ['iso' => 'mk', 'label' => 'Macedonia Płn.',     'min' => 8,  'max' => 8],
            '+355' => ['iso' => 'al', 'label' => 'Albania',            'min' => 9,  'max' => 9],
            '+373' => ['iso' => 'md', 'label' => 'Mołdawia',           'min' => 8,  'max' => 8],
            '+374' => ['iso' => 'am', 'label' => 'Armenia',            'min' => 8,  'max' => 8],
            '+375' => ['iso' => 'by', 'label' => 'Białoruś',           'min' => 9,  'max' => 9],
            // Ameryka Północna
            '+1'   => ['iso' => 'us', 'label' => 'USA / Kanada',       'min' => 10, 'max' => 10],
            '+52'  => ['iso' => 'mx', 'label' => 'Meksyk',             'min' => 10, 'max' => 10],
            // Ameryka Południowa
            '+55'  => ['iso' => 'br', 'label' => 'Brazylia',           'min' => 10, 'max' => 11],
            '+54'  => ['iso' => 'ar', 'label' => 'Argentyna',          'min' => 10, 'max' => 10],
            '+56'  => ['iso' => 'cl', 'label' => 'Chile',              'min' => 9,  'max' => 9],
            '+57'  => ['iso' => 'co', 'label' => 'Kolumbia',           'min' => 10, 'max' => 10],
            '+51'  => ['iso' => 'pe', 'label' => 'Peru',               'min' => 9,  'max' => 9],
            // Oceania
            '+61'  => ['iso' => 'au', 'label' => 'Australia',          'min' => 9,  'max' => 9],
            '+64'  => ['iso' => 'nz', 'label' => 'Nowa Zelandia',      'min' => 8,  'max' => 9],
            // Azja Wschodnia
            '+81'  => ['iso' => 'jp', 'label' => 'Japonia',            'min' => 10, 'max' => 11],
            '+86'  => ['iso' => 'cn', 'label' => 'Chiny',              'min' => 11, 'max' => 11],
            '+82'  => ['iso' => 'kr', 'label' => 'Korea Płd.',         'min' => 9,  'max' => 10],
            // Azja Południowa
            '+91'  => ['iso' => 'in', 'label' => 'Indie',              'min' => 10, 'max' => 10],
            '+92'  => ['iso' => 'pk', 'label' => 'Pakistan',           'min' => 10, 'max' => 10],
            // Azja Południowo-Wschodnia
            '+62'  => ['iso' => 'id', 'label' => 'Indonezja',          'min' => 9,  'max' => 12],
            '+66'  => ['iso' => 'th', 'label' => 'Tajlandia',          'min' => 9,  'max' => 9],
            '+84'  => ['iso' => 'vn', 'label' => 'Wietnam',            'min' => 9,  'max' => 10],
            '+63'  => ['iso' => 'ph', 'label' => 'Filipiny',           'min' => 10, 'max' => 10],
            '+60'  => ['iso' => 'my', 'label' => 'Malezja',            'min' => 9,  'max' => 10],
            '+65'  => ['iso' => 'sg', 'label' => 'Singapur',           'min' => 8,  'max' => 8],
            // Bliski Wschód
            '+972' => ['iso' => 'il', 'label' => 'Izrael',             'min' => 9,  'max' => 9],
            '+90'  => ['iso' => 'tr', 'label' => 'Turcja',             'min' => 10, 'max' => 10],
            '+966' => ['iso' => 'sa', 'label' => 'Arabia Saudyjska',   'min' => 9,  'max' => 9],
            '+971' => ['iso' => 'ae', 'label' => 'Zjednoczone Emiraty','min' => 9,  'max' => 9],
            // Afryka
            '+20'  => ['iso' => 'eg', 'label' => 'Egipt',              'min' => 10, 'max' => 10],
            '+27'  => ['iso' => 'za', 'label' => 'RPA',                'min' => 9,  'max' => 9],
            '+212' => ['iso' => 'ma', 'label' => 'Maroko',             'min' => 9,  'max' => 9],
            '+213' => ['iso' => 'dz', 'label' => 'Algieria',           'min' => 9,  'max' => 9],
            '+216' => ['iso' => 'tn', 'label' => 'Tunezja',            'min' => 8,  'max' => 8],
        ];
    }
}
