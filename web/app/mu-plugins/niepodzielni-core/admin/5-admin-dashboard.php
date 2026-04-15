<?php
/**
 * Admin Dashboard — customizacja panelu głównego WP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Usuń zbędne widgety z dashboardu
add_action( 'wp_dashboard_setup', 'np_remove_dashboard_widgets' );

function np_remove_dashboard_widgets(): void {
    remove_meta_box( 'dashboard_quick_press',      'dashboard', 'side'   );
    remove_meta_box( 'dashboard_primary',          'dashboard', 'side'   );
    remove_meta_box( 'dashboard_site_health',      'dashboard', 'normal' );
    remove_meta_box( 'wpseo-dashboard-overview',   'dashboard', 'normal' );
}

// Dodaj widget z informacjami projektu
add_action( 'wp_dashboard_setup', 'np_add_dashboard_widget' );

function np_add_dashboard_widget(): void {
    wp_add_dashboard_widget(
        'np_dashboard_info',
        'Niepodzielni — panel zarządzania',
        'np_render_dashboard_widget'
    );
}

/**
 * Sprawdza status połączenia z API Bookero dla danego cal_id.
 * Wynik cache'owany przez 5 min w transiencie.
 *
 * @return array{ok: bool, msg: string}
 */
function np_check_bookero_status( string $typ ): array {
    $cache_key = 'np_bk_status_' . $typ;
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }

    $cal_id = np_bookero_cal_id_for( $typ );
    if ( ! $cal_id ) {
        $result = [ 'ok' => false, 'msg' => 'Brak ID kalendarza w konfiguracji' ];
        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    $response = wp_remote_get(
        'https://plugin.bookero.pl/plugin-api/v2/init?' . http_build_query( [
            'bookero_id' => $cal_id,
            'lang'       => 'pl',
            'type'       => 'calendar',
        ] ),
        [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            ],
        ]
    );

    if ( is_wp_error( $response ) ) {
        $result = [ 'ok' => false, 'msg' => 'Błąd połączenia: ' . $response->get_error_message() ];
        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code === 200 && isset( $body['result'] ) && $body['result'] == 1 ) {
        $workers = count( $body['workers_list'] ?? [] );
        $result  = [ 'ok' => true, 'msg' => "OK — {$workers} pracowników w kalendarzu" ];
    } else {
        $result = [ 'ok' => false, 'msg' => "HTTP {$code}" . ( isset( $body['message'] ) ? ': ' . $body['message'] : '' ) ];
    }

    set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
    return $result;
}

function np_render_dashboard_widget(): void {
    $psycholodzy = wp_count_posts( 'psycholog' );
    $warsztaty   = wp_count_posts( 'warsztaty' );
    $wydarzenia  = wp_count_posts( 'wydarzenia' );

    // Status API Bookero (obie konta)
    $status_pelny = np_check_bookero_status( 'pelnoplatny' );
    $status_nisko = np_check_bookero_status( 'nisko' );

    // Statystyki synchronizacji terminów
    global $wpdb;
    $total_psy = (int) $psycholodzy->publish;

    // Psycholodzy z ustawionym worker ID (mają Bookero)
    $with_bookero = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_type = 'psycholog' AND p.post_status = 'publish'
           AND pm.meta_key IN ('bookero_id_pelny','bookero_id_niski')
           AND pm.meta_value != ''"
    );

    // Zsynchronizowani (mają np_termin_updated_at)
    $synced = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_type = 'psycholog' AND p.post_status = 'publish'
           AND pm.meta_key = 'np_termin_updated_at'"
    );

    // Mają wolne terminy (przynajmniej jeden typ)
    $has_terms = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_type = 'psycholog' AND p.post_status = 'publish'
           AND pm.meta_key IN ('najblizszy_termin_pelnoplatny','najblizszy_termin_niskoplatny')
           AND pm.meta_value != ''"
    );

    // max(0,...) — psycholodzy z terminem zapisanym przed dodaniem np_termin_updated_at
    // mogą powodować ujemne liczby; cron uzupełni timestamp przy następnym przebiegu
    $not_synced    = max( 0, $with_bookero - $synced );
    $synced_noterm = max( 0, $synced - $has_terms );

    // Log błędów API (ostatnie 24h)
    $error_log     = get_option( 'np_bookero_error_log', [] );
    if ( ! is_array( $error_log ) ) $error_log = [];
    $recent_errors = array_filter( $error_log, fn( $e ) => ( $e['ts'] ?? 0 ) > time() - DAY_IN_SECONDS );

    // Status crona
    $last_run  = (int) get_option( 'np_bookero_last_cron_run', 0 );
    $next_run  = (int) wp_next_scheduled( BOOKERO_CRON_HOOK );
    $interval  = 60; // co minutę
    $now       = time();
    $progress  = $last_run ? min( 100, (int) round( ( $now - $last_run ) / $interval * 100 ) ) : 0;
    $last_ago  = $last_run  ? human_time_diff( $last_run,  $now ) . ' temu' : 'nigdy';
    if ( ! $next_run ) {
        $next_in = 'niezaplanowany';
    } elseif ( $next_run < $now - 90 ) {
        // Zaległa o więcej niż 90s — cron nie odpalał się przez dłuższy czas
        $next_in = '<span style="color:#b45309">zaległa (' . human_time_diff( $next_run, $now ) . ' temu) — odpali przy następnej wizycie</span>';
    } elseif ( $next_run <= $now ) {
        // Kilka sekund spóźnienia — normalne przy WP-Cron, odpali przy następnej wizycie
        $next_in = 'odpali przy następnej wizycie';
    } else {
        $next_in = 'za ' . human_time_diff( $now, $next_run );
    }

    ?>
    <style>
    .np-dash-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 16px; }
    .np-dash-stat { background: #f9f9f9; border-radius: 8px; padding: 10px 14px; text-align: center; }
    .np-dash-stat strong { display: block; font-size: 22px; color: #1c2e4a; line-height: 1.2; }
    .np-dash-stat span { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .04em; }

    .np-api-row { display: flex; align-items: center; gap: 8px; padding: 7px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
    .np-api-row:last-child { border-bottom: none; }
    .np-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .np-dot-ok  { background: #01BE4A; box-shadow: 0 0 0 3px rgba(1,190,74,.2); }
    .np-dot-err { background: #e53e3e; box-shadow: 0 0 0 3px rgba(229,62,62,.2); }
    .np-api-label { font-weight: 600; color: #333; min-width: 110px; }
    .np-api-msg { color: #666; }

    .np-cron-section { margin-top: 14px; }
    .np-cron-header { display: flex; justify-content: space-between; font-size: 12px; color: #666; margin-bottom: 5px; }
    .np-cron-bar-bg { background: #eee; border-radius: 4px; height: 8px; overflow: hidden; }
    .np-cron-bar-fill { height: 100%; border-radius: 4px; background: linear-gradient(90deg, #01BE4A, #01a742); transition: width .3s; }
    .np-cron-footer { display: flex; justify-content: space-between; font-size: 11px; color: #aaa; margin-top: 4px; }

    .np-error-section { margin-top: 14px; }
    .np-error-list { list-style: none; margin: 0; padding: 0; max-height: 160px; overflow-y: auto; }
    .np-error-list li { font-size: 11px; padding: 4px 0; border-bottom: 1px solid #fdd; color: #b00; display: flex; gap: 6px; }
    .np-error-list li time { color: #aaa; white-space: nowrap; flex-shrink: 0; }
    .np-error-badge { display: inline-block; background: #e53e3e; color: #fff; border-radius: 10px; font-size: 10px; padding: 1px 7px; margin-left: 6px; vertical-align: middle; }

    .np-sync-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 14px; }
    .np-sync-stat { border-radius: 6px; padding: 8px 10px; text-align: center; }
    .np-sync-stat strong { display: block; font-size: 20px; line-height: 1.2; }
    .np-sync-stat span { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
    .np-sync-ok   { background: #f0faf4; }
    .np-sync-ok   strong { color: #01BE4A; }
    .np-sync-ok   span   { color: #2d6a4f; }
    .np-sync-warn { background: #fffbeb; }
    .np-sync-warn strong { color: #b45309; }
    .np-sync-warn span   { color: #92400e; }
    .np-sync-new  { background: #f0f4ff; }
    .np-sync-new  strong { color: #3b4fc8; }
    .np-sync-new  span   { color: #1e3a8a; }
    </style>

    <div class="np-dash-grid">
        <div class="np-dash-stat">
            <strong><?= (int) $psycholodzy->publish ?></strong>
            <span>Psycholodzy</span>
        </div>
        <div class="np-dash-stat">
            <strong><?= (int) $warsztaty->publish ?></strong>
            <span>Warsztaty</span>
        </div>
        <div class="np-dash-stat">
            <strong><?= (int) $wydarzenia->publish ?></strong>
            <span>Wydarzenia</span>
        </div>
    </div>

    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#aaa;margin-bottom:6px;">Terminy — synchronizacja</div>
    <div class="np-sync-grid">
        <div class="np-sync-stat np-sync-ok">
            <strong><?= $has_terms ?></strong>
            <span>Ma wolne terminy</span>
        </div>
        <div class="np-sync-stat np-sync-warn">
            <strong><?= $synced_noterm ?></strong>
            <span>Brak wolnych (API OK)</span>
        </div>
        <div class="np-sync-stat np-sync-new">
            <strong><?= max( 0, $not_synced ) ?></strong>
            <span>Jeszcze nie sprawdzono</span>
        </div>
    </div>

    <div style="margin-bottom:14px;">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#aaa;margin-bottom:6px;">Status Bookero API</div>
        <div class="np-api-row">
            <span class="np-dot <?= $status_pelny['ok'] ? 'np-dot-ok' : 'np-dot-err' ?>"></span>
            <span class="np-api-label">Pełnopłatny</span>
            <span class="np-api-msg"><?= esc_html( $status_pelny['msg'] ) ?></span>
        </div>
        <div class="np-api-row">
            <span class="np-dot <?= $status_nisko['ok'] ? 'np-dot-ok' : 'np-dot-err' ?>"></span>
            <span class="np-api-label">Niskopłatny</span>
            <span class="np-api-msg"><?= esc_html( $status_nisko['msg'] ) ?></span>
        </div>
    </div>

    <div class="np-cron-section">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#aaa;margin-bottom:6px;">
            Synchronizacja — system cron (co 60s, niezależnie od ruchu)
        </div>
        <div class="np-cron-header">
            <span>Ostatnio: <strong id="np-cron-ago"><?= esc_html( $last_ago ) ?></strong></span>
            <span>Następna za: <strong id="np-cron-next">…</strong></span>
        </div>
        <div class="np-cron-bar-bg">
            <div class="np-cron-bar-fill" id="np-cron-bar" style="width:<?= $progress ?>%"></div>
        </div>
        <div class="np-cron-footer">
            <span><?= $last_run ? esc_html( date_i18n( 'd.m.Y H:i:s', $last_run ) ) : '—' ?></span>
            <span id="np-cron-next-abs"></span>
        </div>
    </div>
    <script>
    (function() {
        var lastRun  = <?= (int) $last_run ?>;
        var interval = 60;
        var agoEl    = document.getElementById('np-cron-ago');
        var nextEl   = document.getElementById('np-cron-next');
        var barEl    = document.getElementById('np-cron-bar');
        var nextAbs  = document.getElementById('np-cron-next-abs');

        function fmt(sec) {
            if (sec <= 0)  return 'za chwilę…';
            if (sec < 60)  return sec + 's';
            return Math.floor(sec / 60) + 'm ' + (sec % 60) + 's';
        }
        function fmtAgo(sec) {
            if (sec < 5)   return 'przed chwilą';
            if (sec < 60)  return sec + 's temu';
            if (sec < 3600) return Math.floor(sec / 60) + ' min temu';
            return Math.floor(sec / 3600) + ' h temu';
        }
        function pad(n) { return n < 10 ? '0' + n : n; }

        function tick() {
            if (!lastRun) { nextEl.textContent = 'brak danych'; return; }
            var now     = Math.floor(Date.now() / 1000);
            var elapsed = now - lastRun;
            var remaining = interval - (elapsed % interval);
            var progress  = Math.min(100, Math.round((elapsed % interval) / interval * 100));
            var nextTs    = lastRun + Math.ceil(elapsed / interval) * interval;
            var d         = new Date(nextTs * 1000);

            agoEl.textContent   = fmtAgo(elapsed);
            nextEl.textContent  = fmt(remaining);
            barEl.style.width   = progress + '%';
            nextAbs.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());

            // Zmień kolor paska gdy opóźnienie > 90s (cron mógł nie wypalić)
            barEl.style.background = elapsed > 90
                ? 'linear-gradient(90deg,#e53e3e,#c53030)'
                : 'linear-gradient(90deg,#01BE4A,#01a742)';
        }

        tick();
        setInterval(tick, 1000);
    })();
    </script>

    <?php if ( ! empty( $recent_errors ) ) : ?>
    <div class="np-error-section">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#b00;margin-bottom:6px;">
            Błędy API Bookero (ostatnie 24h)
            <span class="np-error-badge"><?= count( $recent_errors ) ?></span>
            <a href="<?= esc_url( add_query_arg( 'np_clear_bk_errors', '1' ) ) ?>"
               style="font-size:10px;margin-left:8px;color:#aaa;text-decoration:none;"
               onclick="return confirm('Wyczyścić log błędów?')">wyczyść</a>
        </div>
        <ul class="np-error-list">
        <?php foreach ( $recent_errors as $err ) : ?>
            <li>
                <time><?= esc_html( date_i18n( 'd.m H:i', $err['ts'] ) ) ?></time>
                <span>[<?= esc_html( $err['context'] ) ?>] <?= esc_html( $err['msg'] ) ?></span>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    <?php
}

// Obsługa czyszczenia logu błędów
add_action( 'admin_init', 'np_maybe_clear_bookero_error_log' );

function np_maybe_clear_bookero_error_log(): void {
    if ( ! isset( $_GET['np_clear_bk_errors'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    delete_option( 'np_bookero_error_log' );
    wp_redirect( remove_query_arg( 'np_clear_bk_errors' ) );
    exit;
}
