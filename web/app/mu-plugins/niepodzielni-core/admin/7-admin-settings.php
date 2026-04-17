<?php
/**
 * Admin Settings — strona ustawień wtyczki Niepodzielni Core
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'np_add_settings_page');

function np_add_settings_page(): void
{
    add_options_page(
        'Ustawienia Niepodzielni',
        'Niepodzielni',
        'manage_options',
        'niepodzielni-settings',
        'np_render_settings_page',
    );
}

add_action('admin_init', 'np_register_settings');

function np_register_settings(): void
{
    register_setting('np_settings_group', 'np_bookero_api_url', [ 'sanitize_callback' => 'esc_url_raw'         ]);
    register_setting('np_settings_group', 'np_bookero_api_key_pelny', [ 'sanitize_callback' => 'sanitize_text_field' ]);
    register_setting('np_settings_group', 'np_bookero_api_key_nisko', [ 'sanitize_callback' => 'sanitize_text_field' ]);
    register_setting('np_settings_group', 'np_bookero_cal_pelny', [ 'sanitize_callback' => 'sanitize_text_field' ]);
    register_setting('np_settings_group', 'np_bookero_cal_nisko', [ 'sanitize_callback' => 'sanitize_text_field' ]);
    register_setting('np_settings_group', 'np_psy_listing_version', [ 'sanitize_callback' => 'sanitize_text_field' ]);

    add_settings_section('np_bookero_section', 'Konfiguracja Bookero', function () {
        echo '<p>Wartości z pliku <code>.env</code> mają priorytet nad polami poniżej i są wyświetlane jako aktywne.</p>';
    }, 'niepodzielni-settings');

    // Helper: wyświetl pole z informacją o stałej .env
    $env_field = function (string $label, string $option_name, string $const_name, string $desc = '') {
        $env_val  = defined($const_name) ? constant($const_name) : '';
        $db_val   = get_option($option_name, '');
        $is_env   = ! empty($env_val);
        $display  = $is_env ? substr($env_val, 0, 6) . str_repeat('•', max(0, strlen($env_val) - 6)) : '';

        if ($is_env) {
            echo '<code style="background:#eaffea;padding:4px 8px;border-radius:4px;color:#1a6b1a;">'
               . esc_html($display) . '</code> '
               . '<span style="color:#1a6b1a;">✓ ustawione w .env</span>';
        } else {
            echo '<input type="text" name="' . esc_attr($option_name) . '" '
               . 'value="' . esc_attr($db_val) . '" class="regular-text">';
        }
        if ($desc) {
            echo '<p class="description">' . $desc . '</p>';
        }
    };

    add_settings_field('np_bookero_api_url', 'URL API Bookero', function () {
        $val = get_option('np_bookero_api_url', 'https://app.bookero.pl/api/v1');
        echo '<input type="url" name="np_bookero_api_url" value="' . esc_attr($val) . '" class="regular-text">';
    }, 'niepodzielni-settings', 'np_bookero_section');

    add_settings_field('np_bookero_cal_pelny', 'ID kalendarza — Pełnopłatny', function () use ($env_field) {
        $env_field(
            '',
            'np_bookero_cal_pelny',
            'NP_BOOKERO_CAL_ID_PELNY',
            'Hash konta Bookero dla konsultacji pełnopłatnych',
        );
    }, 'niepodzielni-settings', 'np_bookero_section');

    add_settings_field('np_bookero_api_key_pelny', 'Klucz API — Pełnopłatny', function () use ($env_field) {
        $env_field(
            '',
            'np_bookero_api_key_pelny',
            'NP_BOOKERO_API_KEY_PELNY',
            'Klucz Bearer do API Bookero dla konta pełnopłatnego',
        );
    }, 'niepodzielni-settings', 'np_bookero_section');

    add_settings_field('np_bookero_cal_nisko', 'ID kalendarza — Niskopłatny', function () use ($env_field) {
        $env_field(
            '',
            'np_bookero_cal_nisko',
            'NP_BOOKERO_CAL_ID_NISKO',
            'Hash konta Bookero dla konsultacji niskopłatnych',
        );
    }, 'niepodzielni-settings', 'np_bookero_section');

    add_settings_field('np_bookero_api_key_nisko', 'Klucz API — Niskopłatny', function () use ($env_field) {
        $env_field(
            '',
            'np_bookero_api_key_nisko',
            'NP_BOOKERO_API_KEY_NISKO',
            'Klucz Bearer do API Bookero dla konta niskopłatnego',
        );
    }, 'niepodzielni-settings', 'np_bookero_section');
}

function np_render_settings_page(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Ustawienia Niepodzielni Core</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('np_settings_group');
    do_settings_sections('niepodzielni-settings');
    submit_button('Zapisz ustawienia');
    ?>
        </form>
        <hr>
        <h2>Narzędzia</h2>
        <p>
            <button type="button" id="np-bulk-sync-btn" class="button button-secondary">
                Odśwież terminy Bookero ręcznie
            </button>
            <span id="np-bulk-sync-status" style="display:inline-block;margin-left:12px;color:#666;font-size:13px;vertical-align:middle;"></span>
        </p>
        <script>
        (function () {
            var btn     = document.getElementById('np-bulk-sync-btn');
            var status  = document.getElementById('np-bulk-sync-status');
            var ajaxUrl = <?= wp_json_encode(admin_url('admin-ajax.php')) ?>;
            var nonce   = <?= wp_json_encode(wp_create_nonce('np_bookero_nonce')) ?>;

            function post(action, extra) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', nonce);
                Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
                return fetch(ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
            }

            btn.addEventListener('click', function () {
                btn.disabled        = true;
                status.style.color  = '#666';
                status.textContent  = 'Pobieranie listy psychologów…';

                post('np_refresh_terminy')
                    .then(function (res) {
                        if (!res.success || !Array.isArray(res.data.post_ids)) {
                            status.style.color = 'red';
                            status.textContent = '✗ Błąd pobierania listy.';
                            btn.disabled = false;
                            return;
                        }

                        var ids    = res.data.post_ids;
                        var total  = ids.length;
                        var done   = 0;
                        var errors = 0;

                        if (total === 0) {
                            status.style.color = '#888';
                            status.textContent = 'Brak psychologów z Bookero ID.';
                            btn.disabled = false;
                            return;
                        }

                        status.textContent = 'Zsynchronizowano 0 z ' + total + '…';

                        function syncNext(index) {
                            if (index >= total) {
                                btn.disabled = false;
                                if (errors > 0) {
                                    status.style.color = '#b45309';
                                    status.textContent = '⚠ Zsynchronizowano ' + done + ' z ' + total + ' (błędy: ' + errors + ')';
                                } else {
                                    status.style.color = 'green';
                                    status.textContent = '✓ Zsynchronizowano ' + done + ' z ' + total;
                                }
                                return;
                            }

                            post('np_refresh_termin_single', { post_id: ids[index] })
                                .then(function (r) { if (!r.success) errors++; })
                                .catch(function () { errors++; })
                                .finally(function () {
                                    done++;
                                    status.textContent = 'Zsynchronizowano ' + done + ' z ' + total + '…';
                                    setTimeout(function () { syncNext(index + 1); }, 400);
                                });
                        }

                        syncNext(0);
                    })
                    .catch(function () {
                        status.style.color = 'red';
                        status.textContent = '✗ Błąd sieci.';
                        btn.disabled = false;
                    });
            });
        }());
        </script>
        <p><strong>Wersja cache listingu:</strong> <?= esc_html(NP_PSY_LISTING_VERSION) ?></p>
    </div>
    <?php
}
