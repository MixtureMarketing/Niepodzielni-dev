<?php

/**
 * Sentry — inicjalizacja zbierania błędów PHP.
 *
 * Aktywne tylko gdy `SENTRY_DSN` jest ustawione w `.env` (przez Trellis vault).
 * Lokalny dev z pustym DSN — early return, brak narzutu, brak ruchu.
 *
 * Filtr `before_send` usuwa z eventu body POST i cookies — żeby PII
 * z formularza kontaktowego (email, telefon, treść zgłoszenia) nie
 * wycieknął do Sentry. Branża psychoterapii → wrażliwe dane.
 *
 * Konfiguracja po stronie panelu Sentry:
 *   - Sentry → Project → Settings → Inbound Filters → włącz
 *     "Filter Out Errors From Web Crawlers" + "Filter Out Health Check
 *     Failures" (oszczędność quotę 5k events/mies free tier).
 *   - Sentry → Project → Alerts → "A new issue is created" → wyślij
 *     do webhooka Discord (sufix `/slack` na URL — Discord rozumie).
 *
 * Dokumentacja: docs/monitoring-runbook.md sekcja 4.3.
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('SENTRY_DSN') || empty(SENTRY_DSN)) {
    return;
}

\Sentry\init([
    'dsn'                => (string) SENTRY_DSN,
    'environment'        => defined('WP_ENV') ? (string) WP_ENV : 'unknown',
    'release'            => defined('NP_RELEASE') ? (string) NP_RELEASE : null,
    'sample_rate'        => 1.0,    // wszystkie błędy (free tier 5k/mies wystarcza)
    'traces_sample_rate' => 0.1,    // 10% requestów dla performance traces
    // ── PII filtering: wyrzuć body POST i cookies przed wysłaniem ──
    'before_send' => static function (\Sentry\Event $event): ?\Sentry\Event {
        $request = $event->getRequest();
        if (! empty($request)) {
            unset($request['data']);          // POST body
            unset($request['cookies']);       // cookies (mogą zawierać auth tokens)
            unset($request['env']);           // niektóre $_SERVER mogą zawierać IP/UA z PII
            $event->setRequest($request);
        }
        return $event;
    },
]);
