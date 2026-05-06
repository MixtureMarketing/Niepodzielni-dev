<?php

declare(strict_types=1);

namespace Niepodzielni\Forms\Helpers;

/**
 * Predefiniowane konfiguracje najczęściej używanych pól formularzy.
 *
 * Użycie: CommonFields::imie() lub CommonFields::imie(['required' => false])
 */
class CommonFields
{
    public static function imie(array $overrides = []): array
    {
        return array_merge([
            'label'        => 'Imię',
            'type'         => 'text',
            'required'     => true,
            'max_length'   => 50,
            'pattern'      => '^[\p{L}\s\'-]+$',
            'custom_error' => 'Imię może zawierać tylko litery, spacje, apostrofy i myślniki.',
            'sanitize'     => 'sanitize_text_field',
        ], $overrides);
    }

    public static function nazwisko(array $overrides = []): array
    {
        return array_merge([
            'label'        => 'Nazwisko',
            'type'         => 'text',
            'required'     => true,
            'max_length'   => 50,
            'pattern'      => '^[\p{L}\s\'-]+$',
            'custom_error' => 'Nazwisko może zawierać tylko litery, spacje, apostrofy i myślniki.',
            'sanitize'     => 'sanitize_text_field',
        ], $overrides);
    }

    public static function imieNazwisko(array $overrides = []): array
    {
        return array_merge([
            'label'        => 'Imię i nazwisko',
            'type'         => 'text',
            'required'     => true,
            'max_length'   => 100,
            'pattern'      => '^[\p{L}\s\'-]+$',
            'custom_error' => 'Pole może zawierać tylko litery, spacje, apostrofy i myślniki.',
            'sanitize'     => 'sanitize_text_field',
        ], $overrides);
    }

    public static function email(array $overrides = []): array
    {
        return array_merge([
            'label'    => 'Adres e-mail',
            'type'     => 'email',
            'required' => true,
            'sanitize' => 'sanitize_email',
        ], $overrides);
    }

    public static function telefon(string $prefixField = 'telefon_prefix', array $overrides = []): array
    {
        return array_merge([
            'label'        => 'Numer telefonu',
            'type'         => 'tel',
            'required'     => true,
            'pattern'      => '^[0-9]{7,15}$',
            'custom_error' => 'Numer telefonu musi zawierać od 7 do 15 cyfr.',
            'sanitize'     => 'sanitize_text_field',
            'prefix_field' => $prefixField,
        ], $overrides);
    }

    public static function telefonPrefix(array $overrides = []): array
    {
        return array_merge([
            'label'    => 'Kierunkowy',
            'type'     => 'select',
            'required' => true,
            'options'  => PhonePrefixes::getAll(),
        ], $overrides);
    }

    public static function kodPocztowy(array $overrides = []): array
    {
        return array_merge([
            'label'        => 'Kod pocztowy',
            'type'         => 'text',
            'required'     => true,
            'pattern'      => '^[0-9]{2}-[0-9]{3}$',
            'custom_error' => 'Podaj kod pocztowy w formacie 00-000.',
            'sanitize'     => 'sanitize_text_field',
        ], $overrides);
    }

    public static function miasto(array $overrides = []): array
    {
        return array_merge([
            'label'      => 'Miasto',
            'type'       => 'text',
            'required'   => true,
            'max_length' => 100,
            'sanitize'   => 'sanitize_text_field',
        ], $overrides);
    }

    public static function ulica(array $overrides = []): array
    {
        return array_merge([
            'label'      => 'Ulica i numer',
            'type'       => 'text',
            'required'   => true,
            'max_length' => 150,
            'sanitize'   => 'sanitize_text_field',
        ], $overrides);
    }

    public static function wiadomosc(array $overrides = []): array
    {
        return array_merge([
            'label'      => 'Wiadomość',
            'type'       => 'textarea',
            'required'   => true,
            'max_length' => 2000,
            'sanitize'   => 'sanitize_textarea_field',
        ], $overrides);
    }

    public static function temat(array $options = [], array $overrides = []): array
    {
        return array_merge([
            'label'    => 'Temat wiadomości',
            'type'     => 'select',
            'required' => true,
            'options'  => $options,
        ], $overrides);
    }

    public static function zgoda(string $label = 'Wyrażam zgodę na przetwarzanie danych osobowych.', array $overrides = []): array
    {
        return array_merge([
            'label'    => $label,
            'type'     => 'checkbox',
            'required' => true,
        ], $overrides);
    }

    public static function firma(array $overrides = []): array
    {
        return array_merge([
            'label'      => 'Nazwa firmy / instytucji',
            'type'       => 'text',
            'required'   => false,
            'max_length' => 150,
            'sanitize'   => 'sanitize_text_field',
        ], $overrides);
    }
}
