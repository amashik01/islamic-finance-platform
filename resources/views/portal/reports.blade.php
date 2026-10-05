@php $catalog = collect(\App\Services\Reports\ReportService::CATALOG[$portal])->filter(fn ($e) => ! $e[1] || auth()->user()->can($e[1])); @endphp
<x-dynamic-component :component="$portal.'-layout'" :title="$portal === 'investor' ? 'Profit & Returns' : 'Reports'">
    <div class="grid gap-4 md:grid-cols-2">
        @forelse($catalog as $key => [$label])
            <x-ui.card :title="$label">
                <p class="text-sm text-ink-600">Download as CSV for spreadsheets, or open a print-friendly version you can save as PDF.</p>
                <div class="mt-4 flex gap-2"><x-ui.button size="sm" :href="route('reports.download', [$portal, $key])">Download CSV</x-ui.button><x-ui.button size="sm" variant="secondary" :href="route('reports.download', [$portal, $key, 'format' => 'print'])" target="_blank">Print / PDF</x-ui.button></div>
            </x-ui.card>
        @empty<x-ui.empty-state title="No reports available for your role." />@endforelse
    </div>
</x-dynamic-component>
