{{-- Report PDF: cover, summary, chart and table per question, and the AI analysis of the open questions
     (port of src/lib/server/export/pdf.ts). --}}
@extends('pdf.layout')
@php($F = \App\Services\Reports\Format::class)
@php($P = \App\Services\Exports\Pdf::class)
@php($s = $report['summary'])

@section('content')
    @include('pdf.partials.cover', [
        'kicker' => 'Informe de resultados',
        'title' => $meta['title'],
        'text' => $meta['description'],
        'rows' => [
            ['Periodo', $meta['period']],
            ['Zona horaria', str_replace('_', ' ', $meta['timezone'])],
            ['Participantes', $meta['filtersLabel']],
            ['Generado', $meta['generatedAt']],
        ],
    ])

    <h1>Resumen general</h1>
    @include('pdf.partials.stats', ['items' => [
        ['Alineación promedio', $F::percent($s['averageAlignment'])],
        ['Participantes', (string) $s['invited']],
        ['Entregas', (string) $s['submitted']],
        ['Tasa de participación', $F::percent($s['participationRate'])],
    ]])
    <p class="note" style="margin-bottom: 12pt">La alineación es el porcentaje de quienes respondieron una pregunta de selección que eligió la opción esperada. Solo se incluyen entregas finales ({{ $s['inProgress'] }} en progreso no se incluyen).</p>

    @if (count($s['mostAligned']))
        @php($groups = array_values(array_filter([['Mayor alineación', $s['mostAligned']], ['Menor alineación', $s['leastAligned']]], fn ($g) => count($g[1]))))
        <table style="width: 100%; margin-bottom: 16pt">
            <tr>
                @foreach ($groups as [$groupTitle, $list])
                    <td style="width: {{ 100 / count($groups) }}%; padding-right: {{ $loop->last ? 0 : 20 }}pt">
                        <h3>{{ $groupTitle }}</h3>
                        <table style="width: 100%">
                            @foreach ($list as $c)
                                <tr>
                                    <td style="font-size: 9pt; padding-bottom: 3pt">{{ $c['number'] }}. {{ $c['prompt'] }}</td>
                                    <td class="right bold" style="width: 40pt; font-size: 9pt">{{ $F::percent($c['alignmentRate']) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @if (count($report['choice']))
        <h2>Alineación por pregunta</h2>
        <table class="bars">
            @foreach ($report['choice'] as $c)
                <tr>
                    <td style="width: 210pt">{{ $c['number'] }}. {{ $c['prompt'] }}</td>
                    <td style="width: 196pt">{!! $P::bar($c['alignmentRate'] ?? 0, true, $accent) !!}</td>
                    <td class="right" style="width: 60pt">{{ $F::percent($c['alignmentRate']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (count($report['choice']))
        <h1 class="break-before">Resultados por pregunta</h1>
        @foreach ($report['choice'] as $c)
            <div class="avoid-break" style="margin-bottom: 16pt">
                <p class="question-title">{{ $c['number'] }}. {{ $c['prompt'] }}</p>
                <p class="meta">{{ $c['respondents'] }} {{ $c['respondents'] === 1 ? 'persona respondió' : 'personas respondieron' }}@if ($c['omitted']) · {{ $c['omitted'] }} la omitieron @endif · Alineación: <strong>{{ $F::percent($c['alignmentRate']) }}</strong></p>
                <table class="bars">
                    @foreach ($c['options'] as $o)
                        <tr>
                            <td style="width: 210pt">{{ $o['text'] }}@if ($o['isExpected'])<span class="expected">&nbsp;&nbsp;(esperada)</span>@endif</td>
                            <td style="width: 196pt">{!! $P::bar($o['rate'], $o['isExpected'], $accent) !!}</td>
                            <td class="right" style="width: 60pt">{{ $o['count'] }} · {{ $F::percent($c['respondents'] ? $o['rate'] : null) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach
    @endif

    @if (count($report['open']))
        <h1 class="break-before">Preguntas abiertas: análisis con IA</h1>
        <p class="note" style="margin-bottom: 12pt">Las respuestas se enviaron a la IA sin nombres ni correos. Las respuestas completas están en el archivo Excel.</p>
        @if (isset($analyses['global']))
            <h2>Análisis global</h2>
            @include('pdf.partials.analysis', ['analysis' => $analyses['global']])
        @endif
        @foreach ($report['open'] as $q)
            <h2 style="margin: 14pt 0 4pt">{{ $q['number'] }}. {{ $q['prompt'] }}</h2>
            <p class="meta">{{ count($q['responses']) }} {{ count($q['responses']) === 1 ? 'respuesta' : 'respuestas' }}</p>
            @if (isset($analyses[$q['id']]))
                @include('pdf.partials.analysis', ['analysis' => $analyses[$q['id']]])
            @else
                <p class="note">Aún no se ha generado el análisis con IA para esta pregunta.</p>
            @endif
        @endforeach
    @endif
@endsection
