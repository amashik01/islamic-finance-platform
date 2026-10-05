<div class="space-y-6">
    @if($notice)<x-ui.alert type="success">{{ $notice }}</x-ui.alert>@endif
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-dashboard.stat-card label="Available Balance" :value="$b['available']->format()" hint="Ready to invest or withdraw." />
        <x-dashboard.stat-card label="Invested Balance" :value="$b['invested']->format()" hint="Principal currently in contracts." />
        <x-dashboard.stat-card label="Pending Balance" :value="$b['pending']->format()" hint="Withdrawals in process. Pending deposits: {{ $b['pending_deposits']->format() }}." />
        <x-dashboard.stat-card label="Withdrawable Balance" :value="$b['withdrawable']->format()" hint="The most you can request to withdraw now." />
    </div>
    <div class="flex flex-wrap gap-3">
        <x-ui.button x-on:click="$dispatch('open-modal', 'deposit')">Deposit</x-ui.button>
        <x-ui.button variant="secondary" :href="route('investor.withdrawals')">Withdraw</x-ui.button>
        <x-ui.button variant="secondary" :href="route('investor.transactions')">Statement</x-ui.button>
    </div>

    <x-ui.card title="Recent transactions">
        @include('livewire.investor.partials.transactions', ['transactions' => $transactions])
    </x-ui.card>

    <x-ui.modal name="deposit" title="Deposit funds">
        @if($error)<x-ui.alert type="error" class="mb-3">{{ $error }}</x-ui.alert>@endif
        <p class="text-ink-600">Transfer funds to the platform account, then enter the amount and your payment reference. Your balance is credited after verification.</p>
        <label for="dep-amount" class="label mt-4">Amount (BDT)</label>
        <input id="dep-amount" wire:model="depositAmount" inputmode="decimal" class="input" autocomplete="off">
        @error('depositAmount')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        <label for="dep-ref" class="label mt-3">Payment reference (optional)</label>
        <input id="dep-ref" wire:model="paymentReference" class="input">
        @error('paymentReference')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'deposit')">Cancel</x-ui.button>
            <x-ui.button wire:click="requestDeposit" loading="requestDeposit" loading-text="Submitting...">Submit deposit</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
