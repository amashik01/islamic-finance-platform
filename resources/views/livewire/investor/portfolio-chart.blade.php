@use('App\Support\Money\Money')
<x-ui.card title="Portfolio overview" subtitle="Principal, returned principal and profit are shown separately">
    <x-slot:actions>
        <div class="flex flex-wrap gap-1" role="group" aria-label="Time range">@foreach($ranges as $r)<button wire:click="setRange('{{ $r }}')" @class(['btn-sm rounded-control px-2.5 py-1 text-xs font-semibold', 'bg-brand-700 text-white' => $range === $r, 'bg-ink-100 text-ink-700' => $range !== $r]) aria-pressed="{{ $range === $r ? 'true' : 'false' }}">{{ $r }}</button>@endforeach</div>
    </x-slot:actions>
    <div wire:loading.delay class="mb-2 text-sm text-ink-500">Loading...</div>
    <dl class="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div><dt class="text-ink-500">Invested capital</dt><dd class="font-semibold">{{ Money::minor($totals['invested'])->format() }}</dd></div>
        <div><dt class="text-ink-500">Returned principal</dt><dd class="font-semibold">{{ Money::minor($totals['returned'])->format() }}</dd></div>
        <div><dt class="text-ink-500">Realised profit</dt><dd class="font-semibold">{{ Money::minor($totals['profit'])->format() }}</dd></div>
        <div><dt class="text-ink-500">Pending amount</dt><dd class="font-semibold">{{ Money::minor($totals['pending'])->format() }}</dd></div>
    </dl>
    @if(array_sum(array_map('array_sum', $chart['series'])) === 0)
        <x-ui.empty-state title="No activity in this period." message="Investments, returns and profit will chart here." />
    @else
        <x-charts.grouped-bars :labels="$chart['labels']" :series="$chart['series']" title="Portfolio activity" />
    @endif
</x-ui.card>
