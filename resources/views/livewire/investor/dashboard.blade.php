<div class="space-y-6">
    @unless($investor->isVerified())
        <x-ui.alert type="warning" title="Verification needed">Complete identity verification to invest and withdraw. Current status: <strong>{{ $investor->kyc_status->label() }}</strong>.</x-ui.alert>
    @endunless

    <div wire:loading.delay class="text-sm text-ink-500">Loading...</div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-dashboard.stat-card label="Available Balance" :value="$balances['available']->format()" hint="Cash you can invest or withdraw right now." />
        <x-dashboard.stat-card label="Invested Capital" :value="$balances['invested']->format()" hint="Principal currently placed in approved contracts." />
        <x-dashboard.stat-card label="Profit Received" :value="\App\Support\Money\Money::zero()->format()" hint="Profit actually paid out to you. Shown separately from principal." />
        <x-dashboard.stat-card label="Pending Returns" :value="$balances['pending']->format()" hint="Amounts in process, such as withdrawals under review." />
        <x-dashboard.stat-card label="Portfolio Value" :value="$balances['available']->add($balances['invested'])->format()" hint="Available plus invested principal. Excludes any unrealised profit." />
    </div>

    <x-ui.card title="Active investments" subtitle="Where your capital is today">
        @forelse ($investments as $i)
            @if($loop->first)<div class="table-wrap hidden sm:block"><table class="table"><thead><tr><th>Project</th><th>Contract</th><th>Invested</th><th>Status</th><th>Maturity</th></tr></thead><tbody>@endif
            <tr class="hidden sm:table-row"><td class="font-medium text-ink-900">{{ $i->project->title }}</td><td>{{ $i->project->contract_type->label() }}</td><td>{{ \App\Support\Money\Money::minor($i->amount)->format() }}</td><td><x-status-badge :status="$i->status" /></td><td>{{ $i->maturity_date?->format('d M Y') }}</td></tr>
            @if($loop->last)</tbody></table></div>@endif
        @empty
            <x-ui.empty-state title="No active investments yet." message="Explore approved opportunities to start building your portfolio." :action="route('investor.opportunities')" action-label="Explore Opportunities" />
        @endforelse
        {{-- Mobile cards --}}
        <div class="space-y-3 sm:hidden">@foreach($investments as $i)
            <div class="rounded-control border border-ink-100 p-3"><p class="font-medium">{{ $i->project->title }}</p><p class="text-xs text-ink-500">{{ $i->project->contract_type->label() }} · {{ $i->maturity_date?->format('d M Y') }}</p><div class="mt-2 flex justify-between text-sm"><span>{{ \App\Support\Money\Money::minor($i->amount)->format() }}</span><x-status-badge :status="$i->status" /></div></div>
        @endforeach</div>
    </x-ui.card>
</div>
