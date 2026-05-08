<?php

declare(strict_types=1);

/**
 * Migration: utf8mb3 → utf8mb4 charset upgrade
 * Date: 2026-05-08
 *
 * Run via: wp np migrate charset-utf8mb4 [--dry-run] [--yes]
 *
 * Audit DB dev.niepodzielni.com (2026-05-08, M1) wykazał, że tabele core WP
 * są wciąż w utf8mb3_general_ci (przestarzałe, max 3 bajty/znak — brak emoji,
 * brak rzadszych symboli Unicode). Tabele "nowe" (po PR audit) są już
 * w utf8mb4_unicode_520_ci. Mix collation = JOIN-y robią filesort i index
 * scan zamiast index seek.
 *
 * KRYTYCZNE PRE-REQUISITES:
 *  1. Pełny backup DB (mysqldump --single-transaction). ALTER TABLE blokuje
 *     tabelę na czas konwersji (zwykle <30s na DB ~30 MB; większe tabele
 *     wp_postmeta / wp_options mogą zająć więcej).
 *  2. Okno serwisowe — żaden plugin / theme / cron nie zapisuje w trakcie.
 *  3. wp-config.php MUSI mieć:
 *        define('DB_CHARSET', 'utf8mb4');
 *        define('DB_COLLATE', 'utf8mb4_unicode_520_ci');
 *     Inaczej WordPress przy następnym INSERT spróbuje pisać w starym
 *     charsecie — sprawdzane w pre-flight (subcommand --yes).
 *
 * Idempotencja:
 *  - Wybiera TYLKO tabele z TABLE_COLLATION LIKE 'utf8mb3%'.
 *  - Tabele już w utf8mb4_* są pomijane (no-op).
 *  - Każda ALTER TABLE jest osobnym wpdb->query — raportujemy per-table,
 *    failure jednej tabeli nie zatrzymuje pozostałych (loguje do error_log).
 *
 * NIE konwertuje:
 *  - tabel spoza TABLE_SCHEMA = DATABASE() (czyli tylko bieżąca baza WP),
 *  - tabel z `BINARY` / `BLOB` w sposób destrukcyjny — CONVERT TO CHARACTER SET
 *    automatycznie zachowuje typy binarne (MySQL 5.7+/MariaDB 10.x docs).
 */

namespace Niepodzielni\Core\Migrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @param  array{dry_run?: bool, yes?: bool}  $options
 * @return array{
 *     name: string,
 *     status: string,
 *     message: string,
 *     duration_ms: float,
 *     tables_converted: int,
 *     tables: array<int,string>,
 *     failed: array<int,string>,
 * }
 */
function np_migration_2026_05_charset_utf8mb4(array $options = []): array
{
    global $wpdb;

    $dryRun = (bool) ($options['dry_run'] ?? false);
    $yes = (bool) ($options['yes'] ?? false);

    $start = microtime(true);

    if (! $yes && ! $dryRun) {
        return [
            'name' => '2026-05-charset-utf8mb4',
            'status' => 'skipped',
            'message' => 'Wymaga --yes (mutacja) lub --dry-run (preview).',
            'duration_ms' => 0.0,
            'tables_converted' => 0,
            'tables' => [],
            'failed' => [],
        ];
    }

    // Pobierz tabele w utf8mb3 z bieżącej bazy.
    /** @var array<int, object{TABLE_NAME: string, TABLE_COLLATION: string}> $rows */
    $rows = $wpdb->get_results(
        "SELECT TABLE_NAME, TABLE_COLLATION
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_TYPE = 'BASE TABLE'
            AND TABLE_COLLATION LIKE 'utf8mb3%'
          ORDER BY TABLE_NAME ASC",
    );

    if (empty($rows)) {
        return [
            'name' => '2026-05-charset-utf8mb4',
            'status' => 'skipped',
            'message' => 'Wszystkie tabele już w utf8mb4 — no-op (idempotent).',
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            'tables_converted' => 0,
            'tables' => [],
            'failed' => [],
        ];
    }

    $converted = [];
    $failed = [];

    foreach ($rows as $row) {
        $table = (string) $row->TABLE_NAME;

        // Bezpieczeństwo: tylko tabele z legalnym identyfikatorem
        // (litery/cyfry/podkreślenia). Quote przez backticki dodatkowo.
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            $failed[] = $table;
            error_log("[charset-utf8mb4] SKIP invalid table name: {$table}");
            continue;
        }

        if ($dryRun) {
            $converted[] = $table;
            continue;
        }

        // ALTER TABLE — krytyczna mutacja. Osobny query per table żeby
        // raportować per-table i nie tracić wszystkiego przy failure.
        $sql = "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci";
        $result = $wpdb->query($sql);

        if ($result === false) {
            $failed[] = $table;
            error_log("[charset-utf8mb4] FAILED: {$table} — " . $wpdb->last_error);
            continue;
        }

        $converted[] = $table;
    }

    $status = $dryRun ? 'dry-run' : 'applied';
    if (! $dryRun && count($failed) > 0 && count($converted) === 0) {
        $status = 'error';
    }

    return [
        'name' => '2026-05-charset-utf8mb4',
        'status' => $status,
        'message' => sprintf(
            '%s: %d tables → utf8mb4_unicode_520_ci (failed: %d). Tables: %s',
            $dryRun ? 'Would convert' : 'Converted',
            count($converted),
            count($failed),
            json_encode($converted, JSON_UNESCAPED_SLASHES),
        ),
        'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        'tables_converted' => count($converted),
        'tables' => $converted,
        'failed' => $failed,
    ];
}
