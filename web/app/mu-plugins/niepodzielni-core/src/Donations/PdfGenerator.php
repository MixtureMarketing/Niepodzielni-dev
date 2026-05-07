<?php

declare(strict_types=1);

namespace Niepodzielni\Donations;

/**
 * Generator PDF dla instrukcji 1.5% PIT.
 *
 * Cienki wrapper na dompdf — escapuje user input, ustawia DejaVu Sans
 * (jedyny domyślny font dompdf z polskimi znakami), zwraca binary PDF.
 *
 * UWAGA na produkcji lh.pl: open_basedir blokuje /tmp. Konstruktor
 * przyjmuje opcjonalny tempDir (domyślnie wp-content/cache/donations-pdf).
 */
class PdfGenerator
{
    private string $tempDir;

    /**
     * @param string|null $tempDir Ścieżka do katalogu tymczasowego dompdf.
     *                              Default: WP_CONTENT_DIR/cache/donations-pdf
     */
    public function __construct(?string $tempDir = null)
    {
        $this->tempDir = $tempDir ?? self::defaultTempDir();
    }

    /**
     * Generuje PDF z instrukcją 1.5% PIT.
     *
     * @param array{
     *   krs: string,
     *   foundation_name: string,
     *   donor_name?: string,
     *   amount?: int|null,
     * } $data
     * @return string Binary PDF
     * @throws DonationsApiException
     */
    public function renderPitInstruction(array $data): string
    {
        $this->ensureSdk();
        $this->ensureTempDir();

        $html = $this->buildPitHtml($data);

        try {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('tempDir', $this->tempDir);
            $options->set('chroot', $this->tempDir);

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
        } catch (\Throwable $e) {
            throw new DonationsApiException(
                'PDF generation failed: ' . $e->getMessage(),
                'pdf_render',
                $e,
            );
        }

        return (string) $dompdf->output();
    }

    /**
     * @param array{
     *   krs: string,
     *   foundation_name: string,
     *   donor_name?: string,
     *   amount?: int|null,
     * } $data
     */
    private function buildPitHtml(array $data): string
    {
        $krs            = $this->esc((string) $data['krs']);
        $foundationName = $this->esc((string) $data['foundation_name']);
        $donorName      = $this->esc((string) ($data['donor_name'] ?? ''));
        $today          = date('d.m.Y');

        $donorBlock = $donorName !== ''
            ? '<p><strong>Imię i nazwisko podatnika:</strong> ' . $donorName . '</p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Instrukcja 1,5% PIT — {$foundationName}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; line-height: 1.5; }
        h1 { color: #c0392b; font-size: 22pt; margin: 0 0 6pt; }
        h2 { color: #2c3e50; font-size: 14pt; margin: 18pt 0 6pt; }
        .krs-box { border: 2px solid #c0392b; padding: 12pt; margin: 12pt 0; background: #fef2f0; }
        .krs-value { font-size: 18pt; font-weight: bold; letter-spacing: 0.05em; }
        ul { padding-left: 18pt; }
        li { margin-bottom: 6pt; }
        .footer { margin-top: 24pt; font-size: 9pt; color: #666; }
        .highlight { background: #f9d71c; padding: 0 3pt; }
    </style>
</head>
<body>
    <h1>1,5% Twojego PIT na {$foundationName}</h1>
    <p>Dokument wygenerowany {$today}.</p>
    {$donorBlock}

    <div class="krs-box">
        <p style="margin:0;">Wpisz w odpowiednim polu zeznania PIT numer KRS naszej fundacji:</p>
        <p class="krs-value">KRS: {$krs}</p>
    </div>

    <h2>Jak przekazać 1,5% PIT — krok po kroku</h2>

    <h2>PIT-37 (umowa o pracę, zlecenie)</h2>
    <ul>
        <li>Sekcja <strong>„Wniosek o przekazanie 1,5% podatku należnego na rzecz organizacji pożytku publicznego (OPP)"</strong>.</li>
        <li>W polu <strong>„Numer KRS"</strong> wpisz: <span class="highlight">{$krs}</span></li>
        <li>W polu <strong>„Wnioskowana kwota"</strong> wpisz kwotę odpowiadającą 1,5% Twojego podatku.</li>
        <li>(opcjonalnie) W polu <strong>„Cel szczegółowy"</strong> wpisz cel — np. wsparcie konkretnej osoby.</li>
    </ul>

    <h2>PIT-36 (działalność gospodarcza)</h2>
    <ul>
        <li>Sekcja <strong>„Wniosek o przekazanie 1,5% podatku należnego na rzecz OPP"</strong>.</li>
        <li>Numer KRS: <span class="highlight">{$krs}</span></li>
    </ul>

    <h2>PIT-28 (ryczałt)</h2>
    <ul>
        <li>Sekcja <strong>„Wniosek o przekazanie 1,5% podatku"</strong>.</li>
        <li>Numer KRS: <span class="highlight">{$krs}</span></li>
    </ul>

    <h2>Online — najszybsze sposoby</h2>
    <ul>
        <li><strong>e-PIT na podatki.gov.pl</strong> — wybierz „Pożytek publiczny" → wpisz numer KRS {$krs} → zatwierdź.</li>
        <li><strong>Twój e-PIT</strong> ma już automatycznie wpisaną organizację z poprzedniego roku — możesz ją zmienić.</li>
    </ul>

    <p class="footer">
        Dziękujemy za wsparcie. Każde 1,5% PIT to konkretne sesje, warsztaty i grupy wsparcia
        dla osób w kryzysie. Zachowaj ten plik na pulpicie i miej pod ręką podczas składania zeznania.
    </p>
</body>
</html>
HTML;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function ensureSdk(): void
    {
        if (! class_exists(\Dompdf\Dompdf::class)) {
            throw new DonationsApiException(
                'dompdf nie jest zainstalowane. Uruchom `composer install` w katalogu projektu.',
                'sdk_missing',
            );
        }
    }

    private function ensureTempDir(): void
    {
        if (is_dir($this->tempDir) && is_writable($this->tempDir)) {
            return;
        }

        if (! @mkdir($this->tempDir, 0755, true) && ! is_dir($this->tempDir)) {
            throw new DonationsApiException(
                "Nie udało się utworzyć katalogu tymczasowego dompdf: {$this->tempDir}",
                'tempdir',
            );
        }
    }

    private static function defaultTempDir(): string
    {
        if (defined('WP_CONTENT_DIR')) {
            return WP_CONTENT_DIR . '/cache/donations-pdf';
        }
        return sys_get_temp_dir() . '/np-donations-pdf';
    }
}
