<?php

/**
 * Template emaila przypomnienia (T-24h).
 *
 * Zmienne dostępne (extract z EventReminderService::renderTemplate):
 *   - $title    string  Tytuł wydarzenia
 *   - $date     string  Sformatowana data po PL (np. "15 maja 2026")
 *   - $time     string  Godzina HH:MM (lub '')
 *   - $location string  Lokalizacja (lub '')
 *   - $url      string  Permalink wydarzenia
 *   - $unsubscribeUrl string
 *   - $siteName string
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html($title); ?></title>
</head>
<body style="margin:0;padding:0;background:#f7f7f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f7f7f7;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                    <tr>
                        <td style="padding:32px 32px 16px;">
                            <p style="margin:0 0 8px;font-size:14px;color:#777;letter-spacing:0.04em;text-transform:uppercase;">Przypomnienie</p>
                            <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;line-height:1.3;color:#1a1a1a;">
                                Jutro: <?php echo esc_html($title); ?>
                            </h1>
                            <p style="margin:0 0 24px;font-size:16px;line-height:1.5;color:#333;">
                                Cześć! Dzień przed wydarzeniem zostawiamy przypomnienie. Poniżej szczegóły:
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#fafafa;border-radius:8px;padding:16px;margin-bottom:24px;">
                                <tr>
                                    <td style="padding:8px 16px;">
                                        <p style="margin:0;font-size:14px;color:#777;">Data</p>
                                        <p style="margin:0;font-size:16px;font-weight:600;"><?php echo esc_html($date); ?><?php if ($time !== ''): ?>, godz. <?php echo esc_html($time); ?><?php endif; ?></p>
                                    </td>
                                </tr>
                                <?php if ($location !== ''): ?>
                                <tr>
                                    <td style="padding:8px 16px;border-top:1px solid #eee;">
                                        <p style="margin:0;font-size:14px;color:#777;">Lokalizacja</p>
                                        <p style="margin:0;font-size:16px;font-weight:600;"><?php echo esc_html($location); ?></p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>

                            <p style="margin:0 0 24px;text-align:center;">
                                <a href="<?php echo esc_url($url); ?>" style="display:inline-block;padding:12px 28px;background:#2c3e50;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">
                                    Zobacz szczegóły
                                </a>
                            </p>

                            <p style="margin:0 0 8px;font-size:13px;color:#777;line-height:1.5;">
                                Do zobaczenia!<br>
                                Zespół <?php echo esc_html($siteName); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px;background:#fafafa;border-top:1px solid #eee;">
                            <p style="margin:0;font-size:12px;color:#999;line-height:1.5;">
                                Otrzymujesz tę wiadomość, ponieważ zapisałeś/aś się na przypomnienie o tym wydarzeniu.
                                <a href="<?php echo esc_url($unsubscribeUrl); ?>" style="color:#666;">Wypisz się</a>.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
