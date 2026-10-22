{{-- Stored AI analysis (always rendered as escaped text). --}}
@php($r = $analysis['result'])
@php($frequency = ['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'])
<p style="margin-bottom: 6pt">{{ $r['summary'] }}</p>
@if (count($r['themes']))
    <h3>Temas principales</h3>
    <table class="light" style="margin-bottom: 6pt">
        <tr>
            <th style="width: 120pt">Tema</th>
            <th style="width: 70pt">Frecuencia</th>
            <th>Descripción</th>
        </tr>
        @foreach ($r['themes'] as $t)
            <tr>
                <td class="bold">{{ $t['name'] }}</td>
                <td>{{ $frequency[$t['frequency']] ?? $t['frequency'] }} (~{{ $t['approxCount'] }})</td>
                <td>{{ $t['description'] }}</td>
            </tr>
        @endforeach
    </table>
@endif
@foreach ([['Puntos destacados', $r['highlights']], ['Preocupaciones recurrentes', $r['concerns']]] as [$listTitle, $items])
    @if (count($items))
        <h3>{{ $listTitle }}</h3>
        <ul>
            @foreach ($items as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    @endif
@endforeach
@if (count($r['quotes']))
    <h3>Citas representativas</h3>
    @foreach ($r['quotes'] as $q)
        <p class="quote">“{{ $q }}”</p>
    @endforeach
@endif
<p class="note" style="margin-top: 4pt">Análisis generado con {{ $analysis['model'] }} sobre {{ $analysis['responseCount'] }} respuestas anónimas.</p>
