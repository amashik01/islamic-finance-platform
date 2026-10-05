@use('App\Support\Money\Money')
@use('App\Enums\ContractType')
@use('App\Enums\MurabahaStage', 'Stage')
@php $t = $c->terms; $type = $c->contract_type; $u = auth()->user(); $canManage = $u->can('contracts.manage'); @endphp
<div class="space-y-6">
    @if($notice)<x-ui.alert type="success">{{ $notice }}</x-ui.alert>@endif
    @if($error)<x-ui.alert type="error">{{ $error }}</x-ui.alert>@endif

    <x-ui.card :title="$c->contract_number" :subtitle="$type->label().' · '.$c->project->title">
        <dl class="grid gap-3 text-sm sm:grid-cols-4">
            <div><dt class="text-ink-500">Status</dt><dd><x-status-badge :status="$c->status" /></dd></div>
            <div><dt class="text-ink-500">Business</dt><dd class="font-medium">{{ $c->project->business->name }}</dd></div>
            <div><dt class="text-ink-500">Starts</dt><dd class="font-medium">{{ $c->start_date?->format('d M Y') ?: '—' }}</dd></div>
            <div><dt class="text-ink-500">Ends</dt><dd class="font-medium">{{ $c->end_date?->format('d M Y') ?: '—' }}</dd></div>
        </dl>
    </x-ui.card>

    @if($type !== ContractType::Murabaha)
        @if($c->status === \App\Enums\ContractStatus::Active)
            <x-ui.card title="Settle contract" subtitle="Records the actual result, returns principal and distributes profit as separate ledger transactions.">
                @unless($u->can('settlements.manage'))<p class="text-sm text-ink-500">You do not have permission to settle contracts.</p>@else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="net" class="label">Actual net result (BDT)</label><input id="net" wire:model.live.debounce.400ms="netResult" class="input" inputmode="decimal" placeholder="20000 for profit, -5000 for loss"><p class="help">The whole project's net profit (positive) or loss (negative).</p></div>
                    @if($type === ContractType::Mudarabah)<label class="mt-6 flex items-start gap-2 text-sm"><input type="checkbox" wire:model.live="managerAtFault" class="mt-1 rounded border-ink-300 text-brand-700"><span>Loss is due to the manager's negligence, misconduct or breach (recorded as recoverable from the manager).</span></label>@endif
                </div>
                @if($preview)<div class="mt-4 rounded-card bg-ink-50 p-4 text-sm" aria-live="polite"><p class="mb-2 font-semibold">Preview (nothing is posted yet)</p>@foreach($preview as $label => $m)<p class="flex justify-between"><span>{{ $label }}</span><strong>{{ $m->format() }}</strong></p>@endforeach</div>@endif
                <x-ui.button class="mt-4" wire:click="$dispatch('open-modal', 'settle')" :disabled="$netResult === ''">Review and settle…</x-ui.button>
                @endunless
            </x-ui.card>
        @endif
        <x-ui.card title="Settlements">
            @forelse($c->settlements as $s)
                <div class="border-b border-ink-100 py-3 text-sm last:border-0"><p class="font-medium">{{ $s->reference }} <x-status-badge :status="$s->status" /></p>
                    <p class="text-ink-500">Net result {{ Money::minor((int) $s->actual_net_result)->format() }} · {{ $s->posted_at?->format('d M Y H:i') }}</p>
                    <ul class="mt-1 text-ink-700">@foreach($s->items->groupBy(fn ($i) => $i->item_type->label()) as $label => $rows)<li>{{ $label }}: {{ Money::minor((int) $rows->sum('amount'))->format() }}</li>@endforeach</ul></div>
            @empty<p class="text-sm text-ink-500">Not settled yet.</p>@endforelse
        </x-ui.card>

        <x-ui.modal name="settle" title="Confirm settlement">
            <p>This posts principal returns and profit to investor wallets and completes the contract. It cannot be undone; corrections are made by reversal.</p>
            <label for="reason" class="label mt-3">Reason / reference (required)</label><textarea id="reason" wire:model="reason" rows="3" class="input"></textarea>
            <x-slot:footer><x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'settle')">Cancel</x-ui.button><x-ui.button wire:click="settle" loading="settle" loading-text="Settling...">Post settlement</x-ui.button></x-slot:footer>
        </x-ui.modal>
    @else
        @php $m = $t; $stage = $m->stage; @endphp
        <x-ui.card title="Murabaha sale" subtitle="Purchase cost + Murabaha sale profit = sale price. This is a sale, not a loan.">
            <div class="grid gap-3 text-sm sm:grid-cols-3"><div><dt class="text-ink-500">Purchase cost</dt><dd class="text-lg font-semibold">{{ Money::minor($m->purchase_cost)->format() }}</dd></div><div><dt class="text-ink-500">+ Murabaha sale profit</dt><dd class="text-lg font-semibold">{{ Money::minor($m->sale_profit)->format() }}</dd></div><div><dt class="text-ink-500">= Sale price</dt><dd class="text-lg font-semibold">{{ Money::minor($m->sale_price)->format() }}</dd></div></div>
            <p class="mt-3 text-sm text-ink-600">Asset: @foreach($m->assets as $a){{ $a->quantity }} × {{ $a->name }} ({{ $a->supplier_name }})@endforeach</p>
        </x-ui.card>

        <x-ui.card title="Workflow">
            <ol class="mb-4 flex flex-wrap gap-2">@foreach($stages as $i => $s)<li @class(['rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset', 'bg-brand-700 text-white ring-brand-700' => $s === $stage, 'bg-brand-50 text-brand-800 ring-brand-200' => array_search($s, $stages) < array_search($stage, $stages), 'bg-white text-ink-500 ring-ink-200' => array_search($s, $stages) > array_search($stage, $stages)])>{{ $s->label() }}</li>@endforeach</ol>
            @if($canManage)
            <div class="grid gap-3 sm:grid-cols-3">
                <div><label for="date" class="label">Date</label><input id="date" type="date" wire:model="date" class="input"></div>
                @if($stage === Stage::Verified)<div><label for="inv" class="label">Supplier invoice reference</label><input id="inv" wire:model="invoice" class="input"></div>@endif
                @if($stage === Stage::Owned)<div class="sm:col-span-2"><label for="notes" class="label">How possession (qabd) was taken</label><input id="notes" wire:model="notes" class="input"></div>@endif
                @if($stage === Stage::Possessed)<div><label for="due" class="label">First installment due</label><input id="due" type="date" wire:model="firstDue" class="input"></div>@endif
            </div>
            <div class="mt-4">
                @switch($stage)
                    @case(Stage::Requested)<x-ui.button wire:click="step('verify')" loading="step">Verify supplier &amp; asset</x-ui.button>@break
                    @case(Stage::Verified)<x-ui.button wire:click="step('purchase')" loading="step">Record asset purchase</x-ui.button>@break
                    @case(Stage::Purchased)<x-ui.button wire:click="step('ownership')" loading="step">Record ownership acquired</x-ui.button>@break
                    @case(Stage::Owned)<x-ui.button wire:click="step('possession')" loading="step">Record possession (qabd)</x-ui.button>@break
                    @case(Stage::Possessed)<x-ui.button wire:click="step('sale')" loading="step">Execute Murabaha sale</x-ui.button>@break
                @endswitch
            </div>
            <p class="mt-3 text-xs text-ink-500">Steps must be completed in order. The asset cannot be sold before it is owned and in possession.</p>
            @endif
        </x-ui.card>

        @if($m->sale?->receivable)
            @php $r = $m->sale->receivable; @endphp
            <x-ui.card title="Receivable and payments" :subtitle="'Outstanding: '.Money::minor($r->outstanding())->format().' of '.Money::minor($r->total_amount)->format()">
                <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Due</th><th>Amount</th><th>Paid</th><th>Status</th></tr></thead><tbody>
                    @foreach($r->schedules as $s)<tr><td>{{ $s->sequence }}</td><td>{{ $s->due_date->format('d M Y') }}</td><td>{{ Money::minor($s->amount)->format() }}</td><td>{{ Money::minor($s->paid_amount)->format() }}</td><td><x-status-badge :status="$s->status" /></td></tr>@endforeach
                </tbody></table></div>
                @if($canManage && $r->outstanding() > 0)
                    <div class="mt-4 flex flex-wrap items-end gap-3"><div><label for="pay" class="label">Record payment (BDT)</label><input id="pay" wire:model="payAmount" class="input" inputmode="decimal"></div><x-ui.button wire:click="recordPayment" loading="recordPayment" loading-text="Recording...">Record payment</x-ui.button></div>
                @endif
            </x-ui.card>
        @endif
    @endif
</div>
