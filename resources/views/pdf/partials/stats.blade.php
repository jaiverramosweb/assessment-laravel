{{-- Row of big numbers ([label, value] pairs). --}}
<table class="stats">
    <tr>
        @foreach ($items as [$label, $value])
            <td style="width: {{ 100 / count($items) }}%">
                <div class="stat-label">{{ $label }}</div>
                <div class="stat-value" @isset($valueSize) style="font-size: {{ $valueSize }}pt" @endisset>{{ $value }}</div>
            </td>
        @endforeach
    </tr>
</table>
