<?php

/**
 * Calendar — REST API + DB bootstrap.
 *
 * Endpointy:
 *   GET  /niepodzielni/v1/calendar/event/{id}.ics
 *   GET  /niepodzielni/v1/calendar/feed.ics?cpt=wydarzenia,warsztaty,grupy-wsparcia&months=6
 *   POST /niepodzielni/v1/calendar/reminder  body: {email, event_post_id, cf-turnstile-response}
 *
 * Tabela wp_np_event_reminders — UNIQUE(email, event_post_id) blokuje duplikaty.
 * Cron `np_event_reminders_cron` (hourly) — patrz api/73-events-reminders-cron.php.
 */

if (! defined('ABSPATH')) {
    exit;
}

const NP_CALENDAR_DB_VERSION = '1.0';
const NP_CALENDAR_FEED_CACHE_TTL = 30 * MINUTE_IN_SECONDS;
const NP_CALENDAR_FEED_CACHE_GROUP = 'np_calendar';

// CPTs w scope kalendarza.
const NP_CALENDAR_CPTS = ['wydarzenia', 'warsztaty', 'grupy-wsparcia'];

// ─── DB install ───────────────────────────────────────────────────────────────

add_action('plugins_loaded', 'np_calendar_maybe_install_db', 5);

function np_calendar_maybe_install_db(): void
{
    if (get_option('np_calendar_db_version') === NP_CALENDAR_DB_VERSION) {
        return;
    }

    global $wpdb;
    $charsetCollate = $wpdb->get_charset_collate();
    $table          = $wpdb->prefix . 'np_event_reminders';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(255) NOT NULL,
        event_post_id BIGINT UNSIGNED NOT NULL,
        sent_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_email_event (email, event_post_id),
        KEY idx_sent_at (sent_at),
        KEY idx_event (event_post_id)
    ) {$charsetCollate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('np_calendar_db_version', NP_CALENDAR_DB_VERSION, false);
}

function np_calendar_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'np_event_reminders';
}

// ─── REST routes ──────────────────────────────────────────────────────────────

add_action('rest_api_init', function (): void {
    register_rest_route('niepodzielni/v1', '/calendar/event/(?P<id>\d+)\.ics', [
        'methods'             => 'GET',
        'callback'            => 'np_calendar_event_ics',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => [
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);

    register_rest_route('niepodzielni/v1', '/calendar/feed\.ics', [
        'methods'             => 'GET',
        'callback'            => 'np_calendar_feed_ics',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('niepodzielni/v1', '/calendar/reminder', [
        'methods'             => 'POST',
        'callback'            => 'np_calendar_reminder_subscribe',
        'permission_callback' => '__return_true',
    ]);
});

// ─── Helpers — mapowanie postmeta → format IcalGenerator ──────────────────────

/**
 * Ładuje pojedynczy event z bazy w formacie IcalGenerator-ready.
 *
 * @return array<string, mixed>|null
 */
function np_calendar_load_event(int $postId): ?array
{
    $post = get_post($postId);
    if (! $post || $post->post_status !== 'publish') {
        return null;
    }

    if (! in_array($post->post_type, NP_CALENDAR_CPTS, true)) {
        return null;
    }

    $date = (string) get_post_meta($postId, 'data', true);
    if ($date === '') {
        return null;
    }

    // Status="Odwołane" → pomiń
    $status = (string) get_post_meta($postId, 'status', true);
    if ($status === 'Odwołane') {
        return null;
    }

    $isWydarzenie = $post->post_type === 'wydarzenia';

    $timeStart = $isWydarzenie
        ? (string) get_post_meta($postId, 'godzina_rozpoczecia', true)
        : (string) get_post_meta($postId, 'godzina', true);
    $timeEnd   = (string) get_post_meta($postId, 'godzina_zakonczenia', true);

    $title = $isWydarzenie
        ? $post->post_title
        : ((string) get_post_meta($postId, 'temat', true) ?: $post->post_title);

    $location = (string) get_post_meta($postId, 'lokalizacja', true);
    if ($isWydarzenie) {
        $miasto = (string) get_post_meta($postId, 'miasto', true);
        if ($miasto !== '' && $location !== '') {
            $location = $miasto . ', ' . $location;
        } elseif ($miasto !== '') {
            $location = $miasto;
        }
    }

    $descRaw = $isWydarzenie
        ? (string) get_post_meta($postId, 'opis', true)
        : '';
    $description = $descRaw !== ''
        ? wp_strip_all_tags($descRaw)
        : wp_strip_all_tags(wp_trim_words($post->post_content, 40));

    return [
        'id'          => $postId,
        'cpt'         => $post->post_type,
        'title'       => $title,
        'date'        => $date,
        'time_start'  => $timeStart,
        'time_end'    => $timeEnd,
        'location'    => $location,
        'description' => $description,
        'url'         => (string) get_permalink($postId),
    ];
}

/**
 * Zbiera nadchodzące eventy z podanych CPT, sortowane po dacie ASC.
 *
 * @param string[] $cpts
 * @return array<int, array<string, mixed>>
 */
function np_calendar_collect_events(array $cpts, int $monthsAhead = 6, int $limit = 200): array
{
    $cpts = array_values(array_intersect($cpts, NP_CALENDAR_CPTS));
    if (empty($cpts)) {
        return [];
    }

    $today = current_time('Y-m-d');
    $until = (new DateTimeImmutable($today))->modify("+{$monthsAhead} months")->format('Y-m-d');

    $query = new \WP_Query([
        'post_type'              => $cpts,
        'post_status'            => 'publish',
        'posts_per_page'         => $limit,
        'no_found_rows'          => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
        'meta_query'             => [
            [
                'key'     => 'data',
                'value'   => [$today, $until],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ],
        ],
        'orderby'                => 'meta_value',
        'meta_key'               => 'data',
        'order'                  => 'ASC',
    ]);

    $events = [];
    foreach ($query->posts as $post) {
        $event = np_calendar_load_event($post->ID);
        if ($event !== null) {
            $events[] = $event;
        }
    }

    return $events;
}

// ─── REST callbacks ───────────────────────────────────────────────────────────

function np_calendar_event_ics(\WP_REST_Request $request): \WP_REST_Response
{
    $postId = (int) $request->get_param('id');
    $event  = np_calendar_load_event($postId);

    if ($event === null) {
        return new \WP_REST_Response(['error' => 'Event not found'], 404);
    }

    $generator = new \Niepodzielni\Calendar\IcalGenerator();
    $ics       = $generator->generateEvent($event);

    $filename = sprintf(
        '%s-%d.ics',
        sanitize_title($event['title']) ?: 'wydarzenie',
        $postId,
    );

    nocache_headers();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($ics));
    echo $ics;
    exit;
}

function np_calendar_feed_ics(\WP_REST_Request $request): \WP_REST_Response
{
    $cptParam = (string) $request->get_param('cpt');
    $cpts     = $cptParam !== ''
        ? array_filter(array_map('sanitize_key', explode(',', $cptParam)))
        : NP_CALENDAR_CPTS;

    $monthsAhead = (int) ($request->get_param('months') ?? 6);
    $monthsAhead = max(1, min(12, $monthsAhead));

    $cacheKey = 'np_calendar_feed_' . md5(implode(',', $cpts) . '_' . $monthsAhead);
    $cached   = wp_cache_get($cacheKey, NP_CALENDAR_FEED_CACHE_GROUP);

    if ($cached !== false && is_string($cached)) {
        $ics = $cached;
    } else {
        $events    = np_calendar_collect_events($cpts, $monthsAhead);
        $generator = new \Niepodzielni\Calendar\IcalGenerator();
        $ics       = $generator->generateFeed($events);
        wp_cache_set($cacheKey, $ics, NP_CALENDAR_FEED_CACHE_GROUP, NP_CALENDAR_FEED_CACHE_TTL);
    }

    nocache_headers();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="niepodzielni-events.ics"');
    header('Content-Length: ' . strlen($ics));
    echo $ics;
    exit;
}

/**
 * Invalidacja cache feedu przy zmianach eventów.
 */
add_action('save_post', 'np_calendar_invalidate_feed_cache');
add_action('deleted_post', 'np_calendar_invalidate_feed_cache');

function np_calendar_invalidate_feed_cache($postId): void
{
    if (in_array(get_post_type($postId), NP_CALENDAR_CPTS, true)) {
        wp_cache_flush_group(NP_CALENDAR_FEED_CACHE_GROUP);
    }
}

// ─── REST: POST /calendar/reminder ────────────────────────────────────────────

function np_calendar_reminder_subscribe(\WP_REST_Request $request): \WP_REST_Response
{
    $body = $request->get_json_params() ?: $request->get_params();

    $email     = sanitize_email((string) ($body['email'] ?? ''));
    $eventId   = (int) ($body['event_post_id'] ?? 0);
    $turnstile = (string) ($body['cf-turnstile-response'] ?? '');
    $remoteIp  = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

    if (! is_email($email)) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Wprowadź poprawny adres email.',
        ], 400);
    }

    if ($eventId <= 0) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Brak event_post_id.',
        ], 400);
    }

    // Turnstile (re-use helper z forms-api). W dev pomijamy.
    if (function_exists('np_verify_turnstile') && ! np_verify_turnstile($turnstile, $remoteIp)) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Weryfikacja anty-spam nie powiodła się.',
        ], 400);
    }

    $event = np_calendar_load_event($eventId);
    if ($event === null) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Wydarzenie nie istnieje lub zostało odwołane.',
        ], 404);
    }

    // Wydarzenie za <24h — opt-in i tak nie zdąży, blokujemy
    $eventDate = (string) $event['date'];
    $today     = current_time('Y-m-d');
    if ($eventDate <= $today) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Wydarzenie odbywa się za mniej niż 24 godziny — przypomnienie nie zostanie wysłane na czas.',
        ], 400);
    }

    global $wpdb;
    $table = np_calendar_table();

    // INSERT z ON DUPLICATE — nie zwraca błędu jeśli email już subskrybuje
    $result = $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$table} (email, event_post_id, created_at)
             VALUES (%s, %d, NOW())
             ON DUPLICATE KEY UPDATE created_at = created_at",
            $email,
            $eventId,
        )
    );

    if ($result === false) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Nie udało się zapisać przypomnienia. Spróbuj ponownie.',
        ], 500);
    }

    return new \WP_REST_Response([
        'status'  => 'ok',
        'message' => 'Przypomnienie ustawione. Otrzymasz email dzień przed wydarzeniem.',
    ], 200);
}
