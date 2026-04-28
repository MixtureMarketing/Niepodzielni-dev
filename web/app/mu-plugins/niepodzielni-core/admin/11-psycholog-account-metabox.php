<?php

/**
 * Metabox "Konto Psychologa" w edycji posta CPT psycholog.
 *
 * Trzy stany:
 *   1. Brak emaila → komunikat "Wpisz email i zapisz post"
 *   2. Email jest, brak konta → przycisk "Stwórz konto"
 *   3. Konto istnieje → status + link do user-edit + przycisk "Wyślij ponownie link do hasła"
 *
 * AJAX:
 *   - np_psycholog_create_account
 *   - np_psycholog_resend_password_link
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('add_meta_boxes', function (): void {
    add_meta_box(
        'np_psycholog_account',
        'Konto psychologa',
        'np_render_psycholog_account_metabox',
        'psycholog',
        'side',
        'high',
    );
});

function np_render_psycholog_account_metabox(WP_Post $post): void
{
    $email = (string) get_post_meta($post->ID, 'email_kontaktowy', true);
    $nonce = wp_create_nonce('np_psycholog_account_' . $post->ID);

    echo '<div id="np-psycholog-account-box" data-post-id="' . (int) $post->ID . '" data-nonce="' . esc_attr($nonce) . '">';

    if ($email === '') {
        echo '<p style="margin:0 0 8px"><strong>Brak emaila.</strong></p>';
        echo '<p style="color:#666;font-size:12px;margin:0">Aby utworzyć konto, wpisz email w polu "Email logowania" w sekcji "Ustawienia Psychologa", zapisz post i odśwież stronę.</p>';
        echo '</div>';
        return;
    }

    $user = get_user_by('email', $email);

    if (! $user) {
        echo '<p style="margin:0 0 8px"><strong>Email:</strong> <code>' . esc_html($email) . '</code></p>';
        echo '<p style="color:#666;font-size:12px;margin:0 0 12px">Konto WP nie istnieje. Po kliknięciu "Stwórz konto":</p>';
        echo '<ul style="font-size:12px;color:#666;margin:0 0 12px;padding-left:18px">';
        echo '<li>powstanie konto z rolą <em>Psycholog</em></li>';
        echo '<li>post psychologa zostanie powiązany z tym kontem</li>';
        echo '<li>na podany email pójdzie link do ustawienia hasła</li>';
        echo '</ul>';
        echo '<button type="button" class="button button-primary" id="np-create-psycholog-account" style="width:100%">Stwórz konto</button>';
        echo '<p class="np-account-feedback" style="margin:8px 0 0;font-size:12px"></p>';
    } else {
        $is_linked = (int) $post->post_author === (int) $user->ID;
        echo '<p style="margin:0 0 8px;color:#16a34a"><strong>✓ Konto aktywne</strong></p>';
        echo '<p style="margin:0 0 8px"><a href="' . esc_url(admin_url('user-edit.php?user_id=' . (int) $user->ID)) . '">' . esc_html($email) . '</a></p>';

        if (! $is_linked) {
            echo '<p style="background:#fef3c7;color:#92400e;padding:8px;border-radius:4px;font-size:12px;margin:0 0 8px">';
            echo '⚠ Post nie jest powiązany z tym kontem (post_author się nie zgadza). Kliknij "Powiąż" aby naprawić.';
            echo '</p>';
            echo '<button type="button" class="button" id="np-link-psycholog-account" style="width:100%;margin-bottom:8px">Powiąż post z kontem</button>';
        }

        echo '<button type="button" class="button" id="np-resend-password-link" style="width:100%">Wyślij link do ustawienia hasła</button>';
        echo '<p class="np-account-feedback" style="margin:8px 0 0;font-size:12px"></p>';
    }

    echo '</div>';

    // Inline JS — wystarczy tu, nie warto bundlować osobnego pliku dla 30 linii
    ?>
    <script>
    (function() {
        const box = document.getElementById('np-psycholog-account-box');
        if (!box) return;
        const postId   = box.dataset.postId;
        const nonce    = box.dataset.nonce;
        const feedback = box.querySelector('.np-account-feedback');

        function call(action, btn) {
            const orig = btn.textContent;
            btn.disabled = true; btn.textContent = 'Pracuję…';
            feedback.textContent = ''; feedback.style.color = '';
            const body = new FormData();
            body.append('action', action);
            body.append('post_id', postId);
            body.append('nonce', nonce);
            fetch(ajaxurl, { method: 'POST', body })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        feedback.style.color = '#16a34a';
                        feedback.textContent = res.data.message || 'OK';
                        if (res.data.reload) setTimeout(() => location.reload(), 800);
                    } else {
                        feedback.style.color = '#dc2626';
                        feedback.textContent = (res.data && res.data.message) || 'Błąd';
                        btn.disabled = false; btn.textContent = orig;
                    }
                })
                .catch(() => {
                    feedback.style.color = '#dc2626';
                    feedback.textContent = 'Błąd sieci';
                    btn.disabled = false; btn.textContent = orig;
                });
        }

        const create = document.getElementById('np-create-psycholog-account');
        if (create) create.addEventListener('click', () => call('np_psycholog_create_account', create));

        const link = document.getElementById('np-link-psycholog-account');
        if (link) link.addEventListener('click', () => call('np_psycholog_link_account', link));

        const resend = document.getElementById('np-resend-password-link');
        if (resend) resend.addEventListener('click', () => call('np_psycholog_resend_password_link', resend));
    })();
    </script>
    <?php
}

// ─── AJAX: utworzenie konta ──────────────────────────────────────────────────

add_action('wp_ajax_np_psycholog_create_account', 'np_ajax_psycholog_create_account');

function np_ajax_psycholog_create_account(): void
{
    $post_id = (int) ($_POST['post_id'] ?? 0);

    if (! current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'Brak uprawnień'], 403);
    }
    if (! wp_verify_nonce((string) ($_POST['nonce'] ?? ''), 'np_psycholog_account_' . $post_id)) {
        wp_send_json_error(['message' => 'Nieprawidłowy token'], 403);
    }

    $post = get_post($post_id);
    if (! $post || $post->post_type !== 'psycholog') {
        wp_send_json_error(['message' => 'Nieprawidłowy post'], 400);
    }

    $email = sanitize_email((string) get_post_meta($post_id, 'email_kontaktowy', true));
    if (! is_email($email)) {
        wp_send_json_error(['message' => 'Nieprawidłowy email w polu meta'], 400);
    }

    if (get_user_by('email', $email)) {
        wp_send_json_error(['message' => 'Konto z tym emailem już istnieje'], 409);
    }

    // Generuj unikalny username z emaila + sufiks gdy konflikt
    $base = sanitize_user(strstr($email, '@', true) ?: 'psycholog', true);
    $username = $base;
    $suffix = 0;
    while (username_exists($username)) {
        $suffix++;
        $username = $base . $suffix;
    }

    $user_id = wp_create_user($username, wp_generate_password(20, true, true), $email);
    if (is_wp_error($user_id)) {
        wp_send_json_error(['message' => 'Nie udało się utworzyć konta: ' . $user_id->get_error_message()], 500);
    }

    // Ustaw rolę psycholog (overrideuje domyślną subscriber)
    $user = new WP_User($user_id);
    $user->set_role(NP_PSYCHOLOG_ROLE);

    // Ustaw display_name na tytuł posta (imię i nazwisko)
    wp_update_user([
        'ID'           => $user_id,
        'display_name' => $post->post_title,
        'first_name'   => $post->post_title, // dla powitania w panelu
    ]);

    // Powiąż post z userem przez post_author
    wp_update_post([
        'ID'          => $post_id,
        'post_author' => $user_id,
    ]);

    // Wyślij wbudowane WP powiadomienie z linkiem do ustawienia hasła
    wp_new_user_notification($user_id, null, 'user');

    wp_send_json_success([
        'message' => 'Konto utworzone. Email z linkiem do ustawienia hasła został wysłany.',
        'reload'  => true,
        'user_id' => $user_id,
    ]);
}

// ─── AJAX: powiązanie istniejącego konta z postem ────────────────────────────

add_action('wp_ajax_np_psycholog_link_account', 'np_ajax_psycholog_link_account');

function np_ajax_psycholog_link_account(): void
{
    $post_id = (int) ($_POST['post_id'] ?? 0);

    if (! current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'Brak uprawnień'], 403);
    }
    if (! wp_verify_nonce((string) ($_POST['nonce'] ?? ''), 'np_psycholog_account_' . $post_id)) {
        wp_send_json_error(['message' => 'Nieprawidłowy token'], 403);
    }

    $email = sanitize_email((string) get_post_meta($post_id, 'email_kontaktowy', true));
    $user  = get_user_by('email', $email);
    if (! $user) {
        wp_send_json_error(['message' => 'Konto nie istnieje'], 404);
    }

    wp_update_post([
        'ID'          => $post_id,
        'post_author' => $user->ID,
    ]);

    wp_send_json_success([
        'message' => 'Post powiązany z kontem.',
        'reload'  => true,
    ]);
}

// ─── AJAX: ponowne wysłanie linku do hasła ───────────────────────────────────

add_action('wp_ajax_np_psycholog_resend_password_link', 'np_ajax_psycholog_resend_password_link');

function np_ajax_psycholog_resend_password_link(): void
{
    $post_id = (int) ($_POST['post_id'] ?? 0);

    if (! current_user_can('edit_post', $post_id)) {
        wp_send_json_error(['message' => 'Brak uprawnień'], 403);
    }
    if (! wp_verify_nonce((string) ($_POST['nonce'] ?? ''), 'np_psycholog_account_' . $post_id)) {
        wp_send_json_error(['message' => 'Nieprawidłowy token'], 403);
    }

    $email = sanitize_email((string) get_post_meta($post_id, 'email_kontaktowy', true));
    $user  = get_user_by('email', $email);
    if (! $user) {
        wp_send_json_error(['message' => 'Konto nie istnieje'], 404);
    }

    // get_password_reset_key() — ten sam mechanizm co "Zapomniałem hasła"
    $key = get_password_reset_key($user);
    if (is_wp_error($key)) {
        wp_send_json_error(['message' => 'Nie udało się wygenerować linku: ' . $key->get_error_message()], 500);
    }

    $message  = "Cześć " . $user->display_name . ",\n\n";
    $message .= "Aby ustawić hasło do panelu psychologa, kliknij w poniższy link:\n\n";
    $message .= network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user->user_login), 'login') . "\n\n";
    $message .= "Po ustawieniu hasła zaloguj się na: " . home_url(NP_PANEL_LOGIN_PATH) . "\n";

    $sent = wp_mail($email, 'Link do ustawienia hasła — Niepodzielni', $message);
    if (! $sent) {
        wp_send_json_error(['message' => 'Nie udało się wysłać emaila'], 500);
    }

    wp_send_json_success([
        'message' => 'Email z linkiem do hasła wysłany na ' . $email,
        'reload'  => false,
    ]);
}
