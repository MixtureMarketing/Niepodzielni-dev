<?php

declare(strict_types=1);

namespace Niepodzielni\Forms;

use Niepodzielni\Forms\Helpers\CommonFields;
use Niepodzielni\Forms\Helpers\PhonePrefixes;

/**
 * Formularz kontaktowy — testuje pełny zakres pól frameworka.
 */
class ContactForm extends BaseFormHandler
{
    protected bool $saveToDb            = true;
    protected bool $userConfirmation    = true;
    protected bool $requireVerification = false;

    public function getFormId(): string
    {
        return 'contact';
    }

    protected function getFields(): array
    {
        return [
            'imie'                => CommonFields::imie(),
            'nazwisko'            => CommonFields::nazwisko(),
            'kod_pocztowy'        => CommonFields::kodPocztowy(),
            'miasto'              => CommonFields::miasto(),
            'ulica'               => CommonFields::ulica(),
            'telefon_prefix'      => CommonFields::telefonPrefix(),
            'telefon'             => CommonFields::telefon(),
            'email'               => CommonFields::email(),
            'temat'               => CommonFields::temat([
                'ogolne'     => 'Ogólne zapytania',
                'wspolpraca' => 'Współpraca',
                'pomoc'      => 'Pomoc psychologiczna',
            ]),
            'preferowany_kontakt' => [
                'label'    => 'Preferowany kontakt',
                'type'     => 'radio',
                'required' => true,
                'options'  => [
                    'email'   => 'E-mail',
                    'telefon' => 'Telefon',
                ],
            ],
            'wiadomosc'           => CommonFields::wiadomosc(),
            'zgoda'               => CommonFields::zgoda(
                'Wyrażam zgodę na przetwarzanie danych osobowych zgodnie z Polityką prywatności.'
            ),
        ];
    }

    /**
     * Walidacja relacyjna: długość numeru telefonu zależna od wybranego kierunkowego.
     */
    protected function validateRelated(array $sanitized, array $raw): array
    {
        $errors = [];

        $prefix = (string) ($sanitized['telefon_prefix'] ?? '');
        $phone  = (string) ($sanitized['telefon'] ?? '');

        if ($prefix === '' || $phone === '') {
            return $errors;
        }

        $prefixes = PhonePrefixes::getAll();
        if (! isset($prefixes[$prefix])) {
            return $errors;
        }

        $len = mb_strlen($phone);
        $min = $prefixes[$prefix]['min'];
        $max = $prefixes[$prefix]['max'];

        if ($len < $min || $len > $max) {
            $errors['telefon'] = $min === $max
                ? "Dla kierunkowego {$prefix} numer telefonu musi mieć dokładnie {$min} cyfr."
                : "Dla kierunkowego {$prefix} numer telefonu musi mieć od {$min} do {$max} cyfr.";
        }

        return $errors;
    }

    protected function getUserConfirmationBody(array $formData, string $siteName): string
    {
        $name = esc_html((string) ($formData['imie'] ?? ''));
        return "<p>Drogi/a {$name},</p>"
             . "<p>Dziękujemy za przesłanie wiadomości do {$siteName}. Odpowiemy w ciągu 1–2 dni roboczych.</p>"
             . "<p>Z poważaniem,<br>Zespół {$siteName}</p>";
    }
}
