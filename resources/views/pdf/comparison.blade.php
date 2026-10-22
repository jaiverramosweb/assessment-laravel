{{-- Comparison between measurements (port of buildComparisonPdfDefinition in src/lib/server/export/comparison-export.ts). --}}
@extends('pdf.layout')
@php($F = \App\Services\Reports\Format::class)
@php($P = \App\Services\Exports\Pdf::class)
@php($E = \App\Services\Exports\ComparisonExport::class)
@php($s = $c['summary'])
@php($withTransitions = count($c['choice']) && $c['choice'][0]['transitions'] !== null)

@section('content')
    @include('pdf.partials.cover', [
        'kicker' => 'Comparativo entre mediciones',
        'title' => $meta['title'],
        'text' => null,
        'rowsMargin' => 30,
        'rows' => [
            ...array_map(fn ($m) => ['Medición '.$m['order'], $m['title'].' · '.$F::shortDate($m['startsAt'], $m['timezone'])], $c['measurements']),
            ['Personas', $E::modeText($c)],
            ['Generado', $meta['generatedAt']],
        ],
    ])

    <h1>Resumen</h1>
    @include('pdf.partials.stats', ['valueSize' => 15, 'items' => [
        ['Acierto: antes y después', $F::percent($s['firstScore']).' a '.$F::percent($s['lastScore'])],
        ['Diferencia', $F::points($s['delta'])],
        ['Alineación: antes y después', $F::percent($s['firstAlignment']).' a '.$F::percent($s['lastAlignment'])],
        ['Respondieron todas', (string) $s['paired']],
    ]])
    <p class="note" style="margin-bottom: 14pt">Diferencias en puntos porcentuales (pp), de la primera a la última medición. Acierto: % de preguntas de selección respondidas con la opción esperada, por persona. Alineación: % de quienes respondieron cada pregunta que eligió la opción esperada.</p>

    <h2>Acierto promedio por medición</h2>
    <table class="bars" style="margin-bottom: 14pt">
        @foreach ($c['measurements'] as $m)
            <tr>
                <td style="width: 150pt">{{ $E::label($m) }}</td>
                <td style="width: 196pt">{!! $P::bar($m['averageScore'] ?? 0, true, $accent) !!}</td>
                <td class="right bold" style="width: 50pt">{{ $F::percent($m['averageScore']) }}</td>
            </tr>
        @endforeach
    </table>

    @if (count($c['choice']))
        <h2>Alineación por pregunta</h2>
        <table class="light" style="margin-bottom: 14pt">
            <tr>
                <th>Pregunta</th>
                @foreach ($c['measurements'] as $m)
                    <th class="right" style="width: 50pt">Med. {{ $m['order'] }}</th>
                @endforeach
                <th class="right" style="width: 46pt">Dif.</th>
                @if ($withTransitions)
                    <th class="right" style="width: 80pt">Mejoraron / empeoraron</th>
                @endif
            </tr>
            @foreach ($c['choice'] as $q)
                <tr>
                    <td>{{ $q['position'] }}. {{ $q['prompt'] }}</td>
                    @foreach ($q['rates'] as $r)
                        <td class="right">{{ $F::percent($r) }}</td>
                    @endforeach
                    <td class="right bold">{{ $F::points($q['delta']) }}</td>
                    @if ($q['transitions'])
                        <td class="right">+{{ $q['transitions']['improved'] }} / −{{ $q['transitions']['worsened'] }}</td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif

    @foreach ([['Por área', 'Área', $c['byArea']], ['Por cargo', 'Cargo', $c['byCargo']]] as [$groupTitle, $label, $groups])
        @if (count($groups))
            <h2>{{ $groupTitle }}</h2>
            <table class="light" style="margin-bottom: 14pt">
                <tr>
                    <th>{{ $label }}</th>
                    @foreach ($c['measurements'] as $m)
                        <th class="right" style="width: 62pt">Medición {{ $m['order'] }}</th>
                    @endforeach
                    <th class="right" style="width: 54pt">Diferencia</th>
                </tr>
                @foreach ($groups as $g)
                    <tr>
                        <td @class(['italic' => $g['name'] === 'Sin dato'])>{{ $g['name'] }}</td>
                        @foreach ($g['scores'] as $i => $score)
                            <td class="right">{{ $F::percent($score) }} ({{ $g['counts'][$i] }})</td>
                        @endforeach
                        <td class="right bold">{{ $F::points($g['delta']) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    @endforeach

    @if (count($c['people']))
        <h2 class="break-before">Acierto por persona</h2>
        <table class="light">
            <tr>
                <th>Persona</th>
                <th style="width: 90pt">Área</th>
                @foreach ($c['measurements'] as $m)
                    <th class="right" style="width: 50pt">Med. {{ $m['order'] }}</th>
                @endforeach
                <th class="right" style="width: 46pt">Dif.</th>
            </tr>
            @foreach ($c['people'] as $p)
                <tr>
                    <td>{{ $p['name'] }}</td>
                    <td>{{ $p['area'] ?? '—' }}</td>
                    @foreach ($p['scores'] as $x)
                        <td class="right">{{ $F::percent($x) }}</td>
                    @endforeach
                    <td class="right bold">{{ $F::points($p['delta']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    <p class="note" style="margin-top: 10pt">Las respuestas abiertas de cada medición, lado a lado, están en el archivo Excel.</p>
@endsection
