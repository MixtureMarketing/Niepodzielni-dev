{{-- Template Name: Panel — Logowanie --}}

@extends('layouts.app')

@php
    // Zalogowany psycholog → od razu na dashboard
    if (is_user_logged_in()) {
        $current = wp_get_current_user();
        if (in_array('psycholog', (array) $current->roles, true) || current_user_can('manage_options')) {
            wp_safe_redirect(home_url('/panel/'));
            exit;
        }
    }

    $login_error = $_GET['login'] ?? '';
@endphp

@section('content')
<div class="panel-page panel-page--login">
    <div class="panel-login">
        <div class="panel-login__card">
            <h1 class="panel-login__title">Panel psychologa</h1>
            <p class="panel-login__subtitle">Zaloguj się aby zarządzać swoim profilem</p>

            @if ($login_error === 'failed')
                <div class="panel-toast panel-toast--error" style="margin-bottom:16px">
                    Nieprawidłowy login lub hasło.
                </div>
            @endif

            {!! wp_login_form([
                'echo'           => false,
                'redirect'       => home_url('/panel/'),
                'form_id'        => 'panel-login-form',
                'label_username' => 'Email lub login',
                'label_password' => 'Hasło',
                'label_remember' => 'Zapamiętaj mnie',
                'label_log_in'   => 'Zaloguj się',
                'remember'       => true,
                'value_remember' => true,
            ]) !!}

            <p class="panel-login__forgot">
                <a href="{{ wp_lostpassword_url(home_url('/panel/logowanie/')) }}">
                    Nie pamiętam hasła
                </a>
            </p>
        </div>
    </div>
</div>
@endsection
