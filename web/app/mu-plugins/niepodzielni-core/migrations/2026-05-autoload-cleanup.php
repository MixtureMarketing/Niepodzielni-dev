<?php

declare(strict_types=1);

/**
 * Migration: Reduce autoloaded options size in wp_options.
 * Date: 2026-05-07
 *
 * Run via:
 *   wp np migrate run --dry-run            (within batch runner — domyślnie tylko dry-run, NIE mutuje)
 *   wp np migrate autoload-cleanup --dry-run
 *   wp np migrate autoload-cleanup --yes   (operator / CI)
 *
 * Why: każdy request WordPressa wykonuje zapytanie:
 *   SELECT option_name, option_value FROM wp_options WHERE autoload='yes'
 * Wynik trafia do pamięci PHP (wp_load_alloptions) i jest serializowany.
 * Wtyczki czasem zapisują transients/cache ze złym flagiem autoload, lub
 * trzymają wielomegabajtowe ustawienia jako autoload=yes — jeden request to
 * dziesiątki MB pamięci i dodatkowe milisekundy parsowania serializacji.
 *
 * Strategia: wyłącz autoload (autoload='no') dla opcji spełniających heurystykę:
 *   - rozmiar option_value > 100 KB (102_400 bajtów),
 *   - LUB nazwa pasuje do pluginowych transientów (`_transient_*`, `_site_transient_*`),
 *   - I jednocześnie NIE jest na whitelist krytycznych opcji rdzenia/runtime.
 *
 * Idempotencja: kandydat jest aktualizowany TYLKO jeśli ma autoload='yes'.
 * Ponowne uruchomienie = no-op (nic nie spełnia warunku).
 *
 * Bezpieczeństwo: w trybie batchowym (`wp np migrate run`) ZAWSZE wykonujemy
 * dry-run, niezależnie od flagi `--dry-run` runnera. Realna mutacja wymaga
 * dedykowanego subcommandu `wp np migrate autoload-cleanup --yes`, żeby
 * uniknąć przypadkowej zmiany na produkcji bez przeglądu listy.
 *
 * Rollback: `wp option set <name> "$(wp option get <name>)" --autoload=yes` lub
 * przywrócenie z backupu wp_options sprzed migracji.
 */

namespace Niepodzielni\Core\Migrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Próg w bajtach — powyżej tego rozmiaru opcja nie powinna być autoloaded.
 */
const NP_AUTOLOAD_SIZE_THRESHOLD = 102_400; // 100 KB

/**
 * Whitelist — NIGDY nie zmieniamy autoload dla tych opcji rdzenia / runtime.
 *
 * @return array<int, string>
 */
function np_migration_autoload_whitelist(): array
{
    return [
        // Core identity / routing — używane w każdym requeście.
        'siteurl',
        'home',
        'blogname',
        'blogdescription',
        'admin_email',
        'template',
        'stylesheet',
        'WPLANG',
        'blog_charset',
        'date_format',
        'time_format',
        'gmt_offset',
        'timezone_string',
        'start_of_week',
        'rewrite_rules',
        // Plugin/theme bootstrap — bez tego strona się nie załaduje normalnie.
        'active_plugins',
        'cron',
        // Bezpieczeństwo / sesja.
        'auth_key',
        'auth_salt',
        'logged_in_key',
        'logged_in_salt',
        'nonce_key',
        'nonce_salt',
        'secure_auth_key',
        'secure_auth_salt',
    ];
}

/**
 * Zwraca top N opcji autoload=yes posortowanych po rozmiarze malejąco.
 *
 * @return array<int, array{option_name: string, size: int}>
 */
function np_migration_autoload_top(\wpdb $wpdb, int $limit = 20): array
{
    /** @var array<int, array<string, mixed>>|null $rows */
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, LENGTH(option_value) AS size
             FROM {$wpdb->options}
             WHERE autoload = 'yes'
             ORDER BY size DESC
             LIMIT %d",
            $limit,
        ),
        ARRAY_A,
    );

    if (! is_array($rows)) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'option_name' => (string) ($row['option_name'] ?? ''),
            'size' => (int) ($row['size'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Łączny rozmiar autoload=yes payloadu.
 */
function np_migration_autoload_total_bytes(\wpdb $wpdb): int
{
    $val = $wpdb->get_var(
        "SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE autoload = 'yes'",
    );

    return (int) ($val ?? 0);
}

/**
 * Czy nazwa opcji wygląda jak transient / site_transient.
 */
function np_migration_is_transient_name(string $name): bool
{
    if (str_starts_with($name, '_transient_')) {
        return true;
    }
    if (str_starts_with($name, '_site_transient_')) {
        return true;
    }

    return false;
}

/**
 * Wyznacza listę kandydatów do wyłączenia autoload.
 * Heurystyka: (size > threshold) LUB (nazwa to transient), AND nie na whitelist.
 *
 * @return array<int, array{option_name: string, size: int, reason: string}>
 */
function np_migration_autoload_candidates(\wpdb $wpdb): array
{
    $whitelist = array_flip(np_migration_autoload_whitelist());

    /** @var array<int, array<string, mixed>>|null $rows */
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, LENGTH(option_value) AS size
             FROM {$wpdb->options}
             WHERE autoload = 'yes'
             AND (
                 LENGTH(option_value) > %d
                 OR option_name LIKE %s
                 OR option_name LIKE %s
             )
             ORDER BY size DESC",
            NP_AUTOLOAD_SIZE_THRESHOLD,
            '\_transient\_%',
            '\_site\_transient\_%',
        ),
        ARRAY_A,
    );

    if (! is_array($rows)) {
        return [];
    }

    $candidates = [];
    foreach ($rows as $row) {
        $name = (string) ($row['option_name'] ?? '');
        $size = (int) ($row['size'] ?? 0);

        if ($name === '') {
            continue;
        }
        if (isset($whitelist[$name])) {
            continue;
        }

        $reasons = [];
        if ($size > NP_AUTOLOAD_SIZE_THRESHOLD) {
            $reasons[] = sprintf('size=%dKB', (int) round($size / 1024));
        }
        if (np_migration_is_transient_name($name)) {
            $reasons[] = 'transient';
        }

        $candidates[] = [
            'option_name' => $name,
            'size' => $size,
            'reason' => implode(',', $reasons),
        ];
    }

    return $candidates;
}

/**
 * @param  array{dry_run?: bool, force?: bool}  $options
 * @return array{name: string, status: string, message: string, duration_ms: float, rows_before: int, rows_after: int, size_before_mb: float, size_after_mb: float}
 */
function np_migration_2026_05_autoload_cleanup(array $options = []): array
{
    global $wpdb;

    // SAFETY: w trybie batchowego runnera (`wp np migrate run`) NIE mutujemy DB
    // bez jawnej zgody operatora. Domyślnie zachowujemy się jak dry-run.
    // Realne `autoload='no'` wymaga `force=true` (subcommand `autoload-cleanup --yes`).
    $force = (bool) ($options['force'] ?? false);
    $dryRun = (bool) ($options['dry_run'] ?? false);
    if (! $force) {
        $dryRun = true;
    }

    $start = microtime(true);

    $totalBefore = np_migration_autoload_total_bytes($wpdb);
    $top = np_migration_autoload_top($wpdb, 20);
    $candidates = np_migration_autoload_candidates($wpdb);

    $top20Lines = [];
    foreach ($top as $i => $row) {
        $top20Lines[] = sprintf(
            '#%d %s (%d KB)',
            $i + 1,
            $row['option_name'],
            (int) round($row['size'] / 1024),
        );
    }

    $changed = 0;
    $skipped = 0;
    $perCandidate = [];

    foreach ($candidates as $cand) {
        $name = $cand['option_name'];

        // Idempotencja: aktualizujemy tylko jeśli autoload='yes'.
        $current = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $name,
            ),
        );

        if ($current !== 'yes') {
            $skipped++;
            continue;
        }

        if (! $dryRun) {
            $updated = $wpdb->update(
                $wpdb->options,
                ['autoload' => 'no'],
                ['option_name' => $name],
                ['%s'],
                ['%s'],
            );

            if ($updated === false) {
                $perCandidate[] = sprintf('ERROR %s: %s', $name, $wpdb->last_error !== '' ? $wpdb->last_error : 'unknown');
                continue;
            }
        }

        $changed++;
        $perCandidate[] = sprintf('%s (%s)', $name, $cand['reason']);
    }

    $totalAfter = np_migration_autoload_total_bytes($wpdb);

    $message = ($dryRun ? '[DRY-RUN] ' : '')
        . sprintf(
            'autoload=yes total: %.2f MB → %.2f MB | candidates=%d, would-change/changed=%d, skipped(not-yes)=%d. ',
            $totalBefore / 1024 / 1024,
            $totalAfter / 1024 / 1024,
            count($candidates),
            $changed,
            $skipped,
        )
        . 'TOP20: ' . implode(' | ', $top20Lines);

    if (count($perCandidate) > 0) {
        $message .= ' || TARGETS: ' . implode(' ; ', $perCandidate);
    }

    $status = $dryRun
        ? 'dry-run'
        : ($changed === 0 ? 'skipped' : 'applied');

    return [
        'name' => '2026-05-autoload-cleanup',
        'status' => $status,
        'message' => $message,
        'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        'rows_before' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = 'yes'"),
        'rows_after' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = 'yes'"),
        'size_before_mb' => round($totalBefore / 1024 / 1024, 2),
        'size_after_mb' => round($totalAfter / 1024 / 1024, 2),
    ];
}
