<?php

declare(strict_types=1);

/**
 * Migration: Add composite index (post_id, meta_key) to wp_postmeta
 * Date: 2026-05-07
 *
 * Run via: wp np migrate run [--dry-run]
 *
 * Why: meta_query w listingu psychologów (specjalizacje, nurty, języki),
 * Carbon Fields (klucze z prefiksem `_`) oraz lookupy Bookero ID na CPT
 * wymuszają full scan postmeta — istniejące pojedyncze indeksy
 * (`post_id`, `meta_key(191)`) nie pokrywają złożonego predykatu
 * `WHERE post_id = X AND meta_key = 'Y'`. Composite index pozwala
 * MariaDB użyć ref-lookup zamiast full scan: szacunkowo -500..-2000 ms
 * na listingach z meta_query.
 *
 * Idempotencja: indeks tworzymy tylko jeśli nie istnieje. Operacja
 * `ALTER TABLE ... ADD KEY` na InnoDB jest online (DDL), ale i tak
 * uruchamiamy ją w oknie serwisowym po backupie.
 */

namespace Niepodzielni\Core\Migrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @param  array{dry_run?: bool}  $options
 * @return array{name: string, status: string, message: string, duration_ms: float, rows_before: int, rows_after: int, size_before_mb: float, size_after_mb: float}
 */
function np_migration_2026_05_add_postmeta_index(array $options = []): array
{
    global $wpdb;

    $dryRun = (bool) ($options['dry_run'] ?? false);
    $table = $wpdb->postmeta;
    $indexName = 'idx_post_meta';

    $start = microtime(true);
    $stats = np_migration_postmeta_stats($wpdb, $table);

    // Sprawdź czy indeks już istnieje (idempotencja)
    $existing = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            $indexName,
        ),
    );

    if ($existing !== null) {
        return [
            'name' => '2026-05-add-postmeta-index',
            'status' => 'skipped',
            'message' => "Index {$indexName} already exists on {$table} — no-op.",
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            'rows_before' => $stats['rows'],
            'rows_after' => $stats['rows'],
            'size_before_mb' => $stats['size_mb'],
            'size_after_mb' => $stats['size_mb'],
        ];
    }

    if ($dryRun) {
        return [
            'name' => '2026-05-add-postmeta-index',
            'status' => 'dry-run',
            'message' => "Would execute: ALTER TABLE {$table} ADD KEY {$indexName} (post_id, meta_key(191))",
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            'rows_before' => $stats['rows'],
            'rows_after' => $stats['rows'],
            'size_before_mb' => $stats['size_mb'],
            'size_after_mb' => $stats['size_mb'],
        ];
    }

    // Wykonaj DDL — InnoDB online ALTER (nie blokuje DML)
    // ALGORITHM=INPLACE, LOCK=NONE pozwala dodać secondary index bez przerwy w zapisach
    $sql = "ALTER TABLE {$table} ADD KEY {$indexName} (post_id, meta_key(191)), ALGORITHM=INPLACE, LOCK=NONE";
    $result = $wpdb->query($sql);

    if ($result === false) {
        // Fallback bez online hints (np. starsze MariaDB lub inny silnik)
        $fallbackSql = "ALTER TABLE {$table} ADD KEY {$indexName} (post_id, meta_key(191))";
        $result = $wpdb->query($fallbackSql);
    }

    if ($result === false) {
        return [
            'name' => '2026-05-add-postmeta-index',
            'status' => 'error',
            'message' => 'ALTER TABLE failed: ' . ($wpdb->last_error !== '' ? $wpdb->last_error : 'unknown'),
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            'rows_before' => $stats['rows'],
            'rows_after' => $stats['rows'],
            'size_before_mb' => $stats['size_mb'],
            'size_after_mb' => $stats['size_mb'],
        ];
    }

    $statsAfter = np_migration_postmeta_stats($wpdb, $table);

    return [
        'name' => '2026-05-add-postmeta-index',
        'status' => 'applied',
        'message' => "Index {$indexName} created on {$table}.",
        'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        'rows_before' => $stats['rows'],
        'rows_after' => $statsAfter['rows'],
        'size_before_mb' => $stats['size_mb'],
        'size_after_mb' => $statsAfter['size_mb'],
    ];
}

/**
 * @return array{rows: int, size_mb: float}
 */
function np_migration_postmeta_stats(\wpdb $wpdb, string $table): array
{
    $row = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT TABLE_ROWS as rows_count, (DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024 as size_mb
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table,
        ),
        ARRAY_A,
    );

    if (! is_array($row)) {
        return ['rows' => 0, 'size_mb' => 0.0];
    }

    return [
        'rows' => (int) ($row['rows_count'] ?? 0),
        'size_mb' => round((float) ($row['size_mb'] ?? 0), 2),
    ];
}
