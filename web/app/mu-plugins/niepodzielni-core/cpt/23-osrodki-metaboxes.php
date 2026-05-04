<?php

/**
 * Carbon Fields — Metaboxy dla CPT Ośrodek Pomocy (Psychomapa)
 *
 * CF boot odbywa się w 21-carbon-fields.php — ten plik tylko rejestruje pola.
 * Pola taksonomii (rodzaj-pomocy, grupa-docelowa) zarządzane natywnie przez WP.
 *
 * Klucze post_meta (używane przez GeocodingService i endpoint REST):
 *   np_miasto, np_ulica, np_nr_domu, np_kod_pocztowy, np_wojewodztwo
 *   lat, lng, _np_address_hash (wewnętrzny)
 *   np_telefon, np_telefon_2, np_telefon_3, np_email, np_www, np_logo_url
 *   np_facebook, np_instagram, np_tiktok
 *   {pon|wt|sr|czw|pt|sb|nd}_{otwarcie|zamkniecie|zamkniete}
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists(\Carbon_Fields\Carbon_Fields::class)) {
    return;
}

use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action('carbon_fields_register_fields', 'np_cf_osrodki');

function np_cf_osrodki(): void
{
    Container::make('post_meta', 'np_osrodki_meta', 'Dane ośrodka')
        ->where('post_type', '=', 'osrodek_pomocy')
        ->set_context('normal')
        ->set_priority('high')

        // ─── Zakładka: Adres i Lokalizacja ────────────────────────────────────
        ->add_tab('Adres i Lokalizacja', [

            Field::make('select', 'np_wojewodztwo', 'Województwo')
                ->set_options([
                    ''                      => '— wybierz —',
                    'dolnośląskie'          => 'dolnośląskie',
                    'kujawsko-pomorskie'    => 'kujawsko-pomorskie',
                    'lubelskie'             => 'lubelskie',
                    'lubuskie'              => 'lubuskie',
                    'łódzkie'               => 'łódzkie',
                    'małopolskie'           => 'małopolskie',
                    'mazowieckie'           => 'mazowieckie',
                    'opolskie'              => 'opolskie',
                    'podkarpackie'          => 'podkarpackie',
                    'podlaskie'             => 'podlaskie',
                    'pomorskie'             => 'pomorskie',
                    'śląskie'               => 'śląskie',
                    'świętokrzyskie'        => 'świętokrzyskie',
                    'warmińsko-mazurskie'   => 'warmińsko-mazurskie',
                    'wielkopolskie'         => 'wielkopolskie',
                    'zachodniopomorskie'    => 'zachodniopomorskie',
                ])
                ->set_width(50),

            Field::make('text', 'np_kod_pocztowy', 'Kod pocztowy')
                ->set_attribute('placeholder', '00-000')
                ->set_width(25),

            Field::make('text', 'np_miasto', 'Miasto')
                ->set_attribute('placeholder', 'np. Warszawa')
                ->set_width(50),

            Field::make('text', 'np_ulica', 'Ulica')
                ->set_attribute('placeholder', 'np. ul. Marszałkowska')
                ->set_width(50),

            Field::make('text', 'np_nr_domu', 'Nr domu')
                ->set_attribute('placeholder', 'np. 10')
                ->set_width(25),

            Field::make('text', 'np_nr_mieszkania', 'Nr mieszkania')
                ->set_attribute('placeholder', 'np. 5')
                ->set_width(25),

            Field::make('html', 'cf_sep_geocoding')
                ->set_html('<p style="margin:12px 0 4px;padding:8px 12px;background:#f0f6fc;border-left:3px solid #2271b1;color:#1d2327;font-size:13px">🗺️ <strong>Geokodowanie automatyczne</strong> — po zapisaniu posta współrzędne GPS są pobierane z OpenStreetMap (Nominatim). Zmień adres i zapisz, aby odświeżyć pozycję na mapie.</p>'),

            Field::make('text', 'lat', 'Szerokość geograficzna (lat)')
                ->set_attribute('readOnly', 'readonly')
                ->set_attribute('placeholder', 'Wypełniane automatycznie')
                ->set_width(50)
                ->set_help_text('Pobierane automatycznie na podstawie adresu. Nie edytuj ręcznie.'),

            Field::make('text', 'lng', 'Długość geograficzna (lng)')
                ->set_attribute('readOnly', 'readonly')
                ->set_attribute('placeholder', 'Wypełniane automatycznie')
                ->set_width(50)
                ->set_help_text('Pobierane automatycznie na podstawie adresu. Nie edytuj ręcznie.'),
        ])

        // ─── Zakładka: Kontakt ────────────────────────────────────────────────
        ->add_tab('Kontakt', [

            Field::make('text', 'np_telefon', 'Telefon główny')
                ->set_attribute('type', 'tel')
                ->set_attribute('placeholder', 'np. 22 123 45 67')
                ->set_width(50),

            Field::make('text', 'np_telefon_2', 'Telefon dodatkowy')
                ->set_attribute('type', 'tel')
                ->set_attribute('placeholder', 'np. 500 100 200')
                ->set_width(50),

            Field::make('text', 'np_telefon_3', 'Telefon dodatkowy 2')
                ->set_attribute('type', 'tel')
                ->set_attribute('placeholder', 'np. 800 123 456')
                ->set_width(50),

            Field::make('text', 'np_email', 'E-mail')
                ->set_attribute('type', 'email')
                ->set_attribute('placeholder', 'kontakt@osrodek.pl')
                ->set_width(50),

            Field::make('text', 'np_www', 'Strona WWW')
                ->set_attribute('type', 'url')
                ->set_attribute('placeholder', 'https://www.osrodek.pl')
                ->set_width(50),

            Field::make('text', 'np_logo_url', 'URL logo')
                ->set_attribute('type', 'url')
                ->set_attribute('placeholder', 'https://media.niepodzielni.com/...')
                ->set_width(100)
                ->set_help_text('Przechowywany jako URL (z media.niepodzielni.com). Wypełniany automatycznie przez import CSV.'),
        ])

        // ─── Zakładka: Social Media ───────────────────────────────────────────
        ->add_tab('Social Media', [

            Field::make('text', 'np_facebook', 'Facebook')
                ->set_attribute('type', 'url')
                ->set_attribute('placeholder', 'https://facebook.com/...')
                ->set_width(50),

            Field::make('text', 'np_instagram', 'Instagram')
                ->set_attribute('type', 'url')
                ->set_attribute('placeholder', 'https://instagram.com/...')
                ->set_width(50),

            Field::make('text', 'np_tiktok', 'TikTok')
                ->set_attribute('type', 'url')
                ->set_attribute('placeholder', 'https://tiktok.com/@...')
                ->set_width(50),
        ])

        // ─── Zakładka: Godziny otwarcia ───────────────────────────────────────
        ->add_tab('Godziny otwarcia', np_cf_osrodki_hours_fields());
}

/**
 * Zwraca tablicę pól CF dla godzin otwarcia (7 dni × 3 pola).
 *
 * @return array<int, \Carbon_Fields\Field\Field>
 */
function np_cf_osrodki_hours_fields(): array
{
    $days = [
        'pon' => 'Poniedziałek',
        'wt'  => 'Wtorek',
        'sr'  => 'Środa',
        'czw' => 'Czwartek',
        'pt'  => 'Piątek',
        'sb'  => 'Sobota',
        'nd'  => 'Niedziela',
    ];

    $fields = [];

    foreach ($days as $prefix => $label) {
        $fields[] = Field::make('html', "cf_sep_day_{$prefix}")
            ->set_html("<h4 style='margin:16px 0 8px;color:#1d2327;font-size:13px;font-weight:600'>{$label}</h4>");

        $fields[] = Field::make('text', "{$prefix}_otwarcie", 'Godzina otwarcia')
            ->set_attribute('placeholder', '08:00')
            ->set_width(33);

        $fields[] = Field::make('text', "{$prefix}_zamkniecie", 'Godzina zamknięcia')
            ->set_attribute('placeholder', '16:00')
            ->set_width(33);

        $fields[] = Field::make('select', "{$prefix}_zamkniete", 'Zamknięte')
            ->set_options([
                ''    => 'Nie',
                'tak' => 'Tak (zamknięte)',
            ])
            ->set_width(33);
    }

    return $fields;
}

// Wyłącz edytor blokowy dla osrodek_pomocy — CF v3 używa klasycznych metaboksów
add_filter('use_block_editor_for_post_type', 'np_cf_disable_gutenberg_osrodki', 10, 2);

function np_cf_disable_gutenberg_osrodki(bool $use, string $post_type): bool
{
    return $post_type === 'osrodek_pomocy' ? false : $use;
}
