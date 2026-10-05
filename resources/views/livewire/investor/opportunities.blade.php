<div class="space-y-6">
    @if($success)<x-ui.alert type="success" title="Done">{{ $success }}</x-ui.alert>@endif
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter by contract">
        <button wire:click="$set('type', '')" @class(['btn-sm', 'btn-primary' => $type === '', 'btn-secondary' => $type !== ''])>All</button>
        @foreach(\App\Enums\ContractType::cases() as $c)<button wire:click="$set('type', '{{ $c->value }}')" @class(['btn-sm', 'btn-primary' => $type === $c->value, 'btn-secondary' => $type !== $c->value])>{{ $c->label() }}</button>@endforeach
        <span class="ml-auto text-sm text-ink-500">Available balance: <strong class="text-ink-900">{{ $balance->format() }}</strong></span>
    </div>

    <div wire:loading.delay wire:target="type" class="text-sm text-ink-500">Loading...</div>
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
        @forelse($projects as $p)
            <div wire:key="p-{{ $p->id }}" class="flex flex-col">
                <x-opportunity-card :project="$p" />
                @if($p->contract_type === \App\Enums\ContractType::Murabaha)
                    <p class="mt-2 text-xs text-ink-500">Murabaha is an asset-based financing sale to a business, not a pooled investment.</p>
                @else
                    <x-ui.button class="mt-2" wire:click="startInvest({{ $p->id }})">Invest</x-ui.button>
                @endif
            </div>
        @empty
            <div class="md:col-span-2 xl:col-span-3"><x-ui.empty-state title="No opportunities are open for investment right now." message="Approved projects appear here once they are published." /></div>
        @endforelse
    </div>

    <x-ui.modal name="invest" title="Confirm investment" max-width="lg">
        @if($selected)
            <p class="font-medium text-ink-900">{{ $selected->title }}</p>
            <p class="text-xs text-ink-500">{{ $selected->contract_type->label() }} · {{ $selected->business->name }} · {{ $selected->risk_level->label() }}</p>
            @if($error)<x-ui.alert type="error" class="mt-3">{{ $error }}</x-ui.alert>@endif
            <label for="inv-amount" class="label mt-4">Amount ({{ $selected->currency }})</label>
            <input id="inv-amount" type="text" inputmode="decimal" wire:model="amount" class="input" placeholder="e.g. 10000" autocomplete="off">
            @error('amount')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <p class="help">Minimum {{ \App\Support\Money\Money::minor($selected->minimum_amount)->format() }} · Remaining capacity {{ $selected->remainingCapacity()->format() }} · Your available balance {{ $balance->format() }}</p>
            <x-ui.alert type="warning" class="mt-4" title="Risk notice">Returns depend on actual business results and are not guaranteed. You may lose part or all of your capital. {{ config('finance.shariah_disclaimer') }}</x-ui.alert>
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'invest')">Cancel</x-ui.button>
            <x-ui.button wire:click="confirm" loading="confirm" loading-text="Processing...">Confirm investment</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
