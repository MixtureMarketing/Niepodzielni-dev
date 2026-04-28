<?php

/**
 * Carbon Fields — Metaboxy dla wszystkich CPT
 *
 * Zastępuje 20-cpt-metaboxes.php (natywne add_meta_box + ręczny save_post).
 * Carbon Fields automatycznie: weryfikuje nonce, obsługuje autosave,
 * sanitizuje dane wejściowe i renderuje profesjonalny UI.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * INSTALACJA (jednorazowo, w katalogu root Bedrocka — tam gdzie composer.json):
 *
 *   composer require htmlburger/carbon-fields
 *
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * Obsługiwane CPT:
 *   - psycholog      → Ustawienia Bookero (IDs, stawki, typ wizyty) + taksonomie
 *   - warsztaty      → Data, godziny, lokalizacja, status, cena, prowadzący
 *   - grupy-wsparcia → (te same pola co warsztaty — współdzielony kontener)
 *   - wydarzenia     → Data, godziny, miasto, koszt, opis
 *   - aktualnosci    → Data, miejsce, zdjęcie
 *
 * Backward compatibility:
 *   Wartości post_meta są przechowywane pod tymi samymi kluczami co poprzednio,
 *   więc get_post_meta(), PsychologistListingService i EventsListingService
 *   działają bez żadnych zmian.
 *
 *   Wyjątek: pole "Rodzaj ceny" zmieniono z klucza 'cena_-_rodzaj' na 'cena_rodzaj'.
 *   Zaktualizuj odpowiednio EventsListingService (patrz komentarz w getWorkshopsData).
 *
 * Synchronizacja taksonomii:
 *   CF multiselect dla obszarów/nurtów/języków/specjalizacji przechowuje
 *   zaznaczone slugi jako post_meta (klucze: cf_obszary_pomocy, cf_nurty, etc.).
 *   Hook save_post_psycholog (priorytet 20) przepisuje wartości do natywnych
 *   term_relationships WP, dzięki czemu get_the_terms() działa bez zmian.
 */

if (! defined('ABSPATH')) {
    exit;
}

// Carbon Fields musi być zainstalowany przez Composer (composer require htmlburger/carbon-fields)
if (! class_exists(\Carbon_Fields\Carbon_Fields::class)) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Niepodzielni] Carbon Fields nie jest zainstalowany. Uruchom: composer require htmlburger/carbon-fields w katalogu root Bedrocka.');
    }
    return;
}

use Carbon_Fields\Container;
use Carbon_Fields\Field;

// ─── Boot ─────────────────────────────────────────────────────────────────────
// Musi nastąpić na after_setup_theme — CF rejestruje własne hooki i assets.

add_action('after_setup_theme', static function (): void {
    // CF jest zainstalowany w vendor/ (poza DocumentRoot).
    // entrypoint.sh kopiuje vendor/htmlburger/carbon-fields → web/carbon-fields/,
    // więc assety są dostępne przez HTTP pod /carbon-fields/.
    // config.php definiuje stałą Carbon_Fields\URL tylko gdy nie jest zdefiniowana —
    // definiujemy ją tutaj PRZED boot(), żeby directory_to_url() nie zwróciło ''
    // (vendor/ jest poza WP_CONTENT_DIR i ABSPATH w Bedrocku).
    if (! defined('Carbon_Fields\URL')) {
        define('Carbon_Fields\URL', rtrim(home_url('/carbon-fields'), '/'));
    }

    \Carbon_Fields\Carbon_Fields::boot();
});

// ─── Rejestracja pól ─────────────────────────────────────────────────────────
// carbon_fields_register_fields odpala się po CF boot, przed admin_init.

add_action('carbon_fields_register_fields', 'np_cf_register_all_fields');

function np_cf_register_all_fields(): void
{
    np_cf_psycholog();
    np_cf_warsztaty_grupy();
    np_cf_wydarzenia();
    np_cf_aktualnosci();
    np_cf_theme_options();
}

// ─── Theme Options ────────────────────────────────────────────────────────────

function np_cf_theme_options(): void
{
    Container::make('theme_options', 'np_theme_options', 'Ustawienia motywu')
        ->set_page_menu_position(61)
        ->set_icon('dashicons-admin-customizer')
        ->add_tab('Strona główna', [

            Field::make('html', 'cf_sep_hero_tax')
                ->set_html('<h3 style="margin:4px 0 12px;padding-bottom:8px;border-bottom:1px solid #e0e0e0;color:#1d2327">Sekcja Hero (1,5%)</h3>'),

            Field::make('image', 'hero_tax_bg_desktop', 'Zdjęcie tła — desktop')
                ->set_value_type('id')
                ->set_width(50)
                ->set_help_text('Wyświetlane na ekranach > 768 px.'),

            Field::make('image', 'hero_tax_bg_mobile', 'Zdjęcie tła — mobile')
                ->set_value_type('id')
                ->set_width(50)
                ->set_help_text('Wyświetlane na ekranach ≤ 768 px. Jeśli puste, używane jest zdjęcie desktop.'),
        ])
        ->add_tab('Partnerzy', [

            Field::make('html', 'cf_sep_partners')
                ->set_html('<h3 style="margin:4px 0 12px;padding-bottom:8px;border-bottom:1px solid #e0e0e0;color:#1d2327">Slider partnerów (stopka)</h3><p style="color:#555;margin-bottom:16px">Dodaj logo każdego partnera. Link i jego rodzaj są opcjonalne.</p>'),

            Field::make('complex', 'partners', 'Partnerzy')
                ->set_layout('tabbed-vertical')
                ->setup_labels([
                    'plural_name'   => 'Partnerzy',
                    'singular_name' => 'Partner',
                ])
                ->add_fields([
                    Field::make('image', 'logo', 'Logo')
                        ->set_value_type('id')
                        ->set_help_text('Preferowany format: SVG lub PNG z przezroczystością.'),

                    Field::make('text', 'name', 'Nazwa partnera')
                        ->set_help_text('Używana jako atrybut alt obrazka.')
                        ->set_width(50),

                    Field::make('text', 'url', 'Adres URL (opcjonalnie)')
                        ->set_attribute('placeholder', 'https://')
                        ->set_width(50),

                    Field::make('select', 'link_rel', 'Rodzaj linku')
                        ->set_options([
                            'follow'    => 'Follow (domyślny)',
                            'nofollow'  => 'Nofollow',
                            'sponsored' => 'Sponsored',
                        ])
                        ->set_default_value('follow')
                        ->set_width(50),
                ]),
        ]);
}

// ─── CPT: Psycholog ───────────────────────────────────────────────────────────

function np_cf_psycholog(): void
{
    // ── Kontener 1: Dane Bookero i ustawienia wizyty ──────────────────────────
    Container::make('post_meta', 'np_psycholog_bookero', 'Ustawienia Psychologa')
        ->where('post_type', '=', 'psycholog')
        ->set_context('normal')
        ->set_priority('high')
        ->add_fields([

            // ── Wizyty pełnopłatne ────────────────────────────────────────────
            // Obecność Bookero ID = psycholog świadczy dany rodzaj usług.
            // Nie ma potrzeby osobnego checkboxa "Świadczy wizyty X".

            Field::make('html', 'cf_sep_pelno')
                ->set_html('<h3 style="margin:4px 0 12px;padding-bottom:8px;border-bottom:1px solid #e0e0e0;color:#1d2327">Wizyty pełnopłatne</h3>'),

            Field::make('text', 'bookero_id_pelny', 'Bookero ID (pełnopłatne)')
                ->set_attribute('placeholder', 'np. 12345')
                ->set_width(50)
                ->set_help_text('ID pracownika w Bookero. Puste = nie świadczy wizyt pełnopłatnych.'),

            Field::make('text', 'stawka_wysokoplatna', 'Stawka (pełnopłatna)')
                ->set_attribute('placeholder', 'np. 145 zł')
                ->set_width(50)
                ->set_help_text('Puste = domyślna stawka z Ustawień → Bookero.'),

            // ── Wizyty niskopłatne ────────────────────────────────────────────

            Field::make('html', 'cf_sep_nisko')
                ->set_html('<h3 style="margin:20px 0 12px;padding-bottom:8px;border-bottom:1px solid #e0e0e0;color:#1d2327">Wizyty niskopłatne</h3>'),

            Field::make('text', 'bookero_id_niski', 'Bookero ID (niskopłatne)')
                ->set_attribute('placeholder', 'np. 12346')
                ->set_width(50)
                ->set_help_text('ID pracownika w Bookero. Puste = nie świadczy wizyt niskopłatnych.'),

            Field::make('text', 'stawka_niskoplatna', 'Stawka (niskopłatna)')
                ->set_attribute('placeholder', 'np. 55 zł')
                ->set_width(50)
                ->set_help_text('Puste = domyślna stawka z Ustawień → Bookero.'),

            // ── Ogólne ────────────────────────────────────────────────────────

            Field::make('html', 'cf_sep_ogolne')
                ->set_html('<h3 style="margin:20px 0 12px;padding-bottom:8px;border-bottom:1px solid #e0e0e0;color:#1d2327">Ogólne</h3>'),

            Field::make('select', 'rodzaj_wizyty', 'Rodzaj wizyty')
                ->set_options([
                    ''                      => '— wybierz —',
                    'Online'                => 'Online',
                    'Stacjonarnie'          => 'Stacjonarnie',
                    'Online i Stacjonarnie' => 'Online i Stacjonarnie',
                ]),

            Field::make('rich_text', 'biogram', 'Biogram')
                ->set_rows(6)
                ->set_help_text('Opis psychologa widoczny na listingu i stronie profilu.'),

            Field::make('text', 'tryb_konsultacji_info', 'Tryb konsultacji (info)')
                ->set_attribute('placeholder', 'np. Przyjmuję od poniedziałku do piątku')
                ->set_help_text('Dodatkowa informacja o dostępności — widoczna na profilu.'),

            // ── Konto WP psychologa ───────────────────────────────────────────
            Field::make('html', 'cf_sep_konto')
                ->set_html('<h3 style="margin:20px 0 12px;padding-bottom:8px;border-bottom:1px solid #e0e0e0;color:#1d2327">Konto psychologa (panel frontendowy)</h3>'),

            Field::make('text', 'email_kontaktowy', 'Email logowania')
                ->set_attribute('type', 'email')
                ->set_attribute('placeholder', 'jan.kowalski@example.com')
                ->set_help_text('Po wpisaniu emaila i zapisaniu posta — w boxie "Konto psychologa" pojawi się przycisk do utworzenia konta WP.'),
        ]);

    // ── Kontener 2: Obszary, Nurty, Języki, Specjalizacje ────────────────────
    // Multiselect pobiera opcje dynamicznie z zarejestrowanych taksonomii WP.
    // Wartości zapisywane jako post_meta (klucze: cf_obszary_pomocy, cf_nurty, ...).
    // Hook np_cf_sync_psycholog_taxonomies() (poniżej) synchronizuje je z
    // natywnym term_relationships, dzięki czemu get_the_terms() działa bez zmian.
    Container::make('post_meta', 'np_psycholog_taxonomies', 'Obszary i Specjalizacje')
        ->where('post_type', '=', 'psycholog')
        ->set_context('normal')
        ->set_priority('default')
        ->add_fields([

            Field::make('multiselect', 'cf_obszary_pomocy', 'Obszary pomocy')
                ->set_options(static fn() => np_cf_taxonomy_options('obszar-pomocy'))
                ->set_help_text(
                    'Zaznaczone obszary wpływają na scoring w Matchmakerze. '
                    . 'Zmiany są automatycznie synchronizowane do taksonomii WP po zapisaniu.',
                ),

            Field::make('multiselect', 'cf_nurty', 'Nurty terapeutyczne')
                ->set_options(static fn() => np_cf_taxonomy_options('nurt')),

            Field::make('multiselect', 'cf_jezyki', 'Języki wizyt')
                ->set_options(static fn() => np_cf_taxonomy_options('jezyk')),

            Field::make('multiselect', 'cf_specjalizacje', 'Specjalizacje / Rola')
                ->set_options(static fn() => np_cf_taxonomy_options('specjalizacja'))
                ->set_help_text(
                    'Pierwsza zaznaczona wartość wyświetlana jest jako "Rola" na karcie psychologa na listingu.',
                ),
        ]);
}

// ─── CPT: Warsztaty + Grupy wsparcia ─────────────────────────────────────────
// Oba CPT współdzielą ten sam zestaw pól — dwa warunki where() z tą samą definicją.

function np_cf_warsztaty_grupy(): void
{
    Container::make('post_meta', 'np_warsztaty_meta', 'Dane wydarzenia')
        ->where('post_type', '=', 'warsztaty')
        ->or_where('post_type', '=', 'grupy-wsparcia')
        ->set_context('normal')
        ->set_priority('high')
        ->add_fields([

            Field::make('text', 'temat', 'Temat')
                ->set_attribute('placeholder', 'Tytuł spotkania')
                ->set_help_text('Jeśli puste, na listingu używany jest tytuł postu.'),

            Field::make('date', 'data', 'Data')
                ->set_storage_format('Y-m-d'),

            Field::make('text', 'godzina', 'Godzina rozpoczęcia')
                ->set_attribute('placeholder', 'np. 18:00')
                ->set_width(50),

            Field::make('text', 'godzina_zakonczenia', 'Godzina zakończenia')
                ->set_attribute('placeholder', 'np. 20:00')
                ->set_width(50),

            Field::make('text', 'lokalizacja', 'Lokalizacja')
                ->set_attribute('placeholder', 'np. Warszawa, ul. Nowy Świat 1 / online'),

            Field::make('select', 'status', 'Status zapisów')
                ->set_options([
                    ''                 => '— wybierz —',
                    'Planowane'        => 'Planowane',
                    'Trwa zapisy'      => 'Trwa zapisy',
                    'Zapisy zamknięte' => 'Zapisy zamknięte',
                    'Odwołane'         => 'Odwołane',
                ]),

            Field::make('text', 'cena', 'Cena')
                ->set_attribute('placeholder', 'np. 150 zł')
                ->set_width(50),

            // UWAGA: Klucz postmeta zmieniony z 'cena_-_rodzaj' na 'cena_rodzaj'.
            // Zaktualizuj EventsListingService::getWorkshopsData() — zmień:
            //   get_post_meta($pid, 'cena_-_rodzaj', true)  →  get_post_meta($pid, 'cena_rodzaj', true)
            Field::make('text', 'cena_rodzaj', 'Rodzaj ceny')
                ->set_attribute('placeholder', 'np. za osobę, za parę')
                ->set_width(50),

            Field::make('text', 'stanowisko', 'Stanowisko prowadzącego')
                ->set_attribute('placeholder', 'np. Psychoterapeuta, Trener')
                ->set_width(50),

            // ID postu psychologa pełniącego rolę prowadzącego.
            // np_get_event_leader_name() czyta ten klucz i pobiera imię z CPT psycholog.
            // TODO: w przyszłości zastąpić Field::make('association', ...) z limitem 1.
            Field::make('text', 'prowadzacy_id', 'ID Prowadzącego (psycholog)')
                ->set_attribute('placeholder', 'ID postu z CPT Psycholog')
                ->set_width(50)
                ->set_help_text('Wpisz numeryczne ID wpisu z sekcji Psycholodzy.'),

            Field::make('image', 'zdjecie_glowne', 'Zdjęcie główne')
                ->set_value_type('id')
                ->set_help_text('Przechowywane jako ID attachmentu — obsługiwane przez np_get_post_image_url().'),
        ]);
}

// ─── CPT: Wydarzenia ─────────────────────────────────────────────────────────

function np_cf_wydarzenia(): void
{
    Container::make('post_meta', 'np_wydarzenia_meta', 'Dane wydarzenia')
        ->where('post_type', '=', 'wydarzenia')
        ->set_context('normal')
        ->set_priority('high')
        ->add_fields([

            Field::make('date', 'data', 'Data')
                ->set_storage_format('Y-m-d'),

            Field::make('text', 'godzina_rozpoczecia', 'Godzina rozpoczęcia')
                ->set_attribute('placeholder', 'np. 18:00')
                ->set_width(50),

            Field::make('text', 'godzina_zakonczenia', 'Godzina zakończenia')
                ->set_attribute('placeholder', 'np. 21:00')
                ->set_width(50),

            Field::make('text', 'miasto', 'Miasto')
                ->set_attribute('placeholder', 'np. Warszawa')
                ->set_width(50),

            Field::make('text', 'lokalizacja', 'Lokalizacja / adres')
                ->set_attribute('placeholder', 'np. ul. Nowy Świat 1')
                ->set_width(50),

            Field::make('text', 'koszt', 'Koszt')
                ->set_attribute('placeholder', 'np. Bezpłatne / 50 zł'),

            Field::make('textarea', 'opis', 'Opis skrócony')
                ->set_rows(3)
                ->set_help_text('Widoczny na listingu. Jeśli puste, używany jest excerpt postu.'),

            Field::make('image', 'zdjecie', 'Zdjęcie główne')
                ->set_value_type('id'),

            Field::make('image', 'zdjecie_tla', 'Zdjęcie tła')
                ->set_value_type('id'),
        ]);
}

// ─── CPT: Aktualności ────────────────────────────────────────────────────────

function np_cf_aktualnosci(): void
{
    Container::make('post_meta', 'np_aktualnosci_meta', 'Dane aktualności')
        ->where('post_type', '=', 'aktualnosci')
        ->set_context('normal')
        ->set_priority('high')
        ->add_fields([

            Field::make('date', 'data_wydarzenia', 'Data wydarzenia')
                ->set_storage_format('Y-m-d')
                ->set_help_text('Jeśli puste, na listingu używana jest data publikacji postu.'),

            Field::make('text', 'miejsce', 'Miejsce')
                ->set_attribute('placeholder', 'np. Warszawa / online / Fundacja Niepodzielni'),

            Field::make('image', 'zdjecie_glowne', 'Zdjęcie główne')
                ->set_value_type('id'),
        ]);
}

// ─── Helper: opcje multiselect z taksonomii ───────────────────────────────────

/**
 * Zwraca tablicę [ 'slug' => 'Nazwa termu' ] dla podanej taksonomii.
 * Używane jako callable w Field::make('multiselect')->set_options(fn() => ...).
 *
 * Pobierane dynamicznie (callable, nie tablica statyczna), więc opcje zawsze
 * odzwierciedlają aktualny stan taksonomii bez potrzeby modyfikowania kodu.
 *
 * @param  string  $taxonomy  Slug taksonomii WP (np. 'obszar-pomocy', 'nurt')
 * @return array<string, string>
 */
function np_cf_taxonomy_options(string $taxonomy): array
{
    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    // array_column działa na tablicach obiektów od PHP 7.0
    return array_column((array) $terms, 'name', 'slug');
}

// ─── Ukryj natywne metaboksy taksonomii (zastąpione przez CF multiselect) ────
// CF ma swoje multiselekty dla obszar-pomocy, nurt, jezyk, specjalizacja.
// Natywne checkbox/tag-cloud metaboksy WP byłyby drugi input do tych samych danych.

// Wyłącz edytor blokowy (Gutenberg) dla psychologa — CF v3 używa klasycznych metaboksów.
// show_in_rest pozostaje true (REST API potrzebne dla front-end listingów i matchmakera).
add_filter('use_block_editor_for_post_type', 'np_cf_disable_block_editor_for_psycholog', 10, 2);

function np_cf_disable_block_editor_for_psycholog(bool $use, string $post_type): bool
{
    return $post_type === 'psycholog' ? false : $use;
}

// Klasyczny edytor: usuń natywne metaboksy taksonomii zarządzanych przez CF multiselect.
// Priorytet 999 — po tym jak WP i inne pluginy zdążą zarejestrować swoje metaboksy.
add_action('add_meta_boxes', 'np_cf_remove_native_taxonomy_metaboxes', 999);

function np_cf_remove_native_taxonomy_metaboxes(): void
{
    $taxonomies = ['obszar-pomocy', 'nurt', 'jezyk', 'specjalizacja'];

    foreach ($taxonomies as $taxonomy) {
        remove_meta_box('tagsdiv-' . $taxonomy, 'psycholog', 'side');
        remove_meta_box($taxonomy . 'div', 'psycholog', 'side');
    }
}

// ─── Synchronizacja taksonomii WP ────────────────────────────────────────────

/**
 * Po zapisaniu posta psychologa: przepisuje wartości CF multiselect
 * do natywnych term_relationships WordPress.
 *
 * Dlaczego priorytet 20?
 *   CF zapisuje post_meta na hooku save_post z domyślnym priorytetem 10.
 *   Nasz hook uruchamia się PO CF (20 > 10), więc cf_* meta już istnieje w DB.
 *
 * Backward compat dla istniejących wpisów:
 *   Jeśli wpis nigdy nie był edytowany przez CF (brak cf_* meta w DB),
 *   hook pomija synchronizację — istniejące taksonomie WP są zachowane.
 *   Przy pierwszym zapisie z formularza CF wartości zostaną zsynchronizowane.
 *
 * @param int $post_id  ID zapisywanego posta
 */
add_action('save_post_psycholog', 'np_cf_sync_psycholog_taxonomies', 20);

function np_cf_sync_psycholog_taxonomies(int $post_id): void
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Mapa: klucz postmeta CF → slug taksonomii WP
    $sync_map = [
        'cf_obszary_pomocy' => 'obszar-pomocy',
        'cf_nurty'          => 'nurt',
        'cf_jezyki'         => 'jezyk',
        'cf_specjalizacje'  => 'specjalizacja',
    ];

    foreach ($sync_map as $meta_key => $taxonomy) {
        // CF zapisuje dane zarówno w cf_* (PHP serialized) jak i w _cf_*|||n|value.
        // carbon_get_post_meta czyta z _cf_*|||n|value — poprawny format po CF v3.
        // Pomiń jeśli CF meta nie istnieje (chroni psychologów nigdy nie edytowanych przez CF).
        if (! metadata_exists('post', $post_id, $meta_key)) {
            continue;
        }

        $slugs = (array) carbon_get_post_meta($post_id, $meta_key);
        $slugs = array_values(array_filter(array_map('sanitize_key', $slugs)));

        // wp_set_object_terms([]) czyści przypisane termy — intencjonalne zachowanie
        // gdy redaktor odznaczył wszystkie obszary w CF multiselect.
        wp_set_object_terms($post_id, $slugs, $taxonomy);
    }
}
