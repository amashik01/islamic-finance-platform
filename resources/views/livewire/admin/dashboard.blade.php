<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($cards as [$label, $value, $hint, $href])<x-dashboard.stat-card :label="$label" :value="$value" :hint="$hint" :href="$href" />@endforeach
    </div>
    <x-ui.card title="Pending withdrawals" subtitle="Needs attention">
        @forelse($pendingWithdrawals as $w)
            @if($loop->first)<div class="table-wrap"><table class="table"><thead><tr><th>Reference</th><th>Investor</th><th>Amount</th><th>Requested</th><th>KYC</th><th>Status</th></tr></thead><tbody>@endif
            <tr><td class="font-mono text-xs">{{ $w->reference }}</td><td>{{ $w->user->name }}</td><td>{{ \App\Support\Money\Money::minor($w->amount)->format() }}</td><td>{{ $w->created_at->diffForHumans() }}</td><td>{{ $w->user->investor?->kyc_status->label() }}</td><td><x-status-badge :status="$w->status" /></td></tr>
            @if($loop->last)</tbody></table></div>@endif
        @empty
            <x-ui.empty-state title="No pending withdrawals." message="New withdrawal requests will appear here for review." />
        @endforelse
    </x-ui.card>
    <p class="text-xs text-ink-500">Charts (capital flow, contract distribution, project lifecycle) arrive with the reporting phase.</p>
</div>
