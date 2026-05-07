<?php

/**
 * Audit log — utrwala zdarzenia bezpieczeństwa w dedykowanej tabeli `wp_np_audit`.
 *
 * Zdarzenia logowane:
 *   - login_success / login_failed       — `wp_login` / `wp_login_failed`
 *   - login_lockout                       — `np_security_lockout` (z 13-login-throttle.php)
 *   - user_registered                     — `user_register`
 *   - password_reset                      — `password_reset`
 *   - profile_updated                     — `profile_update`
 *   - form_submit / form_verify_failed    — `np_audit_event` (z forms-api)
 *   - panel_post_updated                  — `np_audit_event`
 *
 * Każdy mu-plugin może triggerować custom event przez:
 *     do_action('np_audit_event', ['action' => '...', 'target' => '...', 'meta' => [...]]);
 *
 * Admin może przeglądać log w Tools → Audit Log (tylko `manage_options`).
 */

if (! defined('ABSPATH')) {
    exit;
}

const NP_AUDIT_TABLE_VERSION = '1.0';

function np_audit_table_name(): string
{
    global $wpdb;
    return $wpdb->prefix . 'np_audit';
}

function np_audit_install(): void
{
    global $wpdb;
    $installed = get_option('np_audit_table_version');
    if ($installed === NP_AUDIT_TABLE_VERSION) {
        return;
    }

    $table   = np_audit_table_name();
    $charset = $wpdb->get_charset_collate();
    $sql     = "CREATE TABLE {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ts          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ip          VARCHAR(45)     NOT NULL DEFAULT '',
        action      VARCHAR(64)     NOT NULL,
        target      VARCHAR(255)    NOT NULL DEFAULT '',
        meta        LONGTEXT        NULL,
        PRIMARY KEY (id),
        KEY idx_ts        (ts),
        KEY idx_user_id   (user_id),
        KEY idx_action    (action)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('np_audit_table_version', NP_AUDIT_TABLE_VERSION, false);
}

add_action('plugins_loaded', 'np_audit_install', 1);

function np_audit_log(string $action, array $context = []): void
{
    global $wpdb;
    $userId = isset($context['user_id']) ? (int) $context['user_id'] : get_current_user_id();
    $ip     = isset($context['ip']) ? (string) $context['ip'] : np_audit_client_ip();
    $target = isset($context['target']) ? substr((string) $context['target'], 0, 255) : '';
    $meta   = isset($context['meta']) ? wp_json_encode($context['meta']) : null;

    $wpdb->insert(
        np_audit_table_name(),
        [
            'user_id' => $userId,
            'ip'      => substr($ip, 0, 45),
            'action'  => substr($action, 0, 64),
            'target'  => $target,
            'meta'    => $meta,
        ],
        ['%d', '%s', '%s', '%s', '%s'],
    );
}

function np_audit_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = isset($_SERVER[$key]) ? (string) $_SERVER[$key] : '';
        if ($value === '') {
            continue;
        }
        $ip = trim((string) explode(',', $value)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

// ── Hooki uwierzytelnienia ────────────────────────────────────────────────────

add_action('wp_login', static function (string $login, \WP_User $user): void {
    np_audit_log('login_success', [
        'user_id' => $user->ID,
        'target'  => $login,
    ]);
}, 10, 2);

add_action('wp_login_failed', static function (string $login): void {
    np_audit_log('login_failed', ['target' => $login]);
});

add_action('user_register', static function (int $userId): void {
    np_audit_log('user_registered', ['user_id' => $userId, 'target' => (string) $userId]);
});

add_action('password_reset', static function (\WP_User $user): void {
    np_audit_log('password_reset', ['user_id' => $user->ID, 'target' => $user->user_login]);
});

add_action('profile_update', static function (int $userId): void {
    np_audit_log('profile_updated', ['user_id' => $userId, 'target' => (string) $userId]);
});

add_action('np_security_lockout', static function (array $payload): void {
    np_audit_log('login_lockout', [
        'ip'     => (string) ($payload['ip'] ?? ''),
        'target' => (string) ($payload['username'] ?? ''),
        'meta'   => $payload,
    ]);
});

// Custom hook dla mu-pluginów / theme.
add_action('np_audit_event', static function (array $payload): void {
    $action = (string) ($payload['action'] ?? 'custom');
    np_audit_log($action, $payload);
});

// ── Retention: usuwaj wpisy starsze niż 365 dni (cron z 15-retention-cron.php) ──

add_action('np_audit_purge_old', static function (): void {
    global $wpdb;
    $table = np_audit_table_name();
    $wpdb->query(
        $wpdb->prepare("DELETE FROM {$table} WHERE ts < DATE_SUB(NOW(), INTERVAL %d DAY)", 365),
    );
});

// ── Prosta strona admina: Tools → Audit Log ──────────────────────────────────

add_action('admin_menu', static function (): void {
    add_management_page(
        'Audit Log',
        'Audit Log',
        'manage_options',
        'np-audit-log',
        'np_audit_render_admin_page',
    );
});

function np_audit_render_admin_page(): void
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Brak uprawnień.'));
    }

    global $wpdb;
    $table   = np_audit_table_name();
    $perPage = 50;
    $page    = max(1, (int) ($_GET['paged'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    $action  = isset($_GET['action_filter']) ? sanitize_text_field((string) $_GET['action_filter']) : '';
    $where   = '';
    $params  = [];
    if ($action !== '') {
        $where    = 'WHERE action = %s';
        $params[] = $action;
    }

    $params[] = $perPage;
    $params[] = $offset;

    // Bezpieczne: tabela to constant identifier, where pochodzi z whitelisty wyżej, parametry przez prepare.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, ts, user_id, ip, action, target, meta FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            ...$params,
        ),
    );

    echo '<div class="wrap"><h1>Audit Log</h1>';
    echo '<form method="get"><input type="hidden" name="page" value="np-audit-log">';
    echo 'Filtr akcji: <input type="text" name="action_filter" value="' . esc_attr($action) . '"> ';
    echo '<button class="button">Filtruj</button></form>';
    echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Czas</th><th>User</th><th>IP</th><th>Action</th><th>Target</th><th>Meta</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . (int) $row->id . '</td>';
        echo '<td>' . esc_html((string) $row->ts) . '</td>';
        echo '<td>' . (int) $row->user_id . '</td>';
        echo '<td>' . esc_html((string) $row->ip) . '</td>';
        echo '<td>' . esc_html((string) $row->action) . '</td>';
        echo '<td>' . esc_html((string) $row->target) . '</td>';
        echo '<td><code>' . esc_html((string) $row->meta) . '</code></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    $next = $page + 1;
    $prev = max(1, $page - 1);
    echo '<p><a href="?page=np-audit-log&paged=' . esc_attr((string) $prev) . '">« Prev</a> | ';
    echo '<a href="?page=np-audit-log&paged=' . esc_attr((string) $next) . '">Next »</a></p>';
    echo '</div>';
}
