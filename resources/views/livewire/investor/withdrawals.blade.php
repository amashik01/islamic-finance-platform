@use('App\Support\Money\Money')
<div class="grid gap-6 lg:grid-cols-3">
    <x-ui.card title="Request a withdrawal" class="lg:self-start">
        @if($notice)<x-ui.alert type="success" class="mb-3">{{ $notice }}</x-ui.alert>@endif
        @if($error)<x-ui.alert type="error" class="mb-3">{{ $error }}</x-ui.alert>@endif
        @unless($investor->isVerified() && $investor->bank_verified)<x-ui.alert type="warning" class="mb-3" title="Verification required">You need verified identity and a verified bank account to withdraw.</x-ui.alert>@endunless
        <form wire:submit="submit" class="space-y-3">
            <p class="text-sm text-ink-600">Withdrawable balance: <strong>{{ $balance->format() }}</strong></p>
            <div><label for="w-amount" class="label">Amount (BDT)</label><input id="w-amount" wire:model="amount" inputmode="decimal" class="input" autocomplete="off">
                @error('amount')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                <p class="help">Minimum {{ Money::minor(config('finance.limits.min_withdrawal'))->format() }} · Maximum {{ Money::minor(config('finance.limits.max_withdrawal'))->format() }} per request.</p></div>
            <x-ui.button type="submit" class="w-full" loading="submit" loading-text="Submitting...">Request withdrawal</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card title="Withdrawal history" class="lg:col-span-2">
        @forelse($withdrawals as $w)
            <div wire:key="w-{{ $w->id }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 py-3 text-sm last:border-0">
                <div><p class="font-medium">{{ Money::minor($w->amount, $w->currency)->format() }} <span class="font-mono text-xs text-ink-500">{{ $w->reference }}</span></p><p class="text-xs text-ink-500">{{ $w->created_at->format('d M Y H:i') }}@if($w->reason && in_array($w->status->value, ['REJECTED'])) · Reason: {{ $w->reason }}@endif</p></div>
                <div class="flex items-center gap-2"><x-status-badge :status="$w->status" />
                    @if(in_array($w->status, [\App\Enums\WithdrawalStatus::Pending, \App\Enums\WithdrawalStatus::UnderReview]))<button wire:click="cancel({{ $w->id }})" wire:confirm="Cancel this withdrawal and release the funds?" class="btn-ghost btn-sm">Cancel</button>@endif</div>
            </div>
        @empty<x-ui.empty-state title="No withdrawals yet." message="Requests you make will appear here with their status." />@endforelse
        <p class="mt-4 text-xs text-ink-500">Status flow: Pending → Under review → Approved → Processing → Paid. A request can also be rejected or cancelled, in which case funds return to your available balance.</p>
    </x-ui.card>
</div>
