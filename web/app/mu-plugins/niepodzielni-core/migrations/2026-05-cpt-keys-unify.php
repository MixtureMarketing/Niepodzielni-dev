<?php

declare(strict_types=1);

/**
 * Migration: Unify Carbon Fields meta keys for 3 CPT events.
 * Date: 2026-05-07
 *
 * Run via: wp np migrate run [--dry-run]
 *
 * Why: 3 CPT eventów (wydarzenia, warsztaty, grupy-wsparcia) używały rozjeżdżonych
 * kluczy postmeta dla tych samych semantycznie pól. Powodowało to duplikację
 * logiki (`if post_type === 'wydarzenia' ... else ...`) w listing services,
 * blade views, ICS generatorze i cronie przypomnień.
 *
 * Po Etap 3 refactoru:
 *   - `data`                — bez zmian (już ujednolicone)
 *   - `godzina_rozpoczecia` — kanon (warsztaty/grupy: kopiujemy z `godzina`)
 *   - `cena`                — kanon (wydarzenia: kopiujemy z `koszt`)
 *   - `lokalizacja`         — bez zmian (`miasto` w wydarzeniach pozostaje jako
 *                              osobne, suplementarne pole — różna semantyka)
 *
 * Idempotencja: kopiujemy stary→nowy TYLKO jeśli nowy klucz nie istnieje
 * w DB (lub jest pustym stringiem). Ponowne uruchomienie = no-op.
 *
 * Stare klucze NIE są usuwane — konsumenci mają fallback `new ?: old`,
 * więc ewentualny rollback Carbon Fields nie zerwie UI. Cleanup w osobnej
 * migracji po >=1 cyklu produkcyjnym (np. 2026-08-cpt-keys-cleanup).
 */

namespace Niepodzielni\Core\Migrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Mapa: post_type => [stary_klucz => nowy_klucz, ...]
 *
 * @return array<string, array<string, string>>
 */
function np_migration_cpt_keys_unify_map(): array
{
    return [
        'warsztaty' => [
            'godzina' => 'godzina_rozpoczecia',
        ],
        'grupy-wsparcia' => [
            'godzina' => 'godzina_rozpoczecia',
        ],
        'wydarzenia' => [
            'koszt' => 'cena',
        ],
    ];
}

/**
 * @param  array{dry_run?: bool}  $options
 * @return array{name: string, status: string, message: string, duration_ms: float, rows_before: int, rows_after: int, size_before_mb: float, size_after_mb: float}
 */
function np_migration_2026_05_cpt_keys_unify(array $options = []): array
{
    global $wpdb;

    $dryRun = (bool) ($options['dry_run'] ?? false);
    $start = microtime(true);

    $stats = np_migration_postmeta_stats($wpdb, $wpdb->postmeta);

    $map = np_migration_cpt_keys_unify_map();

    $totalCopied = 0;
    $totalSkipped = 0;
    $perTypeReport = [];

    foreach ($map as $postType => $keyMap) {
        // Pobierz wszystkie posty publish + draft dla danego CPT.
        $postIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = %s
                 AND post_status NOT IN ('trash','auto-draft')",
                $postType,
            ),
        );

        $postIds = array_map('intval', (array) $postIds);

        foreach ($keyMap as $oldKey => $newKey) {
            $copied = 0;
            $skipped = 0;

            foreach ($postIds as $pid) {
                $oldVal = get_post_meta($pid, $oldKey, true);
                $newVal = get_post_meta($pid, $newKey, true);

                $oldHas = ! ($oldVal === '' || $oldVal === null || $oldVal === false);
                $newHas = ! ($newVal === '' || $newVal === null || $newVal === false);

                // Idempotency: kopiujemy tylko gdy stary ma wartość, a nowy jest pusty.
                if (! $oldHas) {
                    continue;
                }
                if ($newHas) {
                    $skipped++;
                    continue;
                }

                if (! $dryRun) {
                    update_post_meta($pid, $newKey, $oldVal);
                }
                $copied++;
            }

            $perTypeReport[] = sprintf(
                '%s: %s→%s copied=%d, skipped(already has)=%d, scanned=%d',
                $postType,
                $oldKey,
                $newKey,
                $copied,
                $skipped,
                count($postIds),
            );
            $totalCopied += $copied;
            $totalSkipped += $skipped;
        }
    }

    $statsAfter = np_migration_postmeta_stats($wpdb, $wpdb->postmeta);

    $message = ($dryRun ? '[DRY-RUN] ' : '')
        . sprintf('Unify keys — copied %d meta row(s), skipped %d (target already populated). ', $totalCopied, $totalSkipped)
        . implode(' | ', $perTypeReport);

    $status = $dryRun
        ? 'dry-run'
        : ($totalCopied === 0 ? 'skipped' : 'applied');

    return [
        'name' => '2026-05-cpt-keys-unify',
        'status' => $status,
        'message' => $message,
        'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        'rows_before' => $stats['rows'],
        'rows_after' => $statsAfter['rows'],
        'size_before_mb' => $stats['size_mb'],
        'size_after_mb' => $statsAfter['size_mb'],
    ];
}
