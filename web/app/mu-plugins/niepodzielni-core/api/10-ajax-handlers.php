<?php

/**
 * AJAX Handlers — obsługa zapytań AJAX (frontend i admin)
 */

if (! defined('ABSPATH')) {
    exit;
}

// ─── Whitelist walidacja parametru $typ ──────────────────────────────────────

/**
 * Sprawdza czy $typ należy do dozwolonych wartości.
 * Wywołuj we wszystkich handlerach przyjmujących $typ z zewnątrz.
 */
function np_bookero_is_valid_typ(string $typ): bool
{
    return in_array($typ, [ 'pelnoplatny', 'nisko' ], true);
}

// ─── Real-time ingest: przechwycony getMonth z bookero-init.js ───────────────
// Gdy calendar widget ładuje miesiąc, JS interceptor wzywa tę akcję
// z najbliższą dostępną datą — zero dodatkowych requestów do Bookero.

add_action('wp_ajax_bk_ingest_month', 'np_ajax_bk_ingest_month');
add_action('wp_ajax_nopriv_bk_ingest_month', 'np_ajax_bk_ingest_month');

function np_ajax_bk_ingest_month(): void
{
    check_ajax_referer('np_bookero_nonce', 'nonce');

    $worker_bk_id = sanitize_text_field($_POST['worker_bk_id'] ?? '');
    $cal_hash     = sanitize_text_field($_POST['cal_hash']     ?? '');
    $nearest_date = sanitize_text_field($_POST['nearest_date'] ?? '');

    if (! $worker_bk_id || ! $cal_hash || ! $nearest_date) {
        wp_send_json_success([ 'skipped' => true ]);
    }

    // Walidacja formatu daty (YYYY-MM-DD) — zapobiega zapisaniu śmieciowych wartości
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $nearest_date)) {
        wp_send_json_success([ 'skipped' => 'invalid_date_format' ]);
    }

    $ts = strtotime($nearest_date);

    // Odrzuć daty przeszłe — JS może przesyłać nieaktualne dane po odświeżeniu strony
    if (! $ts || $ts < strtotime('today')) {
        wp_send_json_success([ 'skipped' => 'date_in_past' ]);
    }

    // Ustal typ konta (pelnoplatny / nisko) na podstawie hasha kalendarza
    $cal_id_nisko = np_bookero_cal_id_for('nisko');
    $typ          = ($cal_hash === $cal_id_nisko) ? 'nisko' : 'pelnoplatny';
    $meta_key     = ($typ === 'nisko') ? 'najblizszy_termin_niskoplatny' : 'najblizszy_termin_pelnoplatny';
    $worker_meta  = ($typ === 'nisko') ? 'bookero_id_niski' : 'bookero_id_pelny';

    // Znajdź psychologa po worker ID
    $posts = get_posts([
        'post_type'      => 'psycholog',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'meta_query'     => [
            [ 'key' => $worker_meta, 'value' => $worker_bk_id, 'compare' => '=' ],
        ],
        'fields' => 'ids',
    ]);

    if (empty($posts)) {
        wp_send_json_success([ 'skipped' => 'worker_not_found' ]);
    }

    $post_id = (int) $posts[0];

    // $ts jest już obliczony i zwalidowany powyżej
    $label = date_i18n('j F Y', $ts);

    update_post_meta($post_id, $meta_key, $label);
    update_post_meta($post_id, 'np_termin_updated_at', time());
    update_option('np_bookero_last_cron_run', time(), false);

    wp_send_json_success([ 'saved' => $label, 'post_id' => $post_id ]);
}

// ─── Frontend: pobieranie terminów dla widgetów kalendarza ────────────────────

add_action('wp_ajax_np_get_terminy', 'np_ajax_get_terminy');
add_action('wp_ajax_nopriv_np_get_terminy', 'np_ajax_get_terminy');

function np_ajax_get_terminy(): void
{
    // Brak nonce — endpoint read-only serwowany z page-cached stron.
    // Nonce z cachedowanych stron wygasają natychmiast, blokując użytkowników.

    $bookero_id = sanitize_text_field($_POST['bookero_id'] ?? '');
    $typ        = sanitize_key($_POST['typ'] ?? 'pelnoplatny');

    if (! $bookero_id) {
        wp_send_json_error([ 'message' => 'Brak bookero_id' ]);
    }

    if (! np_bookero_is_valid_typ($typ)) {
        wp_send_json_error([ 'message' => 'Nieprawidłowy typ' ]);
    }

    $client = new \Niepodzielni\Bookero\BookeroApiClient();
    $repo   = new \Niepodzielni\Bookero\PsychologistRepository();

    // L1: transient cache (ten sam klucz co stara np_bookero_get_terminy)
    $cached = $repo->getMonthTransient($typ, $bookero_id, 0);
    if ($cached !== false) {
        wp_send_json_success($cached);
    }

    $cal_hash = np_bookero_cal_id_for($typ);
    if (! $cal_hash) {
        wp_send_json_success([]);
    }

    // Account config — z transientu lub API (/init)
    $service_id = 0;
    $cfg_arr    = $repo->getAccountConfigTransient($typ);
    if ($cfg_arr !== false) {
        $service_id = $cfg_arr['service_id'];
    } else {
        try {
            $cfg = $client->getAccountConfig($cal_hash);
            $repo->setAccountConfigTransient($typ, $cfg);
            $service_id = $cfg->serviceId;
        } catch (\Niepodzielni\Bookero\BookeroApiException $e) {
            np_bookero_log_error('get_terminy/config', [ 'worker' => $bookero_id, 'msg' => $e->getMessage() ]);
        }
    }

    // Pobierz dostępne dni z API Bookero
    try {
        $slots = $client->getMonth($cal_hash, $bookero_id, $service_id, 0);
        $repo->setMonthTransient($typ, $bookero_id, 0, $slots);
        wp_send_json_success($slots);
    } catch (\Niepodzielni\Bookero\BookeroRateLimitException $e) {
        np_bookero_log_error('get_terminy/rate-limit', [ 'worker' => $bookero_id, 'msg' => $e->getMessage() ]);
        $repo->setMonthTransientBackoff($typ, $bookero_id, 0);
        wp_send_json_success([]);
    } catch (\Niepodzielni\Bookero\BookeroApiException $e) {
        np_bookero_log_error('get_terminy/api-error', [ 'worker' => $bookero_id, 'msg' => $e->getMessage() ]);
        $repo->setMonthTransientBackoff($typ, $bookero_id, 0);
        wp_send_json_success([]);
    }
}

// ─── Admin: inicjator synchronizacji wsadowej (Batching) ─────────────────────
// Zamiast synchronizować wszystkich naraz (bomba API), endpoint zwraca listę
// post_ids. JS na stronie ustawień wywołuje np_refresh_termin_single dla każdego
// ID z osobna, z małym opóźnieniem i paskiem postępu.

add_action('wp_ajax_np_refresh_terminy', 'np_ajax_refresh_terminy');

function np_ajax_refresh_terminy(): void
{
    check_ajax_referer('np_bookero_nonce', 'nonce');

    if (! current_user_can('manage_options')) {
        wp_send_json_error([ 'message' => 'Brak uprawnień' ]);
    }

    // Zwróć wyłącznie psychologów z przypisanym Bookero ID — reszta nie wymaga sync.
    $post_ids = get_posts([
        'post_type'      => 'psycholog',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => 'bookero_id_pelny', 'value' => '', 'compare' => '!=' ],
            [ 'key' => 'bookero_id_niski',  'value' => '', 'compare' => '!=' ],
        ],
    ]);

    wp_send_json_success([ 'post_ids' => array_map('intval', $post_ids) ]);
}

// ─── Admin: odświeżenie terminów dla jednego psychologa ───────────────────────

add_action('wp_ajax_np_refresh_termin_single', 'np_ajax_refresh_termin_single');

function np_ajax_refresh_termin_single(): void
{
    check_ajax_referer('np_bookero_nonce', 'nonce');

    if (! current_user_can('manage_options')) {
        wp_send_json_error([ 'message' => 'Brak uprawnień' ]);
    }

    $post_id = (int) ($_POST['post_id'] ?? 0);
    if (! $post_id) {
        wp_send_json_error([ 'message' => 'Brak post_id' ]);
    }

    // BookeroSyncService: czyści transienty, odpytuje API, zapisuje metadane i timestamp.
    // Zastępuje ręczne pętle delete_transient + wywołania np_bookero_najblizszy_termin.
    $client = new \Niepodzielni\Bookero\BookeroApiClient();
    $repo   = new \Niepodzielni\Bookero\PsychologistRepository();
    $sync   = new \Niepodzielni\Bookero\BookeroSyncService($client, $repo);

    try {
        $result = $sync->syncSingleWorker($post_id);
    } catch (\Niepodzielni\Bookero\BookeroRateLimitException $e) {
        np_bookero_log_error('refresh_termin_single rate-limit', [ 'post_id' => $post_id, 'msg' => $e->getMessage() ]);
        wp_send_json_error([ 'message' => 'Bookero API: limit zapytań. Spróbuj ponownie za chwilę.', 'rate_limit' => true ]);
    } catch (\Niepodzielni\Bookero\BookeroApiException $e) {
        np_bookero_log_error('refresh_termin_single api-error', [ 'post_id' => $post_id, 'msg' => $e->getMessage() ]);
        wp_send_json_error([ 'message' => 'Bookero API: ' . $e->getMessage() ]);
    }

    // Odczytaj świeżo zapisane etykiety z repo (w WP object cache po syncSingleWorker)
    $termin_pelny = $result->hasPelny ? $result->nearestPelny : '';
    $termin_niski = $result->hasNisko ? $result->nearestNisko : '';

    $now = time();

    // Ostrzeżenie gdy worker ID istnieje, ale sync nie znalazł żadnego terminu
    $api_warning = '';
    if ($result->hasPelny && $termin_pelny === '') {
        $api_warning .= 'Pełny: brak wolnych terminów. ';
    }
    if ($result->hasNisko && $termin_niski === '') {
        $api_warning .= 'Niski: brak wolnych terminów.';
    }

    if (! $result->hasSynced()) {
        wp_send_json_error([ 'message' => 'Psycholog nie ma przypisanego Bookero ID.' ]);
    }

    wp_send_json_success([
        'message'      => $api_warning ? trim($api_warning) : 'Terminy zaktualizowane',
        'termin_pelny' => $termin_pelny ?: '—',
        'termin_niski' => $termin_niski ?: '—',
        'updated_at'   => date_i18n('d.m.Y H:i', $now),
        'warning'      => (bool) $api_warning,
    ]);
}

// ─── Shared Calendar: inwalidacja Object Cache listy psychologów ─────────────

/**
 * Czyści WP Object Cache (Redis) listy psychologów po zapisaniu posta.
 * Wywoływane przez save_post_psycholog i niepodzielni_bookero_batch_synced.
 *
 * Bez tej inwalidacji stara lista byłaby serwowana przez max WORKERS_CACHE_TTL (7 min)
 * nawet po dodaniu nowego psychologa lub zmianie jego worker ID.
 */
function np_bookero_invalidate_workers_cache(): void
{
    $repo = new \Niepodzielni\Bookero\PsychologistRepository();
    $repo->invalidateWorkersCache('nisko');
    $repo->invalidateWorkersCache('pelnoplatny');
}

add_action('save_post_psycholog',              'np_bookero_invalidate_workers_cache');
add_action('niepodzielni_bookero_batch_synced', 'np_bookero_invalidate_workers_cache');

// ─── Shared Calendar: fabryka serwisu ────────────────────────────────────────

/**
 * Singleton-fabryka SharedCalendarService.
 * Tworzy instancję raz na żądanie HTTP i zwraca ją przy kolejnych wywołaniach.
 * Dzięki temu WP_Query (batch meta load) jest wykonywane maksymalnie raz na request.
 */
function np_bookero_shared_calendar_service(): \Niepodzielni\Bookero\SharedCalendarService
{
    static $service = null;

    if ($service === null) {
        $client  = new \Niepodzielni\Bookero\BookeroApiClient();
        $repo    = new \Niepodzielni\Bookero\PsychologistRepository();
        $sync    = new \Niepodzielni\Bookero\BookeroSyncService($client, $repo);
        $service = new \Niepodzielni\Bookero\SharedCalendarService($repo, $client, $sync);
    }

    return $service;
}

// ─── Shared Calendar: dane miesięczne ────────────────────────────────────────

add_action('wp_ajax_bk_get_shared_month', 'np_ajax_bk_get_shared_month');
add_action('wp_ajax_nopriv_bk_get_shared_month', 'np_ajax_bk_get_shared_month');

function np_ajax_bk_get_shared_month(): void
{
    // Brak nonce — endpoint read-only, wywoływany z page-cached stron kalendarza.
    // Nonce osadzony w cachedowanej stronie wygasa w ciągu minut i blokuje kalendarz.
    // Ochrona przez: whitelist $typ + sanitację wejścia + transient cache po stronie serwera.

    $typ         = sanitize_text_field($_POST['typ'] ?? 'nisko');
    $plus_months = max(0, min(12, (int) ($_POST['plus_months'] ?? 0)));

    if (! np_bookero_is_valid_typ($typ)) {
        wp_send_json_error([ 'message' => 'Nieprawidłowy typ' ]);
    }

    wp_send_json_success(np_bookero_shared_calendar_service()->buildMonthData($typ, $plus_months));
}

/**
 * @deprecated  Pozostawiona dla kompatybilności wstecznej — używaj SharedCalendarService::buildMonthData().
 *              Wewnętrznie deleguje do nowego serwisu (transient cache jest wspólny).
 */
function np_bk_build_month_data(string $typ, int $plus_months): array
{
    return np_bookero_shared_calendar_service()->buildMonthData($typ, $plus_months);
}

// ─── Shared Calendar: sloty dla konkretnego dnia ─────────────────────────────

add_action('wp_ajax_bk_get_date_slots', 'np_ajax_bk_get_date_slots');
add_action('wp_ajax_nopriv_bk_get_date_slots', 'np_ajax_bk_get_date_slots');

function np_ajax_bk_get_date_slots(): void
{
    // Brak nonce — endpoint read-only (pobieranie godzin przy kliknięciu dnia).
    // Format daty i typ walidowane poniżej.

    $typ  = sanitize_text_field($_POST['typ'] ?? 'nisko');
    $date = sanitize_text_field($_POST['date'] ?? '');

    if (! np_bookero_is_valid_typ($typ)) {
        wp_send_json_error([ 'message' => 'Nieprawidłowy typ' ]);
    }

    if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        wp_send_json_error([ 'message' => 'Nieprawidłowa data' ]);
    }

    wp_send_json_success(np_bookero_shared_calendar_service()->getDateSlots($typ, $date));
}

// ─── Shared Calendar: weryfikacja dostępności godziny ─────────────────────────

add_action('wp_ajax_bk_verify_hour', 'np_ajax_bk_verify_hour');
add_action('wp_ajax_nopriv_bk_verify_hour', 'np_ajax_bk_verify_hour');

function np_ajax_bk_verify_hour(): void
{
    // Nonce zachowany — endpoint inicjuje N zewnętrznych wywołań API Bookero
    // (jedno na każdy bookero_id). Nonce chroni przed spam-flood z zewnątrz.
    // Wywoływany z dynamicznej strony potwierdzenia rezerwacji (nie z page cache).
    check_ajax_referer('np_bookero_nonce', 'nonce');

    $date        = sanitize_text_field($_POST['date'] ?? '');
    $hour        = sanitize_text_field($_POST['hour'] ?? '');
    $typ         = sanitize_text_field($_POST['typ'] ?? 'pelnoplatny');
    $bookero_ids = array_map('sanitize_text_field', (array) ($_POST['bookero_ids'] ?? []));

    if (! np_bookero_is_valid_typ($typ)) {
        wp_send_json_error([ 'message' => 'Nieprawidłowy typ' ]);
    }

    if (! $date || ! $hour || empty($bookero_ids)) {
        wp_send_json_success([ 'removed' => [] ]);
    }

    $removed = [];

    // Batch lookup: worker_id → post_id (1 zapytanie zamiast N)
    // $meta_bk_key pochodzi z whitelisty $typ — ale całe zapytanie chroni $wpdb->prepare().
    $meta_bk_key  = ($typ === 'nisko') ? 'bookero_id_niski' : 'bookero_id_pelny';
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($bookero_ids), '%s'));
    $rows         = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value IN ({$placeholders})",
            $meta_bk_key,
            ...$bookero_ids,
        ),
    );
    $worker_to_post = [];
    foreach ($rows as $row) {
        $worker_to_post[ $row->meta_value ] = (int) $row->post_id;
    }

    // Inicjalizacja warstwy OOP — BookeroApiClient + PsychologistRepository
    $client     = new \Niepodzielni\Bookero\BookeroApiClient();
    $repo       = new \Niepodzielni\Bookero\PsychologistRepository();
    $cal_hash   = np_bookero_cal_id_for($typ);
    $service_id = 0;

    $cfg_arr = $repo->getAccountConfigTransient($typ);
    if ($cfg_arr !== false) {
        $service_id = $cfg_arr['service_id'];
    } elseif ($cal_hash) {
        try {
            $cfg = $client->getAccountConfig($cal_hash);
            $repo->setAccountConfigTransient($typ, $cfg);
            $service_id = $cfg->serviceId;
        } catch (\Niepodzielni\Bookero\BookeroApiException $e) {
            np_bookero_log_error('bk_verify_hour/config', [ 'msg' => $e->getMessage() ]);
        }
    }

    // Micro-Cache: jeśli transient ma < 60 s → nie uderzamy ponownie w API Bookero.
    // Zapobiega kaskadowym requestom przy równoczesnych rezerwacjach + chroni przed
    // błędami rate-limit. Dane z bazy (repo->saveHours) służą jako fallback
    // dla workerów, dla których pętla zakończyła się wyjątkiem.
    $api_error_occurred = false;

    foreach ($bookero_ids as $worker_id) {
        $hash      = md5($typ . $worker_id . $date);
        $cache_key = 'np_bkday_' . $hash;
        $ts_key    = 'np_bkday_ts_' . $hash;

        $last_fetch = (int) get_transient($ts_key);
        $is_fresh   = $last_fetch && (time() - $last_fetch) < 60;

        if ($is_fresh) {
            // Dane wystarczająco świeże — czytamy z istniejącego transienta
            $hours = (array) get_transient($cache_key);
        } else {
            // Transient wygasł lub za stary — odpytujemy Bookero API (OOP)
            try {
                $hours = $client->getMonthDay($cal_hash, $worker_id, $date, $service_id);
                set_transient($cache_key, $hours, 5 * MINUTE_IN_SECONDS); // dla micro-cache odczytu
                set_transient($ts_key, time(), 300);                       // znacznik świeżości

                // Zaktualizuj DB świeżymi danymi z API
                $post_id = $worker_to_post[ $worker_id ] ?? 0;
                if ($post_id) {
                    $repo->saveHours($post_id, $typ, $date, $hours);
                }
            } catch (\Niepodzielni\Bookero\BookeroRateLimitException $e) {
                np_bookero_log_error('bk_verify_hour/rate-limit', [ 'worker' => $worker_id, 'msg' => $e->getMessage() ]);
                $api_error_occurred = true;
                break; // Graceful degradation: pozostałe workery zatwierdzamy na starych danych
            } catch (\Niepodzielni\Bookero\BookeroApiException $e) {
                np_bookero_log_error('bk_verify_hour/api-error', [ 'worker' => $worker_id, 'msg' => $e->getMessage() ]);
                $api_error_occurred = true;
                break; // j.w.
            }
        }

        if (! in_array($hour, $hours, true)) {
            $removed[] = $worker_id;
        }
    }

    wp_send_json_success([ 'removed' => $removed ]);
}

// ─── Shared Calendar: tworzenie rezerwacji ────────────────────────────────────

add_action('wp_ajax_bk_create_booking', 'np_ajax_bk_create_booking');
add_action('wp_ajax_nopriv_bk_create_booking', 'np_ajax_bk_create_booking');

function np_ajax_bk_create_booking(): void
{
    check_ajax_referer('np_bookero_nonce', 'nonce');

    $cal_hash  = sanitize_text_field($_POST['cal_hash'] ?? '');
    $worker_id = sanitize_text_field($_POST['worker']   ?? '');
    $service   = (int) ($_POST['service'] ?? 0);
    $date      = sanitize_text_field($_POST['date']     ?? '');
    $hour      = sanitize_text_field($_POST['hour']     ?? '');
    $name      = sanitize_text_field($_POST['name']     ?? '');
    $email     = sanitize_email($_POST['email']         ?? '');
    $phone     = sanitize_text_field($_POST['phone']    ?? '');
    $ulica     = sanitize_text_field($_POST['ulica']    ?? '');
    $nr_domu   = sanitize_text_field($_POST['nr_domu']  ?? '');
    $kod       = sanitize_text_field($_POST['kod_poczt'] ?? '');
    $miasto    = sanitize_text_field($_POST['miasto']   ?? '');
    $powod     = sanitize_textarea_field($_POST['powod'] ?? '');
    $zaimki    = sanitize_text_field($_POST['zaimki']   ?? '');
    $agree_18  = ! empty($_POST['agree_18']);
    $agree_tel = ! empty($_POST['agree_tel']);

    if (! $cal_hash || ! $worker_id || ! $date || ! $hour || ! $name || ! $email || ! $phone) {
        wp_send_json_error('Proszę wypełnić wszystkie wymagane pola.');
    }

    // Ustal typ konta na podstawie cal_hash
    $cal_id_nisko = np_bookero_cal_id_for('nisko');
    $typ          = ($cal_hash === $cal_id_nisko) ? 'nisko' : 'pelnoplatny';

    // Account config — warstwa OOP (BookeroApiClient + PsychologistRepository)
    $client  = new \Niepodzielni\Bookero\BookeroApiClient();
    $repo    = new \Niepodzielni\Bookero\PsychologistRepository();
    $cfg_arr = $repo->getAccountConfigTransient($typ);

    if ($cfg_arr !== false) {
        $account_cfg = \Niepodzielni\Bookero\AccountConfig::fromArray($cfg_arr);
    } else {
        try {
            $account_cfg = $client->getAccountConfig($cal_hash);
            $repo->setAccountConfigTransient($typ, $account_cfg);
        } catch (\Niepodzielni\Bookero\BookeroApiException $e) {
            np_bookero_log_error('create_booking/config', [ 'msg' => $e->getMessage() ]);
            $account_cfg = \Niepodzielni\Bookero\AccountConfig::empty();
        }
    }

    $service_id   = $service ?: $account_cfg->serviceId;
    $payment_id   = $account_cfg->paymentId;
    $service_name = $account_cfg->serviceName ?: 'Konsultacja psychologiczna';

    // Zbuduj plugin_comment z parametrami formularza (stałe IDs dla konta Bookero Niepodzielni)
    $parameters = [];
    if ($powod) {
        $parameters[] = [ 'id' => '15663', 'value' => $powod, 'value_id' => null ];
    }
    if ($agree_18) {
        $parameters[] = [ 'id' => '16483', 'value' => [ 'Oświadczam, że mam ukończone 18 lat.' ], 'value_id' => [ 17856 ] ];
    }
    if ($ulica) {
        $parameters[] = [ 'id' => '16488', 'value' => $ulica, 'value_id' => null ];
    }
    if ($nr_domu) {
        $parameters[] = [ 'id' => '16489', 'value' => $nr_domu, 'value_id' => null ];
    }
    if ($kod) {
        $parameters[] = [ 'id' => '16490', 'value' => $kod, 'value_id' => null ];
    }
    if ($miasto) {
        $parameters[] = [ 'id' => '16492', 'value' => $miasto, 'value_id' => null ];
    }
    if ($zaimki) {
        $parameters[] = [ 'id' => '20215', 'value' => $zaimki, 'value_id' => null ];
    }

    $plugin_comment = wp_json_encode([
        'data' => [
            'name'            => $name,
            'phone'           => $phone,
            'parameters'      => $parameters,
            'services_names'  => [ [ 'category' => '', 'value' => $service_name, 'require_people' => 0 ] ],
            'services_values' => [ [ 'category' => 0, 'value' => $service_id, 'require_people' => 0 ] ],
        ],
    ]);

    $payload = [
        'bookero_id'               => $cal_hash,
        'agree_tp'                 => 1,
        'agree_newsletter'         => 0,
        'agree_telemarketing'      => $agree_tel ? 1 : 0,
        'discount_code'            => '',
        'configuration_payment_id' => $payment_id,
        'lang'                     => 'pl',
        'cart_id'                  => 0,
        'name'                     => $name,
        'phone'                    => $phone,
        'email'                    => $email,
        'inquiries'                => [
            [
                'plugin_comment'     => $plugin_comment,
                'phone'              => $phone,
                'people'             => 1,
                'date'               => $date,
                'hour'               => $hour,
                'email'              => $email,
                'service'            => $service_id,
                'worker'             => $worker_id,
                'periodicity_id'     => 0,
                'custom_duration_id' => 0,
                'plugin_inquiry_id'  => null,
            ],
        ],
    ];

    // Deleguj do BookeroApiClient::createBooking() — timeout 8s, obsługa błędów i logowanie
    // w jednym miejscu (BookeroApiClient::post() + parseResponse()).
    // $client zainicjalizowany wyżej przy pobieraniu konfiguracji konta.
    try {
        wp_send_json_success($client->createBooking($cal_hash, $payload));
    } catch (\Niepodzielni\Bookero\BookeroApiException $e) {
        $err_msg = $e->getMessage();
        np_bookero_log_error('add', "worker={$worker_id} date={$date} hour={$hour}: {$err_msg}");

        // cURL error 28 = timeout — komunikat odróżniony od ogólnego błędu sieci.
        $is_timeout = str_contains($err_msg, 'timed out')
                   || str_contains($err_msg, 'error 28')
                   || str_contains($err_msg, 'Operation timed out');

        if ($is_timeout) {
            wp_send_json_error('System rezerwacji jest obecnie przeciążony. Odczekaj chwilę i spróbuj ponownie.');
        }

        // Wiadomość z API (format wyjątku: "[Bookero:add] result=N, message=<msg>")
        if (preg_match('/,\s*message=(.+)$/', $err_msg, $m) && $m[1] !== '—') {
            wp_send_json_error($m[1]);
        }

        wp_send_json_error('Błąd połączenia z Bookero. Spróbuj ponownie.');
    }
}
