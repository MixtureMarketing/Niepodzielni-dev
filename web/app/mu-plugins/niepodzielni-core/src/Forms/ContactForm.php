<?php

declare(strict_types=1);

namespace Niepodzielni\Forms;

/**
 * Formularz kontaktowy — przykładowa implementacja BaseFormHandler.
 *
 * Pola: imie, email, wiadomosc, zgoda (RODO).
 * Weryfikacja OTP wyłączona; zapis do DB i mail do admina włączone.
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
            'imie' => [
                'label'      => 'Imię',
                'type'       => 'text',
                'required'   => true,
                'max_length' => 50,
                'sanitize'   => 'sanitize_text_field',
            ],
            'nazwisko' => [
                'label'      => 'Nazwisko',
                'type'       => 'text',
                'required'   => false,
                'max_length' => 50,
                'sanitize'   => 'sanitize_text_field',
            ],
            'telefon' => [
                'label'      => 'Numer telefonu',
                'type'       => 'tel',
                'required'   => false,
                'max_length' => 20,
                'sanitize'   => 'sanitize_text_field',
            ],
            'email' => [
                'label'    => 'Adres e-mail',
                'type'     => 'email',
                'required' => true,
                'sanitize' => 'sanitize_email',
            ],
            'wiadomosc' => [
                'label'      => 'Wiadomość',
                'type'       => 'textarea',
                'required'   => true,
                'max_length' => 2000,
                'sanitize'   => 'sanitize_textarea_field',
            ],
            'zgoda' => [
                'label'    => 'Wyrażam zgodę na przetwarzanie danych osobowych',
                'type'     => 'checkbox',
                'required' => true,
            ],
        ];
    }

    protected function getUserConfirmationBody(array $formData, string $siteName): string
    {
        $name = esc_html((string) ($formData['imie'] ?? ''));
        return "<p>Drogi/a {$name},</p>"
             . "<p>Dziękujemy za przesłanie wiadomości do {$siteName}. Odpowiemy w ciągu 1–2 dni roboczych.</p>"
             . "<p>Z poważaniem,<br>Zespół {$siteName}</p>";
    }
}
