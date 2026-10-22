{{-- General report of a client (port of buildClientPdfDefinition in src/lib/server/export/client-export.ts). --}}
@extends('pdf.layout')
@php($F = \App\Services\Reports\Format::class)
@php($P = \App\Services\Exports\Pdf::class)
@php($E = \App\Services\Exports\ClientExport::class)
@php($STATUS = \App\Services\Exports\ExportMeta::ASSESSMENT_STATUS_TEXT)
@php($s = $report['summary'])

@section('content')
    @include('pdf.partials.cover', [
        'kicker' => 'Informe general',
        'title' => $meta['name'],
        'text' => $s['assessments'].' '.($s['assessments'] === 1 ? 'assessment' : 'assessments').' · generado el '.$meta['generatedAt'],
        'rows' => [],
    ])

    <h1>Resumen</h1>
    @include('pdf.partials.stats', ['items' => [
        ['Acierto promedio', $F::percent($s['averageScore'])],
        ['Alineación promedio', $F::percent($s['averageAlignment'])],
        ['Entregas', $s['submitted'].' de '.$s['participants']],
        ['Personas distintas', (string) $s['people']],
    ]])
    <p class="note" style="margin-bottom: 14pt">Acierto: porcentaje de preguntas de selección que cada persona respondió con la opción esperada, promediado entre quienes entregaron. Alineación: porcentaje de quienes respondieron cada pregunta que eligió la opción esperada. Solo cuentan entregas finales.</p>

    <h2>Acierto promedio por assessment</h2>
    <table class="bars" style="margin-bottom: 14pt">
        @foreach ($report['assessments'] as $a)
            <tr>
                <td style="width: 150pt">{{ $a['title'] }}</td>
                <td class="muted" style="width: 70pt; font-size: 8pt">{{ $E::period($a) }}</td>
                <td style="width: 196pt">{!! $P::bar($a['averageScore'] ?? 0, true, $accent) !!}</td>
                <td class="right bold" style="width: 44pt">{{ $F::percent($a['averageScore']) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Detalle por assessment</h2>
    <table class="light" style="margin-bottom: 14pt">
        <tr>
            <th>Assessment</th>
            <th class="right" style="width: 56pt">Estado</th>
            <th class="right" style="width: 54pt">Entregas</th>
            <th class="right" style="width: 70pt">Participación</th>
            <th class="right" style="width: 60pt">Alineación</th>
            <th class="right" style="width: 48pt">Acierto</th>
        </tr>
        @foreach ($report['assessments'] as $a)
            <tr>
                <td>{{ $a['title'] }}</td>
                <td class="right">{{ $STATUS[$a['status']] ?? $a['status'] }}</td>
                <td class="right">{{ $a['submitted'] }} de {{ $a['participants'] }}</td>
                <td class="right">{{ $F::percent($a['participationRate']) }}</td>
                <td class="right">{{ $F::percent($a['averageAlignment']) }}</td>
                <td class="right bold">{{ $F::percent($a['averageScore']) }}</td>
            </tr>
        @endforeach
    </table>

    @if (count($report['byArea']))
        <h2>Por área</h2>
        @include('pdf.partials.group-bars', ['groups' => $report['byArea'], 'label' => 'Área'])
    @endif
    @if (count($report['byCargo']))
        <h2>Por cargo</h2>
        @include('pdf.partials.group-bars', ['groups' => $report['byCargo'], 'label' => 'Cargo'])
    @endif
    <p class="note">El detalle de cada área en cada assessment está en el archivo Excel.</p>
@endsection
