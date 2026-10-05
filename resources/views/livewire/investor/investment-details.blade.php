@use('App\Support\Money\Money')
@php $p = $i->project; $c = $i->contract; $t = $c?->terms; $pct = fn (int $b) => rtrim(rtrim(number_format($b / 100, 2), '0'), '.').'%';
    $done = fn (bool $x) => $x;
    $steps = [
        ['Contract approved', (bool) $c?->approved_at, $c?->approved_at?->format('d M Y')],
        ['Investment confirmed', true, $i->invested_at?->format('d M Y')],
        ['Capital deployed', in_array($p->status->value, ['ACTIVE', 'COMPLETED']), null],
        ['Business activity', in_array($p->status->value, ['ACTIVE', 'COMPLETED']), null],
        ['Profit / sale', $profit > 0 || $i->status->value === 'COMPLETED', null],
        ['Settlement', $i->status->value === 'COMPLETED', null],
    ];
@endphp
<div class="space-y-6">
    <a href="{{ route('investor.investments') }}" class="text-sm font-medium text-brand-700">← My investments</a>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Project">
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-ink-500">Project</dt><dd class="font-medium">{{ $p->title }}</dd></div>
                    <div><dt class="text-ink-500">Business</dt><dd class="font-medium">{{ $p->business->name }}</dd></div>
                    <div><dt class="text-ink-500">Contract</dt><dd class="font-medium">{{ $p->contract_type->label() }}</dd></div>
                    <div><dt class="text-ink-500">Status</dt><dd><x-status-badge :status="$i->status" /></dd></div>
                    <div class="sm:col-span-2"><dt class="text-ink-500">Purpose</dt><dd>{{ $p->purpose ?: $p->description }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Financial summary" subtitle="Principal and profit are always shown separately">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-dashboard.stat-card label="Principal" :value="Money::minor($i->amount)->format()" hint="The capital you committed." />
                    <x-dashboard.stat-card label="Profit" :value="Money::minor($profit)->format()" hint="Profit actually distributed to you." />
                    <x-dashboard.stat-card label="Returned principal" :value="Money::minor($returned)->format()" hint="Principal already back in your wallet." />
                    <x-dashboard.stat-card label="Pending amount" :value="Money::minor($pending)->format()" hint="Principal still deployed in the contract." />
                </div>
            </x-ui.card>

            <x-ui.card title="Contract terms">
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-ink-500">Contract number</dt><dd class="font-medium">{{ $c?->contract_number ?? 'Issued when funding completes' }}</dd></div>
                    <div><dt class="text-ink-500">Dates</dt><dd class="font-medium">{{ $i->invested_at?->format('d M Y') }} → {{ $i->maturity_date?->format('d M Y') }}</dd></div>
                    @if($t && $p->contract_type === \App\Enums\ContractType::Mudarabah)
                        <div><dt class="text-ink-500">Profit-sharing ratio</dt><dd class="font-medium">Investor {{ $pct($t->investor_profit_bps) }} / Business {{ $pct($t->business_profit_bps) }} of actual profit</dd></div>
                        <div class="sm:col-span-2"><dt class="text-ink-500">Loss terms</dt><dd>{{ $t->loss_terms ?: 'Loss of capital falls on the investor unless caused by manager negligence or breach.' }}</dd></div>
                    @elseif($t && $p->contract_type === \App\Enums\ContractType::Musharakah)
                        <div><dt class="text-ink-500">Contributions</dt><dd class="font-medium">Investor {{ Money::minor($t->investor_contribution)->format() }} / Business {{ Money::minor($t->business_contribution)->format() }}</dd></div>
                        <div><dt class="text-ink-500">Ownership</dt><dd class="font-medium">{{ $pct($t->investor_ownership_bps) }} / {{ $pct($t->business_ownership_bps) }}</dd></div>
                        <div><dt class="text-ink-500">Profit ratio</dt><dd class="font-medium">{{ $pct($t->investor_profit_bps) }} / {{ $pct($t->business_profit_bps) }}</dd></div>
                        <div><dt class="text-ink-500">Loss allocation</dt><dd class="font-medium">{{ $t->loss_allocation_basis->label() }}</dd></div>
                    @endif
                </dl>
                <p class="mt-3 text-xs text-ink-500">No return is guaranteed. {{ config('finance.shariah_disclaimer') }}</p>
            </x-ui.card>

            <x-ui.card title="Documents">
                @forelse($documents as $d)
                    <div class="flex items-center justify-between border-b border-ink-100 py-2 text-sm last:border-0"><span>{{ $d->title }} <span class="text-ink-500">· {{ $d->category->label() }}</span></span><a class="btn-secondary btn-sm" href="{{ route('documents.show', $d) }}">Download</a></div>
                @empty<p class="text-sm text-ink-500">No documents are available for this project yet.</p>@endforelse
            </x-ui.card>
        </div>

        <x-ui.card title="Activity timeline" class="lg:self-start">
            <ol>@foreach($steps as [$label, $ok, $when])<x-dashboard.activity-item :title="$label" :done="$ok" :time="$when" />@endforeach</ol>
        </x-ui.card>
    </div>
</div>
