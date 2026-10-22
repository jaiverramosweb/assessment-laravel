<?php

namespace App\Services\Exports;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;

/**
 * Server-side PDF with Dompdf from the Blade views in resources/views/pdf (the original used
 * pdfmake). Only the bundled DejaVu fonts and data: URLs are used: remote URLs and PHP are
 * disabled, so text written by a user cannot make the server read files or download anything.
 */
final class Pdf
{
    // SISTEL palette (checked for contrast): accent for what stands out, grey for the rest.
    public const INK = '#1a1c23';

    public const MUTED = '#5b6474';

    public const ACCENT = '#374ef7';

    public const ACCENT_SOFT = '#eef1fe';

    public const BAR = '#94a3b8';

    public const RULE = '#e2e8f0';

    public const BAR_W = 190;

    public const BAR_H = 9;

    private const MARGIN_X = 48;

    /** Colors of the document: the client's when it defines them, the platform's otherwise. */
    public static function colors(array $meta): array
    {
        return [
            'accent' => ($meta['themeAccent'] ?? null) ?: self::ACCENT,
            'heading' => ($meta['themePrimary'] ?? null) ?: self::INK,
        ];
    }

    /** Horizontal bar proportional to `rate` (0–1), as HTML for the views. */
    public static function bar(int|float|null $rate, bool $highlight, string $accent = self::ACCENT, int $width = self::BAR_W): string
    {
        $rate = max(0, min(1, (float) $rate));
        $w = $rate > 0 ? max($rate * $width, 1.5) : 0;
        $inner = $w
            ? sprintf('<div style="width:%.2fpt;height:%dpt;background-color:%s;border-radius:2pt"></div>', $w, self::BAR_H, $highlight ? e($accent) : self::BAR)
            : sprintf('<div style="width:0.5pt;height:%dpt;background-color:%s"></div>', self::BAR_H, self::RULE);

        return sprintf('<div style="width:%dpt;padding-top:1pt">%s</div>', $width, $inner);
    }

    /** HTML of a view (used by the tests to check the content, like the original pdfmake definitions). */
    public static function html(string $view, array $data): string
    {
        return view($view, $data)->render();
    }

    /**
     * Renders the view as an A4 PDF. From page 2 on, `running` goes at the top and
     * "Página N de M" at the bottom (like the pdfmake header/footer).
     */
    public static function render(string $view, array $data, string $title, string $running): string
    {
        $options = new Options([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isFontSubsettingEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
            'defaultPaperSize' => 'a4',
            'dpi' => 72,
        ]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(self::html($view, $data), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $dompdf->addInfo('Title', $title);
        $dompdf->addInfo('Creator', Sheets::CREATOR);

        $muted = self::rgb(self::MUTED);
        $dompdf->getCanvas()->page_script(function (int $page, int $pages, Canvas $canvas, FontMetrics $fonts) use ($running, $muted) {
            if ($page === 1) {
                return;
            }
            $font = $fonts->getFont('DejaVu Sans');
            $size = 8;
            $canvas->text(self::MARGIN_X, 24, $running, $font, $size, $muted);
            $footer = "Página {$page} de {$pages}";
            $x = $canvas->get_width() - self::MARGIN_X - $fonts->getTextWidth($footer, $font, $size);
            $canvas->text($x, $canvas->get_height() - 40, $footer, $font, $size, $muted);
        });

        return (string) $dompdf->output();
    }

    /** "#rrggbb" → [r, g, b] in 0–1 (Dompdf canvas colors). */
    private static function rgb(string $hex): array
    {
        $n = hexdec(substr($hex, 1));

        return [(($n >> 16) & 255) / 255, (($n >> 8) & 255) / 255, ($n & 255) / 255];
    }
}
