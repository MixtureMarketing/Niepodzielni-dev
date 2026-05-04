<?php

declare(strict_types=1);

namespace Niepodzielni\Forms;

/**
 * Abstrakcyjna klasa bazowa dla wszystkich handlerów formularzy.
 *
 * Klasy potomne muszą zaimplementować getFormId() i getFields().
 * Pozostałe właściwości ($saveToDb, $adminEmail itd.) można nadpisać w child class.
 */
abstract class BaseFormHandler
{
    protected bool    $saveToDb            = true;
    protected ?string $adminEmail          = null;
    protected bool    $userConfirmation    = false;
    protected bool    $requireVerification = false;

    abstract public function getFormId(): string;

    /**
     * Zwraca konfigurację pól formularza.
     *
     * Format jednego pola:
     * [
     *   'label'      => 'Imię i nazwisko',
     *   'type'       => 'text' | 'email' | 'textarea' | 'checkbox',
     *   'required'   => true,
     *   'max_length' => 255,
     *   'sanitize'   => 'sanitize_text_field', // nazwa funkcji WP lub callable
     * ]
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function getFields(): array;

    // ─── Walidacja ────────────────────────────────────────────────────────────

    /**
     * Waliduje i sanityzuje przesłane dane.
     *
     * @param  array<string, mixed>  $data  Surowe dane z requestu.
     * @return array{ errors: array<string,string>, sanitized: array<string,mixed> }
     */
    public function validate(array $data): array
    {
        $errors    = [];
        $sanitized = [];

        foreach ($this->getFields() as $name => $config) {
            $value    = $data[$name] ?? null;
            $label    = $config['label'] ?? ucfirst($name);
            $type     = $config['type']  ?? 'text';
            $required = $config['required'] ?? false;

            // Checkbox: wartość "on", "1", true lub dowolna truthy
            if ($type === 'checkbox') {
                $sanitized[$name] = ! empty($value);
                if ($required && ! $sanitized[$name]) {
                    $errors[$name] = "Pole „{$label}" jest wymagane.";
                }
                continue;
            }

            $value = is_string($value) ? trim($value) : '';

            if ($required && $value === '') {
                $errors[$name] = "Pole „{$label}" jest wymagane.";
                $sanitized[$name] = '';
                continue;
            }

            // Sanityzacja
            $sanitizeFn = $config['sanitize'] ?? 'sanitize_text_field';
            $sanitized[$name] = is_callable($sanitizeFn) ? $sanitizeFn($value) : sanitize_text_field($value);

            // Walidacja typu
            if ($type === 'email' && $value !== '') {
                if (! is_email($sanitized[$name])) {
                    $errors[$name] = "Pole „{$label}" musi być prawidłowym adresem e-mail.";
                }
            }

            // Maksymalna długość
            if (isset($config['max_length']) && mb_strlen((string) $sanitized[$name]) > (int) $config['max_length']) {
                $errors[$name] = "Pole „{$label}" może mieć maksymalnie {$config['max_length']} znaków.";
            }

            // Wzorzec regex (opcjonalny)
            if (isset($config['pattern']) && $value !== '' && ! preg_match('/' . $config['pattern'] . '/', (string) $sanitized[$name])) {
                $errors[$name] = $config['pattern_message'] ?? "Pole „{$label}" ma nieprawidłowy format.";
            }
        }

        return ['errors' => $errors, 'sanitized' => $sanitized];
    }

    // ─── Zapis do bazy ────────────────────────────────────────────────────────

    /**
     * Tworzy rekord CPT `zgloszenie` z przesłanymi danymi.
     *
     * @param  array<string, mixed>  $sanitized   Zwalidowane i zsanityzowane dane.
     * @param  string                $sourceUrl   URL strony, z której przyszło zgłoszenie.
     * @return int  ID nowo utworzonego wpisu.
     */
    public function saveSubmission(array $sanitized, string $sourceUrl = ''): int
    {
        $email = '';
        foreach ($this->getFields() as $name => $cfg) {
            if (($cfg['type'] ?? '') === 'email' && ! empty($sanitized[$name])) {
                $email = (string) $sanitized[$name];
                break;
            }
        }

        $title = sprintf(
            'Zgłoszenie [%s] — %s',
            $this->getFormId(),
            current_time('Y-m-d H:i:s')
        );

        $postId = wp_insert_post([
            'post_type'   => 'zgloszenie',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_author' => 0,
        ], true);

        if (is_wp_error($postId)) {
            return 0;
        }

        update_post_meta($postId, '_form_id',    sanitize_text_field($this->getFormId()));
        update_post_meta($postId, '_form_data',  wp_json_encode($sanitized));
        update_post_meta($postId, '_source_url', esc_url_raw($sourceUrl));
        update_post_meta($postId, '_user_email', sanitize_email($email));
        update_post_meta($postId, '_verified',   $this->requireVerification ? '0' : '1');

        return $postId;
    }

    // ─── E-maile ──────────────────────────────────────────────────────────────

    /**
     * Wysyła e-maile: do admina oraz opcjonalne potwierdzenie do użytkownika.
     */
    public function sendEmails(int $submissionId): void
    {
        $formData = json_decode((string) get_post_meta($submissionId, '_form_data', true), true);
        if (! is_array($formData)) {
            return;
        }

        $siteName  = get_bloginfo('name');
        $adminEmail = $this->adminEmail ?: get_option('admin_email');
        $userEmail  = (string) get_post_meta($submissionId, '_user_email', true);
        $sourceUrl  = (string) get_post_meta($submissionId, '_source_url', true);

        // ── Mail do admina ──
        $adminBody  = "<p>Nowe zgłoszenie z formularza <strong>{$this->getFormId()}</strong> na stronie {$siteName}.</p>";
        $adminBody .= '<table cellpadding="8" cellspacing="0" border="1" style="border-collapse:collapse;">';
        foreach ($formData as $key => $val) {
            $display = is_bool($val) ? ($val ? 'Tak' : 'Nie') : esc_html((string) $val);
            $adminBody .= '<tr><th style="text-align:left;background:#f0f0f0;">' . esc_html(ucfirst(str_replace('_', ' ', $key))) . '</th><td>' . $display . '</td></tr>';
        }
        if ($sourceUrl) {
            $adminBody .= '<tr><th style="text-align:left;background:#f0f0f0;">URL źródłowy</th><td>' . esc_html($sourceUrl) . '</td></tr>';
        }
        $adminBody .= '</table>';

        add_filter('wp_mail_content_type', static fn () => 'text/html');

        wp_mail(
            $adminEmail,
            "[{$siteName}] Nowe zgłoszenie — {$this->getFormId()}",
            $adminBody
        );

        // ── Potwierdzenie dla użytkownika ──
        if ($this->userConfirmation && $userEmail) {
            $userBody = $this->getUserConfirmationBody($formData, $siteName);
            wp_mail(
                $userEmail,
                "[{$siteName}] Potwierdzenie zgłoszenia",
                $userBody
            );
        }

        remove_filter('wp_mail_content_type', static fn () => 'text/html');
    }

    /**
     * Treść maila potwierdzającego dla użytkownika — klasy potomne mogą nadpisać.
     *
     * @param  array<string, mixed>  $formData
     */
    protected function getUserConfirmationBody(array $formData, string $siteName): string
    {
        return "<p>Dziękujemy za kontakt z {$siteName}. Otrzymaliśmy Twoje zgłoszenie i odpowiemy wkrótce.</p>";
    }

    // ─── OTP ─────────────────────────────────────────────────────────────────

    /**
     * Generuje 6-cyfrowy kod OTP, zapisuje jego hash do bazy i wysyła na e-mail użytkownika.
     */
    public function generateAndSendOTP(int $submissionId): bool
    {
        $userEmail = (string) get_post_meta($submissionId, '_user_email', true);
        if (! $userEmail) {
            return false;
        }

        $code    = (string) random_int(100000, 999999);
        $hash    = hash_hmac('sha256', $code, wp_salt('auth'));
        $expires = time() + (15 * MINUTE_IN_SECONDS);

        update_post_meta($submissionId, '_otp_hash',       $hash);
        update_post_meta($submissionId, '_otp_expires_at', (string) $expires);

        $siteName = get_bloginfo('name');
        $body     = "<p>Twój kod weryfikacyjny dla {$siteName}:</p>"
                  . "<h2 style=\"letter-spacing:8px;font-size:32px;\">{$code}</h2>"
                  . "<p>Kod jest ważny przez 15 minut.</p>";

        add_filter('wp_mail_content_type', static fn () => 'text/html');
        $sent = wp_mail(
            $userEmail,
            "[{$siteName}] Kod weryfikacyjny",
            $body
        );
        remove_filter('wp_mail_content_type', static fn () => 'text/html');

        return $sent;
    }

    /**
     * Weryfikuje kod OTP. Jeśli poprawny — oznacza zgłoszenie jako zweryfikowane
     * i wywołuje sendEmails().
     */
    public function verifyOTP(int $submissionId, string $code): bool
    {
        $storedHash = (string) get_post_meta($submissionId, '_otp_hash', true);
        $expires    = (int)   get_post_meta($submissionId, '_otp_expires_at', true);

        if (! $storedHash || ! $expires) {
            return false;
        }

        if (time() > $expires) {
            return false;
        }

        $providedHash = hash_hmac('sha256', trim($code), wp_salt('auth'));
        if (! hash_equals($storedHash, $providedHash)) {
            return false;
        }

        update_post_meta($submissionId, '_verified', '1');
        delete_post_meta($submissionId, '_otp_hash');
        delete_post_meta($submissionId, '_otp_expires_at');

        $this->sendEmails($submissionId);

        return true;
    }

    // ─── Gettery konfiguracji ─────────────────────────────────────────────────

    public function requiresVerification(): bool
    {
        return $this->requireVerification;
    }

    public function shouldSaveToDb(): bool
    {
        return $this->saveToDb;
    }
}
