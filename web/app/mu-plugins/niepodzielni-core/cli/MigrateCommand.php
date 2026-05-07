<?php

declare(strict_types=1);

namespace Niepodzielni\Core\CLI;

/**
 * WP-CLI: wp np migrate run [--dry-run]
 *
 * Idempotentny runner migracji DB. Każdy plik w `../migrations/` powinien:
 *  - definiować funkcję `np_migration_<slug>(array $options): array`,
 *    gdzie `<slug>` to nazwa pliku bez rozszerzenia, kropki i myślniki → `_`,
 *  - zwracać tablicę z kluczami: name, status (applied|skipped|dry-run|error),
 *    message, duration_ms, rows_before, rows_after, size_before_mb, size_after_mb,
 *  - być idempotentny (sprawdzać stan przed mutacją).
 *
 * Migracje uruchamiamy ręcznie w oknie serwisowym — NIE odpalają się
 * automatycznie (brak hooka `init`/`admin_init`).
 */
class MigrateCommand
{
    private string $migrationsDir;

    public function __construct(?string $migrationsDir = null)
    {
        $this->migrationsDir = $migrationsDir
            ?? dirname(__DIR__) . '/migrations';
    }

    /**
     * Uruchamia wszystkie migracje (sortowane alfabetycznie po nazwie pliku).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Pokaż co byłoby wykonane, nie modyfikuj DB.
     *
     * ## EXAMPLES
     *
     *     wp np migrate run
     *     wp np migrate run --dry-run
     *
     * @param  array<int, string>     $args
     * @param  array<string, mixed>   $assocArgs
     */
    public function run(array $args, array $assocArgs): void
    {
        $dryRun = (bool) (\WP_CLI\Utils\get_flag_value($assocArgs, 'dry-run', false));

        if (! is_dir($this->migrationsDir)) {
            \WP_CLI::error("Katalog migracji nie istnieje: {$this->migrationsDir}");
        }

        $files = glob($this->migrationsDir . '/*.php');
        if ($files === false || count($files) === 0) {
            \WP_CLI::warning('Brak plików migracji.');
            return;
        }

        sort($files);

        if ($dryRun) {
            \WP_CLI::warning('Tryb --dry-run: żadne zmiany nie zostaną zapisane.');
        }

        \WP_CLI::line(sprintf('Znaleziono %d migracji.', count($files)));

        $applied = 0;
        $skipped = 0;
        $dryRunCount = 0;
        $errors = 0;

        foreach ($files as $file) {
            $slug = $this->fileToSlug($file);
            $fn = '\\Niepodzielni\\Core\\Migrations\\np_migration_' . $slug;

            require_once $file;

            if (! function_exists($fn)) {
                \WP_CLI::warning("Plik {$file} nie definiuje funkcji {$fn} — pomijam.");
                $errors++;
                continue;
            }

            \WP_CLI::line('');
            \WP_CLI::line("→ {$slug}");

            /** @var callable(array{dry_run?: bool}): array{name: string, status: string, message: string, duration_ms: float, rows_before: int, rows_after: int, size_before_mb: float, size_after_mb: float} $fn */
            $result = $fn(['dry_run' => $dryRun]);

            $status = (string) ($result['status'] ?? 'unknown');
            $message = (string) ($result['message'] ?? '');
            $duration = (float) ($result['duration_ms'] ?? 0);
            $rowsBefore = (int) ($result['rows_before'] ?? 0);
            $rowsAfter = (int) ($result['rows_after'] ?? 0);
            $sizeBefore = (float) ($result['size_before_mb'] ?? 0);
            $sizeAfter = (float) ($result['size_after_mb'] ?? 0);

            \WP_CLI::line(sprintf(
                '  status:   %s',
                $status,
            ));
            \WP_CLI::line('  message:  ' . $message);
            \WP_CLI::line(sprintf('  duration: %.2f ms', $duration));
            \WP_CLI::line(sprintf(
                '  rows:     %d → %d (size: %.2f MB → %.2f MB)',
                $rowsBefore,
                $rowsAfter,
                $sizeBefore,
                $sizeAfter,
            ));

            switch ($status) {
                case 'applied':
                    $applied++;
                    break;
                case 'skipped':
                    $skipped++;
                    break;
                case 'dry-run':
                    $dryRunCount++;
                    break;
                case 'error':
                    $errors++;
                    \WP_CLI::warning("Migracja {$slug} zwróciła błąd.");
                    break;
            }
        }

        \WP_CLI::line('');
        \WP_CLI::success(sprintf(
            'Gotowe. Applied: %d | Skipped: %d | Dry-run: %d | Errors: %d',
            $applied,
            $skipped,
            $dryRunCount,
            $errors,
        ));

        if ($errors > 0) {
            \WP_CLI::halt(1);
        }
    }

    /**
     * Konwertuje nazwę pliku na slug funkcji.
     * `2026-05-add-postmeta-index.php` → `2026_05_add_postmeta_index`
     */
    private function fileToSlug(string $file): string
    {
        $base = basename($file, '.php');
        return str_replace(['-', '.'], '_', $base);
    }
}
