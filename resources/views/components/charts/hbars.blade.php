{{-- Single-series horizontal bars with direct value labels. $data: label => number --}}
@props(['data', 'title' => 'Chart', 'color' => '#0f8a63'])
@php $max = max(1, ...array_values($data ?: [0])); @endphp
<figure><figcaption class="sr-only">{{ $title }}</figcaption>
    <ul class="space-y-2">
        @foreach($data as $label => $v)
            <li class="grid grid-cols-[7rem_1fr_2.5rem] items-center gap-2 text-sm"><span class="truncate text-ink-700">{{ $label }}</span>
                <span class="h-3 overflow-hidden rounded-full bg-ink-100"><span class="block h-full rounded-full" style="width: {{ $v > 0 ? max(2, round($v * 100 / $max)) : 0 }}%; background: {{ $color }}"></span></span>
                <span class="text-right font-medium tabular-nums text-ink-900">{{ number_format($v) }}</span></li>
        @endforeach
    </ul></figure>
