@use('App\Support\Money\Money')
@php $c = $p->contract; $t = $c?->terms; $pct = fn (int $b) => \App\Support\Percent::format($b); @endphp
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-3"><a href="{{ route('business.projects') }}" class="text-sm font-medium text-brand-700">← My projects</a>
            @can('update', $p)<x-ui.button :href="route('business.projects.edit', $p)" size="sm">Edit project</x-ui.button>@endcan</div>

        @foreach($feedback->whereIn('action', ['project.requestRevision', 'project.reject'])->take(1) as $f)
            <x-ui.alert :type="$f->action === 'project.reject' ? 'error' : 'warning'" :title="$f->action === 'project.reject' ? 'Project rejected' : 'Revision requested'">{{ $f->reason ?: 'No reason was recorded.' }}</x-ui.alert>
        @endforeach

        <x-ui.card :title="$p->title"><p class="text-sm text-ink-700">{{ $p->description }}</p>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-ink-500">Contract</dt><dd class="font-medium">{{ $p->contract_type->label() }}</dd></div><div><dt class="text-ink-500">Status</dt><dd><x-status-badge :status="$p->status" /></dd></div>
                <div><dt class="text-ink-500">Funding target</dt><dd class="font-medium">{{ $p->fundingTarget()->format() }}</dd></div><div><dt class="text-ink-500">Duration</dt><dd class="font-medium">{{ $p->duration_months }} months</dd></div></dl>
            <div class="mt-4"><x-ui.progress :value="$p->fundingPercent()" /><p class="mt-1 text-xs text-ink-500">{{ $p->fundedAmount()->format() }} received of {{ $p->fundingTarget()->format() }}.</p></div></x-ui.card>

        @if($t)<x-ui.card title="Contract terms">
            <dl class="grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-ink-500">Contract number</dt><dd class="font-medium">{{ $c->contract_number }}</dd></div><div><dt class="text-ink-500">Contract status</dt><dd><x-status-badge :status="$c->status" /></dd></div>
            @if($p->contract_type === \App\Enums\ContractType::Mudarabah)<div><dt class="text-ink-500">Profit-sharing</dt><dd class="font-medium">Investor {{ $pct($t->investor_profit_bps) }} / You {{ $pct($t->business_profit_bps) }}</dd></div><div><dt class="text-ink-500">Capital required</dt><dd class="font-medium">{{ Money::minor($t->capital_required)->format() }}</dd></div>
            @elseif($p->contract_type === \App\Enums\ContractType::Musharakah)<div><dt class="text-ink-500">Contributions</dt><dd class="font-medium">Investor {{ Money::minor($t->investor_contribution)->format() }} / You {{ Money::minor($t->business_contribution)->format() }}</dd></div><div><dt class="text-ink-500">Profit ratio</dt><dd class="font-medium">{{ $pct($t->investor_profit_bps) }} / {{ $pct($t->business_profit_bps) }}</dd></div>
            @else<div><dt class="text-ink-500">Purchase cost</dt><dd class="font-medium">{{ Money::minor($t->purchase_cost)->format() }}</dd></div><div><dt class="text-ink-500">Murabaha sale profit</dt><dd class="font-medium">{{ Money::minor($t->sale_profit)->format() }}</dd></div><div><dt class="text-ink-500">Sale price</dt><dd class="font-medium">{{ Money::minor($t->sale_price)->format() }}</dd></div><div><dt class="text-ink-500">Installments</dt><dd class="font-medium">{{ $t->installments_count }}</dd></div>@endif
            </dl></x-ui.card>@endif

        <x-ui.card title="Documents">@forelse($p->documents as $d)<div class="flex items-center justify-between border-b border-ink-100 py-2 text-sm last:border-0"><span>{{ $d->title }} <span class="text-ink-500">· {{ $d->category->label() }}</span></span><span class="flex items-center gap-2"><x-status-badge :status="$d->verification_status" /><a class="btn-secondary btn-sm" href="{{ route('documents.show', $d) }}">Download</a></span></div>@empty<p class="text-sm text-ink-500">No documents attached.</p>@endforelse</x-ui.card>
    </div>
    <aside class="space-y-4 lg:self-start">
        <x-ui.card title="Review status">
            @php $r = $p->shariahReviews->sortByDesc('id')->first(); @endphp
            <p class="text-sm">Project: <x-status-badge :status="$p->status" /></p>
            <p class="mt-2 text-sm">Shariah review: <x-status-badge :status="$r?->status ?? \App\Enums\ShariahReviewStatus::Pending" /></p>
            <p class="mt-3 text-xs text-ink-500">{{ config('finance.shariah_disclaimer') }}</p>
        </x-ui.card>
        <x-wakalah.summary :project="$p" />
    </aside>
</div>
