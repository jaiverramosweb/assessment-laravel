{{-- Cover page: client logo (data URL), kicker, title, optional text and key/value rows. --}}
@if (! empty($meta['logoDataUrl']))
    <div class="cover-logo"><img src="{{ $meta['logoDataUrl'] }}" alt=""></div>
    <p class="kicker" style="margin-top: 36pt">{{ $kicker }}</p>
@else
    <p class="kicker" style="margin-top: 180pt">{{ $kicker }}</p>
@endif
<div class="cover-title">{{ $title }}</div>
@if (! empty($text))
    <p class="cover-text">{{ $text }}</p>
@endif
@if (! empty($rows))
    <table class="cover-table" style="margin-top: {{ $rowsMargin ?? 40 }}pt">
        @foreach ($rows as [$k, $v])
            <tr><td class="k">{{ $k }}</td><td>{{ $v }}</td></tr>
        @endforeach
    </table>
@endif
<div class="page-break"></div>
