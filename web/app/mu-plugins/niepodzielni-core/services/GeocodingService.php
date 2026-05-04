<?php

declare(strict_types=1);

namespace Niepodzielni\Psychomapa;

/**
 * Serwis geokodowania adresów przez Nominatim (OpenStreetMap).
 *
 * Używany przez:
 *  - hook carbon_fields_post_meta_saved (automatyczny zapis z panelu admina)
 *  - ImportPsychomapyCommand (WP-CLI batch import)
 *
 * Polityki Nominatim:
 *  - Nagłówek User-Agent jest obowiązkowy (blokują requestów bez UA).
 *  - Max 1 request/sekundę — WP-CLI wymusza sleep(1) między wpisami.
 *  - Hook admina geocoduje 1 wpis naraz, limit nie jest problemem.
 *
 * Cache: object cache (Redis/Memcached gdy dostępny, fallback do WP transients).
 * Klucz: md5($address) w grupie 'np_geocoding'.
 */
class GeocodingService
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const USER_AGENT    = 'Psychomapa-Niepodzielni/1.0 (info@niepodzielni.com)';
    private const CACHE_GROUP   = 'np_geocoding';
    private const CACHE_TTL     = 30 * DAY_IN_SECONDS;
    private const POST_TYPE     = 'osrodek_pomocy';

    // ─── API publiczne ────────────────────────────────────────────────────────

    /**
     * Geokoduje adres i zwraca współrzędne GPS lub null gdy brak wyniku.
     *
     * @param  string  $address  Pełny adres (np. "ul. Marszałkowska 10, 00-629 Warszawa")
     * @return array{lat: float, lng: float}|null
     */
    public function geocodeAddress(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $cacheKey = md5($address);

        $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return is_array($cached) ? $cached : null; // false = brak wyniku (też cachujemy)
        }

        $response = wp_remote_get(
            add_query_arg([
                'q'            => $address,
                'format'       => 'json',
                'limit'        => 1,
                'countrycodes' => 'pl',
            ], self::NOMINATIM_URL),
            [
                'timeout'    => 10,
                'user-agent' => self::USER_AGENT,
                'headers'    => ['Accept-Language' => 'pl'],
            ]
        );

        if (is_wp_error($response)) {
            error_log('[GeocodingService] HTTP error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log("[GeocodingService] Nominatim returned HTTP {$code} for: {$address}");
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (! is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
            error_log("[GeocodingService] Brak wyników Nominatim dla: {$address}");
            // Cachuj null (wartość 0) żeby nie bić w API ponownie przy ponownym zapisie
            wp_cache_set($cacheKey, 0, self::CACHE_GROUP, self::CACHE_TTL);
            return null;
        }

        $result = [
            'lat' => (float) $data[0]['lat'],
            'lng' => (float) $data[0]['lon'],
        ];

        wp_cache_set($cacheKey, $result, self::CACHE_GROUP, self::CACHE_TTL);

        return $result;
    }

    /**
     * Rejestruje hook WordPress do automatycznego geokodowania przy zapisie CF.
     * Wywoływane raz przy ładowaniu pluginu.
     */
    public function registerHooks(): void
    {
        add_action('carbon_fields_post_meta_saved', [$this, 'maybeGeocode']);
    }

    /**
     * Callback hooka carbon_fields_post_meta_saved.
     * Porównuje hash adresu — wywołuje Nominatim tylko gdy adres się zmienił.
     */
    public function maybeGeocode(int $postId): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($postId)) {
            return;
        }

        if (get_post_type($postId) !== self::POST_TYPE) {
            return;
        }

        $ulica        = (string) carbon_get_post_meta($postId, 'np_ulica');
        $nrDomu       = (string) carbon_get_post_meta($postId, 'np_nr_domu');
        $kodPocztowy  = (string) carbon_get_post_meta($postId, 'np_kod_pocztowy');
        $miasto       = (string) carbon_get_post_meta($postId, 'np_miasto');

        $address = $this->buildAddress($ulica, $nrDomu, $kodPocztowy, $miasto);

        if ($address === '') {
            return;
        }

        $newHash = md5($address);
        $oldHash = (string) get_post_meta($postId, '_np_address_hash', true);
        $lat     = (string) get_post_meta($postId, 'lat', true);
        $lng     = (string) get_post_meta($postId, 'lng', true);

        // Adres niezmieniony i współrzędne istnieją — nie obciążamy Nominatim
        if ($newHash === $oldHash && $lat !== '' && $lng !== '') {
            return;
        }

        $coords = $this->geocodeAddress($address);

        if ($coords !== null) {
            update_post_meta($postId, 'lat', $coords['lat']);
            update_post_meta($postId, 'lng', $coords['lng']);
        } else {
            error_log("[GeocodingService] Nie udało się geokodować ośrodka ID={$postId}: {$address}");
        }

        // Aktualizuj hash zawsze — nawet przy braku wyniku (zapobiega ciągłym próbom)
        update_post_meta($postId, '_np_address_hash', $newHash);
    }

    // ─── Pomocnicze ──────────────────────────────────────────────────────────

    /**
     * Buduje string adresowy do geokodowania.
     * Pomija puste segmenty, dołącza "Polska" dla lepszej precyzji Nominatim.
     * Usuwa polskie prefiksy ulic (ul., al., os., pl.) — Nominatim ich nie rozumie.
     */
    private function buildAddress(
        string $ulica,
        string $nrDomu,
        string $kodPocztowy,
        string $miasto,
    ): string {
        $street = trim(self::normalizeStreet($ulica) . ' ' . trim($nrDomu));
        $city   = trim(trim($kodPocztowy) . ' ' . trim($miasto));

        $parts = array_filter([$street, $city, 'Polska']);

        return implode(', ', $parts);
    }

    /**
     * Usuwa typowe polskie prefiksy ulic przed wysłaniem do Nominatim.
     * np. "ul. Marszałkowska" → "Marszałkowska", "al. Jana Pawła II" → "Jana Pawła II"
     */
    private static function normalizeStreet(string $street): string
    {
        return trim(preg_replace(
            '/^(ul\.|ulica|al\.|aleja|aleje|os\.|osiedle|pl\.|plac|skwer|rondo|bulwar|droga|trakt|szosa)\s+/iu',
            '',
            trim($street)
        ));
    }
}
