<?php

/**
 * Customizacja strony logowania WP — logo, kolory, typografia marki.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('login_enqueue_scripts', 'np_login_styles');

function np_login_styles(): void
{
    ?>
    <style>
        body.login {
            background: #F9F8F6;
            font-family: 'Roboto', sans-serif;
        }

        body.login #login {
            padding-top: 40px;
        }

        /* Logo */
        body.login h1 a {
            background-image: url('https://media.niepodzielni.com/wp-content/uploads/20260330165908/Clip-path-group.svg') !important;
            background-size: contain !important;
            background-repeat: no-repeat !important;
            background-position: center !important;
            width: 220px !important;
            height: 72px !important;
            margin-bottom: 24px;
        }

        /* Formularz */
        body.login #loginform,
        body.login #lostpasswordform,
        body.login #registerform {
            background: #ffffff;
            border: none;
            border-radius: 20px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.07);
            padding: 32px 36px;
        }

        body.login .login-action-lostpassword #loginform,
        body.login .login-action-register #loginform {
            display: none;
        }

        /* Etykiety pól */
        body.login form .forminput label,
        body.login label {
            color: #323232;
            font-weight: 500;
            font-size: 14px;
        }

        /* Pola input */
        body.login input[type="text"],
        body.login input[type="password"],
        body.login input[type="email"] {
            border: 1px solid #D9D9D9 !important;
            border-radius: 10px !important;
            box-shadow: none !important;
            padding: 10px 14px !important;
            font-size: 15px;
            transition: border-color .2s;
        }

        body.login input[type="text"]:focus,
        body.login input[type="password"]:focus,
        body.login input[type="email"]:focus {
            border-color: #01BE4A !important;
            box-shadow: 0 0 0 2px rgba(1, 190, 74, .15) !important;
            outline: none;
        }

        /* Przycisk Zaloguj */
        body.login .button-primary,
        body.login input[type="submit"] {
            background: #01BE4A !important;
            border: none !important;
            border-radius: 50px !important;
            box-shadow: none !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            font-size: 15px !important;
            padding: 12px 28px !important;
            height: auto !important;
            width: auto !important;
            transition: background .2s, transform .2s;
            text-shadow: none !important;
        }

        body.login .button-primary:hover,
        body.login input[type="submit"]:hover {
            background: #01a040 !important;
            transform: translateY(-1px);
        }

        body.login .button-primary:focus,
        body.login input[type="submit"]:focus {
            box-shadow: 0 0 0 2px rgba(1, 190, 74, .35) !important;
        }

        /* Linki pod formularzem */
        body.login #nav a,
        body.login #backtoblog a {
            color: #1500BB;
            text-decoration: none;
            font-size: 13px;
        }

        body.login #nav a:hover,
        body.login #backtoblog a:hover {
            color: #01BE4A;
        }

        body.login #backtoblog {
            text-align: center;
        }

        /* Checkbox "Pamiętaj mnie" */
        body.login .forgetmenot label {
            color: #828282;
            font-size: 13px;
        }
    </style>
    <?php
}

// Link logo → strona główna (zamiast wordpress.org)
add_filter('login_headerurl', fn() => home_url('/'));

// Tytuł atrybutu logo
add_filter('login_headertext', fn() => get_bloginfo('name'));
