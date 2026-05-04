<?php

declare(strict_types=1);

namespace Niepodzielni\Psychomapa;

/**
 * WP-CLI: wp niepodzielni import-psychomapa <sciezka> [--dry-run]
 *
 * Importuje lub aktualizuje ośrodki pomocy z pliku CSV.
 *
 * Logika:
 *  - Wyszukuje istniejący wpis po tytule (nazwa) — aktualizuje jeśli istnieje.
 *  - Logo: przepisuje niepodzielni.com → media.niepodzielni.com.
 *  - Taksonomie: tworzy brakujące termy automatycznie (wp_set_object_terms).
 *  - Geocoding: pomija jeśli adres niezmieniony i lat/lng są już zapisane.
 *  - sleep(1) między geokodowaniem wpisów (polityka Nominatim: max 1 req/sec).
 *
 * Przykład użycia:
 *   wp niepodzielni import-psychomapa /var/www/data/psychomapa.csv --allow-root
 *   wp niepodzielni import-psychomapa /var/www/data/psychomapa.csv --dry-run
 */
class ImportPsychomapyCommand
{
    /** Mapa: kolumna CSV → klucz post_meta */
    private const META_MAP = [
        'wojewodztwa'               => 'np_wojewodztwo',
        'kod_pocztowy'              => 'np_kod_pocztowy',
        'miasto'                    => 'np_miasto',
        'ulica'                     => 'np_ulica',
        'nr_domu'                   => 'np_nr_domu',
        'nr_mieszkania'             => 'np_nr_mieszkania',
        'numer_telefonu'            => 'np_telefon',
        'numer_telefonu_dodatkowy'  => 'np_telefon_2',
        'numer_telefonu_dodatkowy_2'=> 'np_telefon_3',
        'e_mail'                    => 'np_email',
        'strona'                    => 'np_www',
        'facebook'                  => 'np_facebook',
        'instagram'                 => 'np_instagram',
        'tiktok'                    => 'np_tiktok',
    ];

    /** Mapa: prefiks dnia → kolumny CSV z godzinami */
    private const DAYS = [
        'pon' => 'pon',
        'wt'  => 'wt',
        'sr'  => 'sr',
        'czw' => 'czw',
        'pt'  => 'pt',
        'sb'  => 'sb',
        'nd'  => 'nd',
    ];

    public function __construct(
        private readonly GeocodingService $geocoder,
    ) {}

    /**
     * @param  array<int, string>     $args        Pozycyjne argumenty (ścieżka CSV)
     * @param  array<string, mixed>   $assocArgs   Opcje (--dry-run)
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $path   = $args[0] ?? '';
        $dryRun = (bool) (\WP_CLI\Utils\get_flag_value($assocArgs, 'dry-run', false));

        if ($path === '' || ! file_exists($path)) {
            \WP_CLI::error("Plik CSV nie istnieje: {$path}");
        }

        if ($dryRun) {
            \WP_CLI::warning('Tryb --dry-run: żadne zmiany nie zostaną zapisane.');
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            \WP_CLI::error("Nie można otworzyć pliku: {$path}");
        }

        // Pierwsza linia = nagłówki
        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);
            \WP_CLI::error('Plik CSV jest pusty lub ma nieprawidłowy format.');
        }

        $headers = array_map('trim', $headers);
        $colIndex = array_flip($headers);

        $count   = 0;
        $created = 0;
        $updated = 0;
        $errors  = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $count++;

            if (count($row) !== count($headers)) {
                \WP_CLI::warning("Wiersz {$count}: nieprawidłowa liczba kolumn — pomijam.");
                $errors++;
                continue;
            }

            $data = [];
            foreach ($headers as $i => $col) {
                $data[$col] = trim($row[$i] ?? '');
            }

            $title = $data['nazwa'] ?? '';
            if ($title === '') {
                \WP_CLI::warning("Wiersz {$count}: brak nazwy — pomijam.");
                $errors++;
                continue;
            }

            if ($dryRun) {
                \WP_CLI::line("[dry-run] Wiersz {$count}: {$title}");
                continue;
            }

            $postId = $this->findOrCreatePost($title, $data['opis'] ?? '');
            if ($postId === 0) {
                \WP_CLI::warning("Wiersz {$count}: błąd przy tworzeniu posta '{$title}' — pomijam.");
                $errors++;
                continue;
            }

            $isNew = get_post_meta($postId, '_np_imported', true) === '';
            $isNew ? $created++ : $updated++;

            // Zapisz wszystkie meta
            $this->saveMeta($postId, $data, $colIndex);

            // Taksonomie
            $this->saveTerms($postId, $data['rodzaj_pomocy'] ?? '', 'rodzaj-pomocy');
            $this->saveTerms($postId, $data['dedykowane_dla'] ?? '', 'grupa-docelowa');

            // Logo URL
            $this->saveLogo($postId, $data['logo'] ?? '');

            // Godziny otwarcia
            $this->saveHours($postId, $data);

            // Geocoding
            $this->maybeGeocode($postId, $data, $count);

            // Znacznik importu
            update_post_meta($postId, '_np_imported', date('Y-m-d H:i:s'));

            \WP_CLI::line("  [{$count}] " . ($isNew ? 'Dodano' : 'Zaktualizowano') . ": {$title} (ID: {$postId})");
        }

        fclose($handle);

        // Inwalidacja cache REST API
        if (! $dryRun) {
            wp_cache_delete('np_psychomapa_all', 'np_psychomapa');
        }

        \WP_CLI::success(
            "Import zakończony. Wierszy: {$count} | "
            . "Dodano: {$created} | Zaktualizowano: {$updated} | Błędy: {$errors}"
        );
    }

    // ─── Pomocnicze ──────────────────────────────────────────────────────────

    private function findOrCreatePost(string $title, string $content): int
    {
        global $wpdb;

        // Szukaj po tytule (unikamy get_page_by_title — deprecated od WP 6.2)
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_title = %s AND post_type = 'osrodek_pomocy'
                 LIMIT 1",
                $title
            )
        );

        if ($existing) {
            wp_update_post([
                'ID'           => (int) $existing,
                'post_content' => wp_kses_post($content),
                'post_status'  => 'publish',
            ]);
            return (int) $existing;
        }

        $postId = wp_insert_post([
            'post_title'   => sanitize_text_field($title),
            'post_content' => wp_kses_post($content),
            'post_type'    => 'osrodek_pomocy',
            'post_status'  => 'publish',
        ], true);

        if (is_wp_error($postId)) {
            error_log('[ImportPsychomapyCommand] wp_insert_post error: ' . $postId->get_error_message());
            return 0;
        }

        return $postId;
    }

    /**
     * Usuwa polskie prefiksy ulic — Nominatim ich nie rozumie.
     * "ul. Marszałkowska" → "Marszałkowska"
     */
    private static function normalizeStreet(string $street): string
    {
        return trim(preg_replace(
            '/^(ul\.|ulica|al\.|aleja|aleje|os\.|osiedle|pl\.|plac|skwer|rondo|bulwar|droga|trakt|szosa)\s+/iu',
            '',
            trim($street)
        ));
    }

    /** Zapisuje podstawowe meta wg META_MAP. */
    private function saveMeta(int $postId, array $data, array $colIndex): void
    {
        foreach (self::META_MAP as $csvCol => $metaKey) {
            $value = $data[$csvCol] ?? '';
            update_post_meta($postId, $metaKey, sanitize_text_field($value));
        }
    }

    /** Parsuje przecinkami rozdzielony string na termsy i przypisuje do taksonomii. */
    private function saveTerms(int $postId, string $raw, string $taxonomy): void
    {
        if ($raw === '') {
            return;
        }

        $terms = array_values(
            array_filter(
                array_map('trim', explode(',', $raw))
            )
        );

        if (empty($terms)) {
            return;
        }

        $result = wp_set_object_terms($postId, $terms, $taxonomy);
        if (is_wp_error($result)) {
            error_log("[ImportPsychomapyCommand] wp_set_object_terms error ({$taxonomy}): " . $result->get_error_message());
        }
    }

    /** Przepisuje logo URL i zapisuje w meta. */
    private function saveLogo(int $postId, string $logoUrl): void
    {
        if ($logoUrl === '') {
            return;
        }

        $rewritten = str_replace(
            'https://niepodzielni.com',
            'https://media.niepodzielni.com',
            $logoUrl
        );

        update_post_meta($postId, 'np_logo_url', esc_url_raw($rewritten));
    }

    /** Zapisuje godziny otwarcia dla każdego dnia tygodnia. */
    private function saveHours(int $postId, array $data): void
    {
        foreach (self::DAYS as $prefix => $csvPrefix) {
            $open  = $data["{$csvPrefix}_godzina_otwarcia"]  ?? '';
            $close = $data["{$csvPrefix}_godzina_zamkniecia"] ?? '';

            update_post_meta($postId, "{$prefix}_otwarcie",   sanitize_text_field($open));
            update_post_meta($postId, "{$prefix}_zamkniecie", sanitize_text_field($close));

            // Oznacz jako zamknięte gdy brak godzin i nie ma "całodobowe"
            $closed = ($open === '' && $close === '' && stripos($open . $close, 'całodobowe') === false)
                ? 'tak'
                : '';
            update_post_meta($postId, "{$prefix}_zamkniete", $closed);
        }
    }

    /**
     * Geocoduje adres jeśli lat/lng są puste lub adres się zmienił.
     * Wymusza sleep(1) między wywołaniami (polityka Nominatim).
     */
    private function maybeGeocode(int $postId, array $data, int $rowNum): void
    {
        $ulica       = $data['ulica']       ?? '';
        $nrDomu      = $data['nr_domu']     ?? '';
        $kodPocztowy = $data['kod_pocztowy']?? '';
        $miasto      = $data['miasto']      ?? '';

        $street  = trim(self::normalizeStreet($ulica) . ' ' . $nrDomu);
        $city    = trim("{$kodPocztowy} {$miasto}");
        $parts   = array_filter([$street, $city, 'Polska']);
        $address = implode(', ', $parts);

        if ($address === 'Polska') {
            return;
        }

        $newHash = md5($address);
        $oldHash = (string) get_post_meta($postId, '_np_address_hash', true);
        $lat     = (string) get_post_meta($postId, 'lat', true);
        $lng     = (string) get_post_meta($postId, 'lng', true);

        if ($newHash === $oldHash && $lat !== '' && $lng !== '') {
            return; // adres niezmieniony, współrzędne aktualne
        }

        \WP_CLI::line("    → Geokodowanie: {$address}");

        $coords = $this->geocoder->geocodeAddress($address);

        if ($coords !== null) {
            update_post_meta($postId, 'lat', $coords['lat']);
            update_post_meta($postId, 'lng', $coords['lng']);
            \WP_CLI::line("    ✓ {$coords['lat']}, {$coords['lng']}");
        } else {
            \WP_CLI::warning("    Brak wyników Nominatim dla wiersza {$rowNum}: {$address}");
        }

        update_post_meta($postId, '_np_address_hash', $newHash);

        sleep(1); // Nominatim: max 1 request/sec
    }
}
