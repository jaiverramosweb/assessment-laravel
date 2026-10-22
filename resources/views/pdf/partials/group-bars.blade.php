{{-- Client report groups (area or cargo): submissions, bar and average score. --}}
@php($F = \App\Services\Reports\Format::class)
@php($P = \App\Services\Exports\Pdf::class)
<table class="bars" style="margin-bottom: 14pt">
    <tr>
        <td class="muted bold" style="width: 150pt">{{ $label }}</td>
        <td class="muted bold right" style="width: 50pt">Entregas</td>
        <td style="width: 196pt"></td>
        <td class="muted bold right" style="width: 50pt">Acierto</td>
    </tr>
    @foreach ($groups as $g)
        <tr>
            <td @class(['italic' => $g['name'] === 'Sin dato'])>{{ $g['name'] }}</td>
            <td class="right">{{ $g['submissions'] }}</td>
            <td>{!! $P::bar($g['averageScore'] ?? 0, true, $accent) !!}</td>
            <td class="right bold">{{ $F::percent($g['averageScore']) }}</td>
        </tr>
    @endforeach
</table>
