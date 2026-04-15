<?php

/**
 * Contact form handler — processes POST from template-kontakt.blade.php
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('admin_post_nopriv_np_contact_form', 'np_handle_contact_form');
add_action('admin_post_np_contact_form', 'np_handle_contact_form');

function np_handle_contact_form(): void
{
    if (! isset($_POST['np_contact_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['np_contact_nonce'])), 'np_contact_form')) {
        wp_safe_redirect(add_query_arg('kontakt', 'blad', wp_get_referer()));
        exit;
    }

    $imie      = sanitize_text_field(wp_unslash($_POST['imie']      ?? ''));
    $nazwisko  = sanitize_text_field(wp_unslash($_POST['nazwisko']  ?? ''));
    $telefon   = sanitize_text_field(wp_unslash($_POST['telefon']   ?? ''));
    $email     = sanitize_email(wp_unslash($_POST['email']     ?? ''));
    $wiadomosc = sanitize_textarea_field(wp_unslash($_POST['wiadomosc'] ?? ''));

    if (empty($imie) || empty($email) || empty($wiadomosc) || ! is_email($email)) {
        wp_safe_redirect(add_query_arg('kontakt', 'blad', wp_get_referer()));
        exit;
    }

    $to      = 'kontakt@niepodzielni.com';
    $subject = 'Wiadomość z formularza kontaktowego — ' . $imie . ' ' . $nazwisko;
    $body    = "Imię i nazwisko: {$imie} {$nazwisko}\n"
             . "E-mail: {$email}\n"
             . "Telefon: {$telefon}\n\n"
             . "Wiadomość:\n{$wiadomosc}";
    $headers = [ 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $email ];

    $sent = wp_mail($to, $subject, $body, $headers);

    wp_safe_redirect(add_query_arg('kontakt', $sent ? 'ok' : 'blad', wp_get_referer()));
    exit;
}
