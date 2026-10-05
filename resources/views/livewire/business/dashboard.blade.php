<div class="space-y-6">
    @if($business->kyc_status !== \App\Enums\KycStatus::Approved)
        <x-ui.alert type="warning" title="Business verification needed">Verify your business before projects can be published. Status: <strong>{{ $business->kyc_status->label() }}</strong>.</x-ui.alert>
    @endif
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-dashboard.stat-card label="Active Projects" :value="number_format($active)" hint="Projects currently funding or running." />
        <x-dashboard.stat-card label="Funding Received" :value="$funding->format()" hint="Capital raised across your projects." />
        <x-dashboard.stat-card label="Capital Deployed" :value="$funding->format()" hint="Funds put to use in approved activity." />
        <x-dashboard.stat-card label="Outstanding Obligations" :value="\App\Support\Money\Money::zero()->format()" hint="Amounts you currently owe under your contracts." />
    </div>
    <x-ui.card title="Your projects">
        @forelse($projects as $p)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 py-3 last:border-0">
                <div><p class="font-medium text-ink-900">{{ $p->title }}</p><p class="text-xs text-ink-500">{{ $p->contract_type->label() }}</p></div>
                <div class="w-full sm:w-64"><x-ui.progress :value="$p->fundingPercent()" /></div>
                <x-status-badge :status="$p->status" />
            </div>
        @empty
            <x-ui.empty-state title="You haven't submitted a project yet." :action="route('business.projects.create')" action-label="Create Project" />
        @endforelse
    </x-ui.card>
</div>
