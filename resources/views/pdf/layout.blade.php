{{-- Base of every PDF export (Dompdf). Fonts: bundled DejaVu Sans (pdfmake used Roboto). --}}
@php($C = \App\Services\Exports\Pdf::class)
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $documentTitle }}</title>
<style>
    @page { margin: 56pt 48pt; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: {{ $C::INK }}; line-height: 1.3; margin: 0; }
    p { margin: 0; }
    table { border-collapse: collapse; }
    td, th { vertical-align: top; text-align: left; font-weight: normal; }
    .page-break { page-break-after: always; }
    .break-before { page-break-before: always; }
    .avoid-break { page-break-inside: avoid; }
    .kicker { font-size: 11pt; color: {{ $accent }}; font-weight: bold; margin: 0 0 8pt; }
    .cover-title { font-size: 25pt; font-weight: bold; line-height: 1.1; color: {{ $heading }}; }
    .cover-text { font-size: 11pt; color: {{ $C::MUTED }}; margin-top: 12pt; }
    .cover-logo { margin-top: 110pt; }
    .cover-logo img { max-width: 160pt; max-height: 70pt; }
    .cover-table { margin-top: 40pt; width: 100%; }
    .cover-table td { padding: 3pt 6pt 3pt 0; font-size: 10pt; }
    .cover-table td.k { width: 110pt; color: {{ $C::MUTED }}; }
    h1 { font-size: 16pt; font-weight: bold; margin: 0 0 10pt; color: {{ $heading }}; }
    h2 { font-size: 12pt; font-weight: bold; margin: 6pt 0 6pt; }
    h3 { font-size: 10pt; font-weight: bold; margin: 4pt 0 3pt; }
    .stats { width: 100%; margin-bottom: 12pt; }
    .stats td { padding-right: 12pt; }
    .stat-label { font-size: 9pt; color: {{ $C::MUTED }}; }
    .stat-value { font-size: 19pt; font-weight: bold; }
    .note { font-size: 8.5pt; color: {{ $C::MUTED }}; font-style: italic; }
    .meta { font-size: 9pt; color: {{ $C::MUTED }}; margin: 0 0 6pt; }
    .bars td { padding: 3pt 6pt 3pt 0; font-size: 9pt; }
    .light { width: 100%; }
    .light th { font-size: 9pt; font-weight: bold; color: {{ $C::MUTED }}; padding: 4pt; }
    .light td { font-size: 9pt; padding: 4pt; border-top: 0.5pt solid {{ $C::RULE }}; }
    .light tr:first-child td { border-top: 0; }
    .right { text-align: right; }
    .bold { font-weight: bold; }
    .muted { color: {{ $C::MUTED }}; }
    .italic { font-style: italic; }
    .expected { font-weight: bold; color: {{ $accent }}; }
    .question-title { font-size: 11pt; font-weight: bold; margin: 0 0 2pt; }
    .quote { font-size: 9pt; font-style: italic; color: #334155; margin: 0 0 4pt 10pt; }
    ul { margin: 0 0 6pt; padding-left: 14pt; }
    li { font-size: 10pt; }
</style>
</head>
<body>
@yield('content')
</body>
</html>
