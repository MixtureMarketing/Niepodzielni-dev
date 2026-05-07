<?php

/**
 * AJAX Handlers — obsługa zapytań AJAX (frontend i admin).
 *
 * Wszystkie endpointy zarejestrowane przez np_ajax_endpoint() (api/0-ajax-endpoint-wrapper.php).
 * Wrapper obsługuje nonce, capability, JSON envelope; handler skupia się na logice.
 *
 * Niektóre endpointy nadal wywołują wp_send_json_* ręcznie — w try/catch z różnymi
 * komunikatami / kodami błędu (np. bk_create_booking ma timeout-specific message).
 * Wrapper to akceptuje: gdy handler odpowiedział sam, kontynuacja w wrapper się nie zdarzy.
 */

if (! defined('ABSPATH')) {
    exit;
}

// ─── Real-time ingest: przechwycony getMonth z bookero-init.js ───────────────
// Gdy calendar widget ładuje miesiąc, JS interceptor wzywa tę akcję
// z najbliższą dostępną datą — zero dodatkowych requestów do Bookero.

np_ajax_endpoint('bk_ingest_month', [
    'public'       => true,
    'nonce_action' => 'np_bookero_nonce',
], function (array $req): array {
    $worker_bk_id = sanitize_text_field($req['worker_bk_id'] ?? '');
    $cal_hash     = sanitize_text_field($req['cal_hash']     ?? '');
    $nearest_date = sanitize_text_field($req['nearest_date'] ?? '');

    if (! $worker_bk_id || ! $cal_hash || ! $nearest_date) {
        return ['skipped' => true];
    }

    // Walidacja formatu daty (YYYY-MM-DD) — zapobiega zapisaniu śmieciowych wartości
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $nearest_date)) {
        return ['skipped' => 'invalid_date_format'];
    }

    $ts = strtotime($nearest_date);

    // Odrzuć daty przeszłe — JS może przesyłać nieaktualne dane po odświeżeniu strony
    if (! $ts || $ts < strtotime('today')) {
        return ['skipped' => 'date_in_past'];
    }

    $cal_id_nisko = np_bookero_cal_id_for('nisko');
    $typ          = ($cal_hash === $cal_id_nisko) ? 'nisko' : 'pelnoplatny';
    $meta_key     = np_bk_meta_key($typ);
    $worker_meta  = np_bk_id_meta_key($typ);

    $posts = get_posts([
        'post_type'              => 'psycholog',
        'posts_per_page'         => 1,
        'post_status'            => 'publish',
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query'             => [
            ['key' => $worker_meta, 'value' => $worker_bk_id, 'compare' => '='],
        ],
    ]);

    if (empty($posts)) {
        return ['skipped' => 'worker_not_found'];
    }

    $post_id = (int) $posts[0];
    $label   = date_i18n('j F Y', $ts);

    update_post_meta($post_id, $meta_key, $label);
    update_post_meta($post_id, 'np_termin_updated_at', time());
    update_option('np_bookero_last_cron_run', time(), false);

    return ['saved' => $label, 'post_id' => $post_id];
});

// ─── Frontend: pobieranie terminów dla widgetów kalendarza ────────────────────
// Brak nonce — endpoint read-only serwowany z page-cached stron.

np_ajax_endpoint('np_get_terminy', [
    'public'       => true,
    'nonce_action' => null,
], function (array $req): void {
    $bookero_id = sanitize_text_field($req['bookero_id'] ?? '');
    $typ        = sanitize_key($req['typ'] ?? 'pelnoplatny');

    if (! $bookero_id) {
        wp_send_json_error(['message' => 'Brak bookero_id']);
    }

    if (! np_bookero_is_valid_typ($typ)) {
        wp_send_json_error(['message' => 'Nieprawidłowy typ']);
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

    $sync        = new \Niepodzielni\Bookero\BookeroSyncService($client, $repo);
    $account_cfg = $sync->getAccountConfig($typ);
    $service_id  = $account_cfg->serviceId;

    try {
        $slots = $client->getMonth($cal_hash, $bookero_id, $service_id, 0);
        $repo->setMonthTransient($typ, $bookero_id, 0, $slots);
        wp_send_json_success($slots);
    } catch (\Niepodzielni\Bookero\BookeroRateLimitException $e) {
        np_bookero_log_error('get_terminy/rate-limit', ['worker' => $bookero_id, 'msg' => $e->getMessage()]);
        $repo->setMonthTransientBackoff($typ, $bookero_id, 0);
        wp_send_json_success([]);
    } catch (\Niepodzielni\Bookero\BookeroApiException $e) {
        np_bookero_log_error('get_terminy/api-error', ['worker' => $bookero_id, 'msg' => $e->getMessage()]);
        $repo->setMonthTransientBackoff($typ, $bookero_id, 0);
        wp_send_json_success([]);
    }
});

// ─── Admin: inicjator synchronizacji wsadowej (Batching) ─────────────────────
// Zamiast synchronizować wszystkich naraz (bomba API), endpoint zwraca listę
// post_ids. JS na stronie ustawień wywołuje np_refresh_termin_single dla każdego
// ID z osobna, z małym opóźnieniem i paskiem postępu.

np_ajax_endpoint('np_refresh_terminy', [
    'nonce_action' => 'np_bookero_nonce',
    'capability'   => 'manage_options',
], function (): array {
    $post_ids = get_posts([
        'post_type'      => 'psycholog',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            'relation' => 'OR',
            ['key' => 'bookero_id_pelny', 'value' => '', 'compare' => '!='],
            ['key' => 'bookero_id_niski', 'value' => '', 'compare' => '!='],
        ],
    ]);

    return ['post_ids' => array_map('intval', $post_ids)];
});

// ─── Admin: odświeżenie terminów dla jednego psychologa ───────────────────────

np_ajax_endpoint('np_refresh_termin_single', [
    'nonce_action' => 'np_bookero_nonce',
    'capability'   => 'manage_options',
], function (array $req): void {
    $post_id = absint($req['post_id'] ?? 0);
    if (! $post_id) {
        wp_send_json_error(['message' => 'Brak post_id']);
    }

    $client = new \Niepodzielni\Bookero\BookeroApiClient();
    $repo   = new \Niepodzielni\Bookero\PsychologistRepository();
    $sync   = new \Niepodzielni\Bookero\BookeroSyncService($client, $repo);

    try {
        $result = $sync->syncSingleWorker($post_id);
    } catch (\Niepodzielni\Bookero\BookeroRateLimitException $e) {
        np_bookero_log_error('refresh_termin_single rate-limit', ['post_id' => $post_id, 'msg' => $e->getMessage()]);
        wp_send_json_error(['message' => 'Bookero API: limit zapytań. Spróbuj ponownie za chwilę.', 'rate_limit' => true]);
    } catch (\Niepodzielni\Bookero\BookeroApiException $e) {
        np_bookero_log_error('refresh_termin_single api-error', ['post_id' => $post_id, 'msg' => $e->getMessage()]);
        wp_send_json_error(['message' => 'Bookero API: ' . $e->getMessage()]);
    }

    $termin_pelny = $result->hasPelny ? $result->nearestPelny : '';
    $termin_niski = $result->hasNisko ? $result->nearestNisko : '';

    $api_warning = '';
    if ($result->hasPelny && $termin_pelny === '') {
        $api_warning .= 'Pełny: brak wolnych terminów. ';
    }
    if ($result->hasNisko && $termin_niski === '') {
        $api_warning .= 'Niski: brak wolnych terminów.';
    }

    if (! $result->hasSynced()) {
        wp_send_json_error(['message' => 'Psycholog nie ma przypisanego Bookero ID.']);
    }

    wp_send_json_success([
        'message'      => $api_warning ? trim($api_warning) : 'Terminy zaktualizowane',
        'termin_pelny' => $termin_pelny ?: '—',
        'termin_niski' => $termin_niski ?: '—',
        'updated_at'   => date_i18n('d.m.Y H:i', time()),
        'warning'      => (bool) $api_warning,
    ]);
});

// ─── Shared Calendar: inwalidacja Object Cache listy psychologów ─────────────

/**
 * Czyści WP Object Cache (Redis) listy psychologów po zapisaniu posta.
 * Bez tej inwalidacji stara lista byłaby serwowana przez max WORKERS_CACHE_TTL (7 min)
 * nawet po dodaniu nowego psychologa lub zmianie jego worker ID.
 */
function np_bookero_invalidate_workers_cache(): void
{
    $repo = new \Niepodzielni\Bookero\PsychologistRepository();
    $repo->invalidateWorkersCache('nisko');
    $repo->invalidateWorkersCache('pelnoplatny');
}

add_action('save_post_psycholog', 'np_bookero_invalidate_workers_cache');
add_action('niepodzielni_bookero_batch_synced', 'np_bookero_invalidate_workers_cache');

// ─── Shared Calendar: fabryka serwisu ────────────────────────────────────────

/**
 * Singleton-fabryka SharedCalendarService — instancja raz na żądanie HTTP.
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
// Brak nonce — endpoint read-only, wywoływany z page-cached stron kalendarza.
// Ochrona przez whitelist $typ + transient cache po stronie serwera.

np_ajax_endpoint('bk_get_shared_month', [
    'public'       => true,
    'nonce_action' => null,
], function (array $req): void {
    $typ         = sanitize_text_field($req['typ'] ?? 'nisko');
    $plus_months = max(0, min(12, (int) ($req['plus_months'] ?? 0)));

    if (! np_bookero_is_valid_typ($typ)) {
        wp_send_json_error(['message' => 'Nieprawidłowy typ']);
    }

    wp_send_json_success(np_bookero_shared_calendar_service()->buildMonthData($typ, $plus_months));
});

// ─── Shared Calendar: sloty dla konkretnego dnia ─────────────────────────────
// Brak nonce — pobieranie godzin przy kliknięciu dnia, z page-cache.

np_ajax_endpoint('bk_get_date_slots', [
    'public'       => true,
    'nonce_action' => null,
], function (array $req): void {
    $typ  = sanitize_text_field($req['typ'] ?? 'nisko');
    $date = sanitize_text_field($req['date'] ?? '');

    if (! np_bookero_is_valid_typ($typ)) {
        wp_send_json_error(['message' => 'Nieprawidłowy typ']);
    }

    if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        wp_send_json_error(['message' => 'Nieprawidłowa data']);
    }

    wp_send_json_success(np_bookero_shared_calendar_service()->getDateSlots($typ, $date));
});

// ─── Shared Calendar: weryfikacja dostępności godziny ─────────────────────────
// Nonce zachowany — endpoint inicjuje N zewnętrznych wywołań API Bookero (po jednym
// na każdy bookero_id). Nonce chroni przed flood-spam z zewnątrz; strona potwierdzenia
// rezerwacji jest dynamiczna (nie cached), więc nonce jest świeży.

np_ajax_endpoint('bk_verify_hour', [
    'public'       => true,
    'nonce_action' => 'np_bookero_nonce',
], function (array $req): array {
    $date = sanitize_text_field($req['date'] ?? '');
    $hour = sanitize_text_field($req['hour'] ?? '');
    $typ  = sanitize_text_field($req['typ']  ?? 'pelnoplatny');
    // Limit 50 — zabezpieczenie przed flood-atakiem wypełniającym pętlę API
    $bookero_ids = array_slice(
        array_map('sanitize_text_field', (array) ($req['bookero_ids'] ?? [])),
        0,
        50,
    );

    if (! np_bookero_is_valid_typ($typ)) {
        throw new \RuntimeException('Nieprawidłowy typ');
    }

    if (! $date || ! $hour || empty($bookero_ids)) {
        return ['removed' => []];
    }

    $removed = [];

    // Batch lookup: worker_id → post_id (1 zapytanie zamiast N)
    // $meta_bk_key pochodzi z whitelisty $typ, ale całe zapytanie chroni $wpdb->prepare().
    $meta_bk_key = np_bk_id_meta_key($typ);
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
        $worker_to_post[$row->meta_value] = (int) $row->post_id;
    }

    $client      = new \Niepodzielni\Bookero\BookeroApiClient();
    $repo        = new \Niepodzielni\Bookero\PsychologistRepository();
    $cal_hash    = np_bookero_cal_id_for($typ);
    $sync        = new \Niepodzielni\Bookero\BookeroSyncService($client, $repo);
    $account_cfg = $sync->getAccountConfig($typ);

    // Micro-Cache: jeśli transient ma < 60 s → nie uderzamy ponownie w API Bookero.
    // Zapobiega kaskadowym requestom przy równoczesnych rezerwacjach + chroni przed
    // błędami rate-limit.

    foreach ($bookero_ids as $worker_id) {
        $hash      = md5($typ . $worker_id . $date);
        $cache_key = 'np_bkday_' . $hash;
        $ts_key    = 'np_bkday_ts_' . $hash;

        $last_fetch = (int) get_transient($ts_key);
        $is_fresh   = $last_fetch && (time() - $last_fetch) < 60;

        if ($is_fresh) {
            $hours = (array) get_transient($cache_key);
        } else {
            try {
                $service_id = $account_cfg->getServiceIdForWorker($worker_id);
                $hours      = $client->getMonthDay($cal_hash, $worker_id, $date, $service_id);
                set_transient($cache_key, $hours, 5 * MINUTE_IN_SECONDS);
                set_transient($ts_key, time(), 300);

                $post_id = $worker_to_post[$worker_id] ?? 0;
                if ($post_id) {
                    $repo->saveHours($post_id, $typ, $date, $hours);

                    // Brak godzin = data nieaktualna → usuń ze slotów + inwaliduj cache
                    if (empty($hours)) {
                        $repo->removeDateFromSlots($post_id, $typ, $date);
                        $repo->invalidateSharedMonthTransients($typ);
                    }
                }
            } catch (\Niepodzielni\Bookero\BookeroRateLimitException $e) {
                np_bookero_log_error('bk_verify_hour/rate-limit', ['worker' => $worker_id, 'msg' => $e->getMessage()]);
                break; // graceful degradation
            } catch (\Niepodzielni\Bookero\BookeroApiException $e) {
                np_bookero_log_error('bk_verify_hour/api-error', ['worker' => $worker_id, 'msg' => $e->getMessage()]);
                break;
            }
        }

        if (! in_array($hour, $hours, true)) {
            $removed[] = $worker_id;
        }
    }

    return ['removed' => $removed];
});

// ─── Shared Calendar: tworzenie rezerwacji ────────────────────────────────────

np_ajax_endpoint('bk_create_booking', [
    'public'       => true,
    'nonce_action' => 'np_bookero_nonce',
], function (array $req): void {
    $cal_hash  = sanitize_text_field($req['cal_hash'] ?? '');
    $worker_id = sanitize_text_field($req['worker']   ?? '');
    $service   = (int) ($req['service'] ?? 0);
    $date      = sanitize_text_field($req['date']     ?? '');
    $hour      = sanitize_text_field($req['hour']     ?? '');
    $name      = sanitize_text_field($req['name']     ?? '');
    $email     = sanitize_email($req['email']         ?? '');
    $phone     = sanitize_text_field($req['phone']    ?? '');
    $ulica     = sanitize_text_field($req['ulica']    ?? '');
    $nr_domu   = sanitize_text_field($req['nr_domu']  ?? '');
    $kod       = sanitize_text_field($req['kod_poczt'] ?? '');
    $miasto    = sanitize_text_field($req['miasto']   ?? '');
    $powod     = sanitize_textarea_field($req['powod'] ?? '');
    $zaimki    = sanitize_text_field($req['zaimki']   ?? '');
    $agree_18  = ! empty($req['agree_18']);
    $agree_tel = ! empty($req['agree_tel']);

    if (! $cal_hash || ! $worker_id || ! $date || ! $hour || ! $name || ! $email || ! $phone) {
        wp_send_json_error('Proszę wypełnić wszystkie wymagane pola.');
    }

    $cal_id_nisko = np_bookero_cal_id_for('nisko');
    $typ          = ($cal_hash === $cal_id_nisko) ? 'nisko' : 'pelnoplatny';

    $client      = new \Niepodzielni\Bookero\BookeroApiClient();
    $repo        = new \Niepodzielni\Bookero\PsychologistRepository();
    $sync        = new \Niepodzielni\Bookero\BookeroSyncService($client, $repo);
    $account_cfg = $sync->getAccountConfig($typ);

    $service_id   = $service ?: $account_cfg->serviceId;
    $payment_id   = $account_cfg->paymentId;
    $service_name = $account_cfg->serviceName ?: 'Konsultacja psychologiczna';

    // plugin_comment z parametrami formularza (stałe IDs dla konta Bookero Niepodzielni)
    $parameters = [];
    if ($powod) {
        $parameters[] = ['id' => '15663', 'value' => $powod, 'value_id' => null];
    }
    if ($agree_18) {
        $parameters[] = ['id' => '16483', 'value' => ['Oświadczam, że mam ukończone 18 lat.'], 'value_id' => [17856]];
    }
    if ($ulica) {
        $parameters[] = ['id' => '16488', 'value' => $ulica, 'value_id' => null];
    }
    if ($nr_domu) {
        $parameters[] = ['id' => '16489', 'value' => $nr_domu, 'value_id' => null];
    }
    if ($kod) {
        $parameters[] = ['id' => '16490', 'value' => $kod, 'value_id' => null];
    }
    if ($miasto) {
        $parameters[] = ['id' => '16492', 'value' => $miasto, 'value_id' => null];
    }
    if ($zaimki) {
        $parameters[] = ['id' => '20215', 'value' => $zaimki, 'value_id' => null];
    }

    $plugin_comment = wp_json_encode([
        'data' => [
            'name'            => $name,
            'phone'           => $phone,
            'parameters'      => $parameters,
            'services_names'  => [['category' => '', 'value' => $service_name, 'require_people' => 0]],
            'services_values' => [['category' => 0, 'value' => $service_id, 'require_people' => 0]],
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
});
