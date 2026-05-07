<?php

declare(strict_types=1);

namespace Niepodzielni\Donations;

/**
 * Klient Stripe dla darowizn — Checkout (one-off + subscription) + webhooki.
 *
 * Klucze pobierane z env (NP_STRIPE_*) lub wp_options jako fallback.
 * Lazy-load: Stripe SDK jest dostępne dopiero po composer install — w testach
 * jednostkowych klasa nie importuje SDK na poziomie pliku, dopiero przy
 * pierwszym wywołaniu metody publicznej.
 *
 * Wszystkie kwoty w GROSZACH (cents) — zgodnie z konwencją Stripe.
 */
class StripeClient
{
    public const CURRENCY        = 'PLN';
    public const MIN_AMOUNT_PLN  = 5;       // 500 groszy
    public const MAX_AMOUNT_PLN  = 50_000;  // 5 000 000 groszy

    private string $secretKey;

    /**
     * @throws DonationsApiException gdy klucz secret nie jest skonfigurowany.
     */
    public function __construct(?string $secretKey = null)
    {
        $this->secretKey = $secretKey ?? self::resolveSecretKey();

        if ($this->secretKey === '') {
            throw new DonationsApiException(
                'Brak klucza Stripe (NP_STRIPE_SECRET_KEY). Skonfiguruj w .env lub WP options.',
                'config',
            );
        }
    }

    /**
     * Tworzy sesję Checkout dla jednorazowej darowizny.
     *
     * @param array<string, string> $metadata Doklejane do PaymentIntent — np. ['donation_id' => '123', 'source' => 'home']
     * @return array{checkout_url: string, session_id: string}
     * @throws DonationsApiException
     */
    public function createCheckoutSessionOneOff(
        int $amountCents,
        string $email,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
    ): array {
        $this->ensureAmount($amountCents);
        $this->ensureSdk();

        \Stripe\Stripe::setApiKey($this->secretKey);

        try {
            $session = \Stripe\Checkout\Session::create([
                'mode'                  => 'payment',
                'payment_method_types'  => ['card', 'p24', 'blik'],
                'customer_email'        => $email !== '' ? $email : null,
                'line_items'            => [[
                    'price_data' => [
                        'currency'     => strtolower(self::CURRENCY),
                        'unit_amount'  => $amountCents,
                        'product_data' => [
                            'name'        => 'Darowizna na rzecz Fundacji Niepodzielni',
                            'description' => 'Wsparcie wybranej osoby w trudnym momencie życiowym.',
                        ],
                    ],
                    'quantity'   => 1,
                ]],
                'success_url'           => $successUrl,
                'cancel_url'            => $cancelUrl,
                'metadata'              => $metadata,
                'payment_intent_data'   => [
                    'metadata' => $metadata,
                ],
            ]);
        } catch (\Throwable $e) {
            throw new DonationsApiException(
                'Stripe Checkout one-off failed: ' . $e->getMessage(),
                'checkout_one_off',
                $e,
            );
        }

        return [
            'checkout_url' => (string) ($session->url ?? ''),
            'session_id'   => (string) ($session->id  ?? ''),
        ];
    }

    /**
     * Tworzy sesję Checkout dla subskrypcji miesięcznej.
     *
     * Wymaga że konto Stripe ma włączone recurring w PLN.
     *
     * @param array<string, string> $metadata
     * @return array{checkout_url: string, session_id: string}
     * @throws DonationsApiException
     */
    public function createCheckoutSessionSubscription(
        int $amountCents,
        string $email,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
    ): array {
        $this->ensureAmount($amountCents);
        $this->ensureSdk();

        \Stripe\Stripe::setApiKey($this->secretKey);

        try {
            $session = \Stripe\Checkout\Session::create([
                'mode'                 => 'subscription',
                'payment_method_types' => ['card'],
                'customer_email'       => $email !== '' ? $email : null,
                'line_items'           => [[
                    'price_data' => [
                        'currency'     => strtolower(self::CURRENCY),
                        'unit_amount'  => $amountCents,
                        'recurring'    => ['interval' => 'month'],
                        'product_data' => [
                            'name'        => 'Comiesięczne wsparcie Fundacji Niepodzielni',
                            'description' => 'Stałe wsparcie wybranej osoby w trudnym momencie życiowym.',
                        ],
                    ],
                    'quantity'   => 1,
                ]],
                'success_url'          => $successUrl,
                'cancel_url'           => $cancelUrl,
                'metadata'             => $metadata,
                'subscription_data'    => [
                    'metadata' => $metadata,
                ],
            ]);
        } catch (\Throwable $e) {
            throw new DonationsApiException(
                'Stripe Checkout subscription failed: ' . $e->getMessage(),
                'checkout_subscription',
                $e,
            );
        }

        return [
            'checkout_url' => (string) ($session->url ?? ''),
            'session_id'   => (string) ($session->id  ?? ''),
        ];
    }

    /**
     * Weryfikuje sygnaturę Stripe webhooka i zwraca zdekodowany Event.
     *
     * @throws DonationsApiException przy nieprawidłowej sygnaturze.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader, ?string $secret = null): \Stripe\Event
    {
        $this->ensureSdk();
        $whSecret = $secret ?? self::resolveWebhookSecret();

        if ($whSecret === '') {
            throw new DonationsApiException(
                'Brak NP_STRIPE_WEBHOOK_SECRET — webhook nie może być zweryfikowany.',
                'webhook_config',
            );
        }

        try {
            return \Stripe\Webhook::constructEvent($payload, $signatureHeader, $whSecret);
        } catch (\Throwable $e) {
            throw new DonationsApiException(
                'Stripe webhook signature verification failed: ' . $e->getMessage(),
                'webhook_signature',
                $e,
            );
        }
    }

    /**
     * Resolved secret key z env (priorytet) → wp_option.
     *
     * Helper publiczny żeby admin notice w settings mógł sprawdzić, czy klucz
     * istnieje, bez pełnej instancjacji klienta.
     */
    public static function resolveSecretKey(): string
    {
        $envKey = getenv('NP_STRIPE_SECRET_KEY');
        if ($envKey !== false && $envKey !== '') {
            return (string) $envKey;
        }

        return (string) (function_exists('get_option') ? get_option('np_stripe_secret_key', '') : '');
    }

    public static function resolvePublishableKey(): string
    {
        $envKey = getenv('NP_STRIPE_PUBLISHABLE_KEY');
        if ($envKey !== false && $envKey !== '') {
            return (string) $envKey;
        }

        return (string) (function_exists('get_option') ? get_option('np_stripe_publishable_key', '') : '');
    }

    public static function resolveWebhookSecret(): string
    {
        $envKey = getenv('NP_STRIPE_WEBHOOK_SECRET');
        if ($envKey !== false && $envKey !== '') {
            return (string) $envKey;
        }

        return (string) (function_exists('get_option') ? get_option('np_stripe_webhook_secret', '') : '');
    }

    /**
     * @throws DonationsApiException gdy kwota poza granicami.
     */
    private function ensureAmount(int $amountCents): void
    {
        $minCents = self::MIN_AMOUNT_PLN * 100;
        $maxCents = self::MAX_AMOUNT_PLN * 100;
        if ($amountCents < $minCents || $amountCents > $maxCents) {
            throw new DonationsApiException(
                sprintf('Kwota poza zakresem (%d–%d PLN). Otrzymano: %d groszy.', self::MIN_AMOUNT_PLN, self::MAX_AMOUNT_PLN, $amountCents),
                'amount',
            );
        }
    }

    /**
     * @throws DonationsApiException gdy SDK Stripe niedostępny.
     */
    private function ensureSdk(): void
    {
        if (! class_exists(\Stripe\Stripe::class)) {
            throw new DonationsApiException(
                'Stripe SDK nie jest zainstalowane. Uruchom `composer install` w katalogu projektu.',
                'sdk_missing',
            );
        }
    }
}
