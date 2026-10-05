{{-- Donut for <=5 parts of a whole, with legend values (never colour-only). --}}
@props(['data', 'title' => 'Chart'])
@php
    $colors = ['#0f8a63', '#c9951f', '#2f6fc4', '#c4492f', '#6b6a64'];
    $total = array_sum($data); $r = 52; $c = 2 * M_PI * $r; $offset = 0;
@endphp
<figure class="flex flex-wrap items-center gap-6"><figcaption class="sr-only">{{ $title }}</figcaption>
    <svg viewBox="0 0 140 140" class="h-36 w-36 shrink-0" role="img" aria-label="{{ $title }}">
        <circle cx="70" cy="70" r="{{ $r }}" fill="none" stroke="#efede8" stroke-width="18"/>
        @if($total > 0)@foreach(array_values($data) as $i => $v)@if($v > 0)@php $len = $c * $v / $total; @endphp
            <circle cx="70" cy="70" r="{{ $r }}" fill="none" stroke="{{ $colors[$i % 5] }}" stroke-width="18" stroke-dasharray="{{ max(0, $len - 2) }} {{ $c }}" stroke-dashoffset="{{ -$offset }}" transform="rotate(-90 70 70)"><title>{{ array_keys($data)[$i] }}: {{ $v }}</title></circle>@php $offset += $len; @endphp
        @endif @endforeach @endif
        <text x="70" y="68" text-anchor="middle" font-size="22" font-weight="600" fill="#1d1c1a">{{ $total }}</text><text x="70" y="84" text-anchor="middle" font-size="9" fill="#5b5851">projects</text>
    </svg>
    <ul class="space-y-1.5 text-sm">@foreach($data as $label => $v)<li class="flex items-center gap-2"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: {{ $colors[$loop->index % 5] }}"></span><span class="text-ink-700">{{ $label }}</span><strong class="ml-auto pl-4 tabular-nums">{{ $v }}@if($total) <span class="font-normal text-ink-500">({{ round($v * 100 / $total) }}%)</span>@endif</strong></li>@endforeach</ul></figure>
