<div class="grid gap-6 lg:grid-cols-2">
    <x-ui.card title="Capital flow" class="lg:col-span-2" subtitle="Deposits, investments, returns and withdrawals">
        <x-slot:actions><div class="flex flex-wrap gap-1" role="group" aria-label="Time range">@foreach($ranges as $r)<button wire:click="setRange('{{ $r }}')" @class(['rounded-control px-2.5 py-1 text-xs font-semibold', 'bg-brand-700 text-white' => $range === $r, 'bg-ink-100 text-ink-700' => $range !== $r]) aria-pressed="{{ $range === $r ? 'true' : 'false' }}">{{ $r }}</button>@endforeach</div></x-slot:actions>
        @if(array_sum(array_map('array_sum', $flow['series'])) === 0)<x-ui.empty-state title="No capital movement in this period." />
        @else<x-charts.grouped-bars :labels="$flow['labels']" :series="$flow['series']" title="Capital flow" />@endif
    </x-ui.card>
    <x-ui.card title="Contract distribution"><x-charts.donut :data="$distribution" title="Projects by contract type" /></x-ui.card>
    <x-ui.card title="Project lifecycle"><x-charts.hbars :data="$lifecycle" title="Projects by status" /></x-ui.card>
    <x-ui.card title="Monthly activity" class="lg:col-span-2" subtitle="Last six months"><x-charts.grouped-bars :labels="$activity['labels']" :series="$activity['series']" format="count" title="Monthly activity" /></x-ui.card>
</div>
