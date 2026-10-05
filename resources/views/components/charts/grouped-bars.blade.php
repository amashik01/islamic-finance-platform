{{-- Grouped bar chart (SVG). $series: name => list of numbers; $labels: x labels; $format: 'money'|'count'. --}}
@props(['labels', 'series', 'format' => 'money', 'title' => 'Chart', 'height' => 220])
@php
    $colors = ['#0f8a63', '#c9951f', '#2f6fc4', '#c4492f', '#6b6a64'];
    $fmt = fn (int $v) => $format === 'money' ? \App\Support\Money\Money::minor($v)->format() : number_format($v);
    $max = max(1, ...array_values(array_map(fn ($s) => max($s ?: [0]), $series)));
    $n = count($labels); $k = max(1, count($series));
    $W = 640; $padL = 8; $padB = 26; $padT = 10; $H = $height; $plotH = $H - $padB - $padT; $groupW = ($W - $padL) / max(1, $n);
    $barW = max(3, min(18, ($groupW - 6) / $k - 2));
    $names = array_keys($series);
@endphp
<figure class="w-full" x-data="{ table: false }">
    <figcaption class="sr-only">{{ $title }}</figcaption>
    <div class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-700" role="list" aria-label="Legend">
        @foreach($names as $i => $name)<span role="listitem" class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: {{ $colors[$i % 5] }}"></span>{{ $name }}</span>@endforeach
        <button type="button" class="ml-auto text-brand-700 underline" @click="table = ! table" x-text="table ? 'Hide table' : 'View as table'">View as table</button>
    </div>
    <svg viewBox="0 0 {{ $W }} {{ $H }}" class="h-auto w-full" role="img" aria-label="{{ $title }}">
        @foreach([0.25, 0.5, 0.75, 1] as $g)<line x1="{{ $padL }}" x2="{{ $W }}" y1="{{ $padT + $plotH * (1 - $g) }}" y2="{{ $padT + $plotH * (1 - $g) }}" stroke="#e8e6e0" stroke-width="1"/>@endforeach
        <line x1="{{ $padL }}" x2="{{ $W }}" y1="{{ $padT + $plotH }}" y2="{{ $padT + $plotH }}" stroke="#c3bfb4" stroke-width="1"/>
        @foreach($labels as $li => $label)
            @php $gx = $padL + $li * $groupW + ($groupW - ($k * ($barW + 2))) / 2; @endphp
            @foreach($names as $si => $name)
                @php $v = $series[$name][$li] ?? 0; $h = $v > 0 ? max(2, $plotH * $v / $max) : 0; $x = $gx + $si * ($barW + 2); @endphp
                @if($h > 0)<rect x="{{ $x }}" y="{{ $padT + $plotH - $h }}" width="{{ $barW }}" height="{{ $h }}" rx="3" fill="{{ $colors[$si % 5] }}" class="transition hover:opacity-80"><title>{{ $name }} · {{ $label }}: {{ $fmt($v) }}</title></rect>@endif
            @endforeach
            @if($n <= 12 || $li % ceil($n / 12) === 0)<text x="{{ $padL + $li * $groupW + $groupW / 2 }}" y="{{ $H - 8 }}" text-anchor="middle" font-size="10" fill="#5b5851">{{ $label }}</text>@endif
        @endforeach
    </svg>
    <div x-show="table" x-cloak class="table-wrap mt-3"><table class="table"><caption class="sr-only">{{ $title }} data</caption><thead><tr><th scope="col">Period</th>@foreach($names as $name)<th scope="col">{{ $name }}</th>@endforeach</tr></thead><tbody>
        @foreach($labels as $li => $label)<tr><th scope="row" class="px-4 py-2 text-left font-medium">{{ $label }}</th>@foreach($names as $name)<td>{{ $fmt($series[$name][$li] ?? 0) }}</td>@endforeach</tr>@endforeach
    </tbody></table></div>
</figure>
