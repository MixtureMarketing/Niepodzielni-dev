<?php

/**
 * Opinie psychologów — REST API
 *
 * POST /wp-json/niepodzielni/v1/reviews
 *
 * Params: post_id, rating (1-5), email, content (opt),
 *         cf-turnstile-response (opt), magic_token (opt)
 *
 * Magic link (stateless):
 *   hash_hmac('sha256', "{email}|{post_id}", wp_salt('auth'))
 *   URL: /psycholog/slug/?magic_token=TOKEN&rvw_email=user@example.com
 */

if (! defined('ABSPATH')) {
    exit;
}

// ─── Rejestracja endpointu ────────────────────────────────────────────────────

add_action('rest_api_init', function (): void {
    register_rest_route('niepodzielni/v1', '/reviews', [
        'methods'             => 'POST',
        'callback'            => 'np_reviews_handle_submit',
        'permission_callback' => '__return_true',
    ]);
});

// ─── Callback ────────────────────────────────────────────────────────────────

function np_reviews_handle_submit(\WP_REST_Request $request): \WP_REST_Response
{
    $body   = $request->get_json_params() ?: (array) $request->get_params();
    $postId = (int) ($body['post_id'] ?? 0);
    $rating = (int) ($body['rating']  ?? 0);
    $email  = sanitize_email((string) ($body['email'] ?? ''));
    $content = sanitize_textarea_field((string) ($body['content'] ?? ''));
    $magicToken = sanitize_text_field((string) ($body['magic_token'] ?? ''));
    $rvwEmail   = sanitize_email((string) ($body['rvw_email'] ?? $email));

    // Walidacja posta
    $post = get_post($postId);
    if (! $post || $post->post_type !== 'psycholog' || $post->post_status !== 'publish') {
        return new \WP_REST_Response(['status' => 'error', 'message' => 'Nieprawidłowy profil.'], 404);
    }

    // Walidacja oceny
    if ($rating < 1 || $rating > 5) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Ocena musi być w skali 1–5.',
            'errors'  => ['rating' => 'Wybierz ocenę od 1 do 5.'],
        ], 422);
    }

    // ── Weryfikacja magic_token lub Turnstile ──────────────────────────────
    $magicValid = false;
    if ($magicToken && $rvwEmail) {
        $expected   = np_reviews_generate_magic_token($rvwEmail, $postId);
        $magicValid = hash_equals($expected, $magicToken);
        $email      = $rvwEmail;
    }

    if (! $magicValid) {
        // Weryfikacja Cloudflare Turnstile
        $tsToken = sanitize_text_field((string) ($body['cf-turnstile-response'] ?? ''));
        if (! np_reviews_verify_turnstile($tsToken, (string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            return new \WP_REST_Response([
                'status'  => 'error',
                'message' => 'Weryfikacja anty-spam nie powiodła się. Odśwież stronę i spróbuj ponownie.',
            ], 400);
        }
    }

    // Walidacja e-maila
    if (! is_email($email)) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Podaj prawidłowy adres e-mail.',
            'errors'  => ['email' => 'Podaj prawidłowy adres e-mail.'],
        ], 422);
    }

    // Duplikat: jeden email = jedna opinia na psychologa
    $existing = get_comments([
        'post_id'    => $postId,
        'type'       => 'review',
        'meta_key'   => '_reviewer_email',
        'meta_value' => $email,
        'count'      => true,
    ]);
    if ($existing > 0) {
        return new \WP_REST_Response([
            'status'  => 'error',
            'message' => 'Opinia z tego adresu e-mail została już dodana.',
        ], 409);
    }

    // ── Weryfikacja wizyty w Bookero ──────────────────────────────────────
    $verifiedVisit = false;
    if (! $magicValid) {
        $verifiedVisit = np_reviews_check_bookero_visit($postId, $email);
    } else {
        // Magic token = potwierdzona wizyta
        $verifiedVisit = true;
    }

    // ── Zapis komentarza ──────────────────────────────────────────────────
    $authorName = (string) ($body['author_name'] ?? '');
    if (! $authorName) {
        $authorName = explode('@', $email)[0];
    }
    $authorName = sanitize_text_field($authorName);

    $commentId = wp_insert_comment([
        'comment_post_ID'      => $postId,
        'comment_author'       => $authorName,
        'comment_author_email' => $email,
        'comment_content'      => $content,
        'comment_type'         => 'review',
        'comment_parent'       => 0,
        'comment_approved'     => 1,
        'user_id'              => 0,
    ]);

    if (! $commentId || is_wp_error($commentId)) {
        return new \WP_REST_Response(['status' => 'error', 'message' => 'Błąd zapisu opinii. Spróbuj ponownie.'], 500);
    }

    update_comment_meta((int) $commentId, '_rating', (string) $rating);
    update_comment_meta((int) $commentId, '_verified_visit', $verifiedVisit ? '1' : '0');
    update_comment_meta((int) $commentId, '_reviewer_email', $email);

    // ── Przelicz cache post_meta ──────────────────────────────────────────
    np_reviews_recalculate_rating($postId);

    // ── E-mail do psychologa ──────────────────────────────────────────────
    np_reviews_notify_psychologist($postId, (int) $commentId, $authorName, $rating, $content);

    return new \WP_REST_Response([
        'status'         => 'success',
        'message'        => 'Dziękujemy za opinię!',
        'verified_visit' => $verifiedVisit,
        'comment_id'     => $commentId,
    ], 201);
}

// ─── Magic token ─────────────────────────────────────────────────────────────

function np_reviews_generate_magic_token(string $email, int $postId): string
{
    return hash_hmac('sha256', strtolower(trim($email)) . '|' . $postId, wp_salt('auth'));
}

// ─── Weryfikacja Turnstile ────────────────────────────────────────────────────

function np_reviews_verify_turnstile(string $token, string $remoteIp = ''): bool
{
    $secret = '';
    foreach (['NP_CF_TURNSTILE_SECRET', 'CF_TURNSTILE_SECRET_KEY'] as $const) {
        if (defined($const) && constant($const)) {
            $secret = (string) constant($const);
            break;
        }
    }
    if (! $secret) {
        $secret = (string) get_option('np_cf_turnstile_secret', '');
    }
    if (! $secret) {
        return true; // tryb deweloperski
    }

    $body = ['secret' => $secret, 'response' => $token];
    if ($remoteIp) {
        $body['remoteip'] = $remoteIp;
    }

    $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 10,
        'body'    => $body,
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    return (bool) ($data['success'] ?? false);
}

// ─── Weryfikacja wizyty Bookero ───────────────────────────────────────────────

function np_reviews_check_bookero_visit(int $postId, string $email): bool
{
    try {
        $repo     = new \Niepodzielni\Bookero\PsychologistRepository();
        $client   = new \Niepodzielni\Bookero\BookeroApiClient();
        $workerIdPelny = $repo->getWorkerId($postId, 'pelnoplatny');
        $workerIdNisko = $repo->getWorkerId($postId, 'nisko');
        $calPelny = defined('NP_BOOKERO_CAL_ID_PELNY') ? (string) NP_BOOKERO_CAL_ID_PELNY : (string) get_option('np_bookero_cal_pelny', '');
        $calNisko = defined('NP_BOOKERO_CAL_ID_NISKO') ? (string) NP_BOOKERO_CAL_ID_NISKO : (string) get_option('np_bookero_cal_nisko', '');

        $pairs = array_filter([
            $workerIdPelny ? [$calPelny, $workerIdPelny] : null,
            $workerIdNisko ? [$calNisko, $workerIdNisko] : null,
        ]);

        foreach ($pairs as [$cal, $worker]) {
            if ($cal && $worker && $client->checkEmailBookedWithWorker($cal, $worker, $email)) {
                return true;
            }
        }
    } catch (\Throwable) {
        // Weryfikacja opcjonalna — błąd API = brak potwierdzenia
    }

    return false;
}

// ─── Cache ocen (post_meta) ───────────────────────────────────────────────────

function np_reviews_recalculate_rating(int $postId): void
{
    $comments = get_comments([
        'post_id' => $postId,
        'type'    => 'review',
        'status'  => 'approve',
        'parent'  => 0,
    ]);

    $total = 0;
    $count = 0;

    foreach ($comments as $comment) {
        $r = (int) get_comment_meta((int) $comment->comment_ID, '_rating', true);
        if ($r >= 1 && $r <= 5) {
            $total += $r;
            $count++;
        }
    }

    update_post_meta($postId, '_average_rating', $count > 0 ? round($total / $count, 1) : 0);
    update_post_meta($postId, '_reviews_count', $count);
}

// Hookuj przeliczanie przy usunięciu komentarza
add_action('deleted_comment', function (int $commentId): void {
    $comment = get_comment($commentId);
    if ($comment && $comment->comment_type === 'review') {
        np_reviews_recalculate_rating((int) $comment->comment_post_ID);
    }
});

add_action('trashed_comment', function (int $commentId): void {
    $comment = get_comment($commentId);
    if ($comment && $comment->comment_type === 'review') {
        np_reviews_recalculate_rating((int) $comment->comment_post_ID);
    }
});

// ─── E-mail do psychologa ─────────────────────────────────────────────────────

function np_reviews_notify_psychologist(int $postId, int $commentId, string $authorName, int $rating, string $content): void
{
    $post = get_post($postId);
    if (! $post || ! $post->post_author) {
        return;
    }

    $author = get_userdata((int) $post->post_author);
    if (! $author || ! $author->user_email) {
        return;
    }

    $siteName  = get_bloginfo('name');
    $postTitle = $post->post_title;
    $stars     = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
    $adminLink = admin_url("edit-comments.php?p={$postId}&type=review");

    $body = "<p>Hej {$author->display_name},</p>"
          . "<p>Dodano nową opinię do Twojego profilu <strong>{$postTitle}</strong>.</p>"
          . "<table cellpadding=\"8\" style=\"border-collapse:collapse;border:1px solid #eee;\">"
          . "<tr><th style=\"background:#f6f6f6;text-align:left\">Od</th><td>" . esc_html($authorName) . "</td></tr>"
          . "<tr><th style=\"background:#f6f6f6;text-align:left\">Ocena</th><td>{$stars} ({$rating}/5)</td></tr>"
          . ($content ? "<tr><th style=\"background:#f6f6f6;text-align:left\">Treść</th><td>" . nl2br(esc_html($content)) . "</td></tr>" : '')
          . "</table>"
          . "<p><a href=\"{$adminLink}\">Zobacz opinię w panelu admina</a></p>"
          . "<p>Pozdrawiamy, {$siteName}</p>";

    add_filter('wp_mail_content_type', static fn() => 'text/html');
    wp_mail($author->user_email, "[{$siteName}] Nowa opinia — {$postTitle}", $body);
    remove_filter('wp_mail_content_type', static fn() => 'text/html');
}

// ─── Enqueue ──────────────────────────────────────────────────────────────────

add_action('wp_enqueue_scripts', 'np_reviews_enqueue_assets');

function np_reviews_enqueue_assets(): void
{
    if (! is_singular('psycholog')) {
        return;
    }

    $jsPath = get_template_directory() . '/resources/js/reviews.js';
    $jsUrl  = get_template_directory_uri() . '/resources/js/reviews.js';
    if (! file_exists($jsPath)) {
        return;
    }

    wp_enqueue_script('np-reviews', $jsUrl, [], (string) filemtime($jsPath), true);

    $siteKey = '';
    foreach (['NP_CF_TURNSTILE_SITE_KEY', 'CF_TURNSTILE_SITE_KEY'] as $c) {
        if (defined($c) && constant($c)) {
            $siteKey = (string) constant($c);
            break;
        }
    }
    if (! $siteKey) {
        $siteKey = (string) get_option('np_cf_turnstile_site_key', '');
    }

    wp_localize_script('np-reviews', 'NpReviewsConfig', [
        'apiUrl'        => esc_url_raw(rest_url('niepodzielni/v1/reviews')),
        'turnstileSiteKey' => $siteKey,
        'magicToken'    => sanitize_text_field((string) ($_GET['magic_token'] ?? '')),
        'rvwEmail'      => sanitize_email((string) ($_GET['rvw_email'] ?? '')),
    ]);
}
