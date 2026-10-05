@use('App\Enums\ProjectStatus', 'S')
@use('App\Support\Money\Money')
@php
    $c = $p->contract; $t = $c?->terms; $s = $p->status; $u = auth()->user();
    $pct = fn (int $bps) => rtrim(rtrim(number_format($bps / 100, 2), '0'), '.').'%';
@endphp
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        @if($notice)<x-ui.alert type="success">{{ $notice }}</x-ui.alert>@endif

        <x-ui.card title="Project overview">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-ink-500">Title</dt><dd class="font-medium">{{ $p->title }}</dd></div>
                <div><dt class="text-ink-500">Industry</dt><dd class="font-medium">{{ $p->industry ?: '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-ink-500">Description</dt><dd>{{ $p->description }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-ink-500">Purpose</dt><dd>{{ $p->purpose ?: '—' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Business information">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-ink-500">Business</dt><dd class="font-medium">{{ $p->business->name }}</dd></div>
                <div><dt class="text-ink-500">Verification</dt><dd><x-status-badge :status="$p->business->kyc_status" /></dd></div>
                <div><dt class="text-ink-500">Registration no.</dt><dd>{{ $p->business->registration_number ?: '—' }}</dd></div>
                <div><dt class="text-ink-500">Contact</dt><dd>{{ $p->business->user->email }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card :title="'Contract information — '.$p->contract_type->label()">
            @if(! $t)<p class="text-sm text-ink-500">No contract terms have been added.</p>
            @else
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-ink-500">Contract number</dt><dd class="font-medium">{{ $c->contract_number }}</dd></div>
                <div><dt class="text-ink-500">Status</dt><dd><x-status-badge :status="$c->status" /></dd></div>
                @if($p->contract_type === \App\Enums\ContractType::Mudarabah)
                    <div><dt class="text-ink-500">Investor profit share</dt><dd class="font-medium">{{ $pct($t->investor_profit_bps) }} of actual profit</dd></div>
                    <div><dt class="text-ink-500">Business profit share</dt><dd class="font-medium">{{ $pct($t->business_profit_bps) }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-ink-500">Loss terms</dt><dd>{{ $t->loss_terms ?: 'Loss of capital falls on the investor unless caused by manager negligence or breach.' }}</dd></div>
                @elseif($p->contract_type === \App\Enums\ContractType::Musharakah)
                    <div><dt class="text-ink-500">Investor contribution</dt><dd class="font-medium">{{ Money::minor($t->investor_contribution)->format() }} ({{ $pct($t->investor_ownership_bps) }} ownership)</dd></div>
                    <div><dt class="text-ink-500">Business contribution</dt><dd class="font-medium">{{ Money::minor($t->business_contribution)->format() }} ({{ $pct($t->business_ownership_bps) }} ownership)</dd></div>
                    <div><dt class="text-ink-500">Profit ratio (investor / business)</dt><dd class="font-medium">{{ $pct($t->investor_profit_bps) }} / {{ $pct($t->business_profit_bps) }}</dd></div>
                    <div><dt class="text-ink-500">Loss allocation</dt><dd class="font-medium">{{ $t->loss_allocation_basis->label() }}</dd></div>
                @else
                    <div><dt class="text-ink-500">Purchase cost</dt><dd class="font-medium">{{ Money::minor($t->purchase_cost)->format() }}</dd></div>
                    <div><dt class="text-ink-500">Murabaha sale profit</dt><dd class="font-medium">{{ Money::minor($t->sale_profit)->format() }}</dd></div>
                    <div><dt class="text-ink-500">Sale price</dt><dd class="font-medium">{{ Money::minor($t->sale_price)->format() }}</dd></div>
                    <div><dt class="text-ink-500">Installments</dt><dd class="font-medium">{{ $t->installments_count }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-ink-500">Asset / supplier</dt><dd>@forelse($t->assets as $a){{ $a->quantity }} × {{ $a->name }} — {{ $a->supplier_name }}@if(! $loop->last), @endif @empty — @endforelse</dd></div>
                    <div><dt class="text-ink-500">Stage</dt><dd><x-ui.badge>{{ $t->stage->label() }}</x-ui.badge></dd></div>
                @endif
            </dl>
            @endif
        </x-ui.card>

        <x-ui.card title="Financial information">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-ink-500">Funding target</dt><dd class="font-medium">{{ $p->fundingTarget()->format() }}</dd></div>
                <div><dt class="text-ink-500">Funded</dt><dd class="font-medium">{{ $p->fundedAmount()->format() }}</dd></div>
                <div><dt class="text-ink-500">Minimum investment</dt><dd class="font-medium">{{ Money::minor($p->minimum_amount)->format() }}</dd></div>
                <div><dt class="text-ink-500">Duration</dt><dd class="font-medium">{{ $p->duration_months }} months</dd></div>
            </dl>
            <div class="mt-3"><x-ui.progress :value="$p->fundingPercent()" /></div>
        </x-ui.card>

        <x-ui.card title="Documents">
            @forelse($p->documents as $d)
                <div class="flex items-center justify-between gap-3 border-b border-ink-100 py-2 text-sm last:border-0">
                    <span>{{ $d->title }} <span class="text-ink-500">· {{ $d->category->label() }} · v{{ $d->version }}</span></span>
                    <span class="flex items-center gap-2"><x-status-badge :status="$d->verification_status" /><a class="btn-secondary btn-sm" href="{{ route('documents.show', ['document' => $d, 'inline' => 1]) }}" target="_blank" rel="noopener">Open</a></span>
                </div>
            @empty<p class="text-sm text-ink-500">No documents uploaded.</p>@endforelse
        </x-ui.card>

        <x-ui.card title="Risk information">
            <p class="text-sm">Risk level: <strong>{{ $p->risk_level->label() }}</strong></p>
            <p class="mt-2 text-sm text-ink-700">{{ $p->key_risks ?: 'No key risks recorded.' }}</p>
        </x-ui.card>

        <x-ui.card title="Shariah review">
            @forelse($p->shariahReviews->sortByDesc('id') as $r)
                <div class="border-b border-ink-100 py-2 text-sm last:border-0"><x-status-badge :status="$r->status" /> <span class="text-ink-500">{{ $r->reviewer?->name ?? 'Unassigned' }} {{ $r->reviewed_at?->format('d M Y') }}</span>@if($r->notes)<p class="mt-1">{{ $r->notes }}</p>@endif</div>
            @empty<p class="text-sm text-ink-500">No review yet.</p>@endforelse
            <p class="mt-3 text-xs text-ink-500">{{ config('finance.shariah_disclaimer') }}</p>
        </x-ui.card>

        <x-ui.card title="Approval and audit history">
            @forelse($history as $h)
                <div class="border-b border-ink-100 py-2 text-sm last:border-0"><span class="font-medium">{{ $h->action }}</span> <span class="text-ink-500">by {{ $h->user?->name ?? 'System' }} · {{ $h->created_at->format('d M Y H:i') }}</span>@if($h->reason)<p class="text-ink-600">“{{ $h->reason }}”</p>@endif</div>
            @empty<p class="text-sm text-ink-500">No history yet.</p>@endforelse
        </x-ui.card>
    </div>

    <aside class="space-y-4 lg:sticky lg:top-24 lg:self-start">
        <x-ui.card title="Status">
            <p><x-status-badge :status="$s" /></p>
            <dl class="mt-3 space-y-1 text-sm"><div class="flex justify-between"><dt class="text-ink-500">Assigned reviewer</dt><dd>{{ $p->reviewer?->name ?? 'Unassigned' }}</dd></div><div class="flex justify-between"><dt class="text-ink-500">Last updated</dt><dd>{{ $p->updated_at->diffForHumans() }}</dd></div></dl>
        </x-ui.card>
        <x-ui.card title="Review actions">
            <div class="flex flex-col gap-2">
                @if($s === S::Review && $u->can('projects.approve'))<x-ui.button wire:click="ask('approve')">Approve</x-ui.button>@endif
                @if($s === S::Review && $u->can('projects.review'))<x-ui.button variant="secondary" wire:click="ask('revision')">Request revision</x-ui.button>@endif
                @if(in_array($s, [S::Review, S::Approved]) && $u->can('projects.reject'))<x-ui.button variant="danger" wire:click="ask('reject')">Reject</x-ui.button>@endif
                @if($s === S::Approved && $u->can('projects.approve'))<x-ui.button variant="gold" wire:click="ask('publish')">Publish for funding</x-ui.button>@endif
                @if($s === S::Funding && $u->can('projects.approve'))<x-ui.button variant="secondary" wire:click="ask('pause')">Pause funding</x-ui.button>@endif
                @if($s === S::Paused && $u->can('projects.approve'))<x-ui.button variant="secondary" wire:click="ask('resume')">Resume funding</x-ui.button>@endif
                @if(! in_array($s, [S::Completed, S::Cancelled, S::Rejected, S::Active, S::Defaulted]) && $u->can('projects.approve'))<x-ui.button variant="ghost" wire:click="ask('cancel')">Cancel project</x-ui.button>@endif
            </div>
        </x-ui.card>
        <x-wakalah.summary :project="$p" />
        @can('projects.edit')
            @if(in_array($p->status, [S::Draft, S::NeedsRevision, S::Review, S::Approved], true))
            <x-ui.card title="Appointment of Wakil">
                <div class="space-y-3">
                    <x-ui.field label="Select Wakil" model="wakilId" type="select"><option value="">No Wakil</option>@foreach($wakils as $w)<option value="{{ $w->user_id }}">{{ $w->display_name }} — Wakil</option>@endforeach</x-ui.field>
                    @if(\App\Enums\WakalahRole::forContract($p->contract_type))
                        <fieldset><legend class="label">Wakalah Role</legend>
                            @foreach(\App\Enums\WakalahRole::optionsFor($p->contract_type) as $rv => $rl)<label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="wakalahRoles" value="{{ $rv }}"> {{ $rl }}</label>@endforeach
                        </fieldset>
                    @endif
                    <x-ui.field label="Reason (recorded in the audit log)" model="wakalahReason" />
                    <x-ui.button wire:click="saveWakil" loading="saveWakil" loading-text="Saving...">Save appointment</x-ui.button>
                    <p class="text-xs text-ink-500">Changing the Wakil needs the Shariah review to be recorded again before publishing.</p>
                </div>
            </x-ui.card>
            @endif
        @endcan
    </aside>

    <x-ui.modal name="review-action" title="Confirm action">
        @if($error)<x-ui.alert type="error" class="mb-3">{{ $error }}</x-ui.alert>@endif
        <p>This is recorded in the audit history.</p>
        <label for="reason" class="label mt-3">Reason @unless(in_array($pending, ['revision','reject','pause','cancel'])) (optional)@endunless</label>
        <textarea id="reason" wire:model="reason" rows="3" class="input"></textarea>
        @error('reason')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'review-action')">Cancel</x-ui.button>
            <x-ui.button wire:click="confirm" loading="confirm" loading-text="Processing...">Confirm</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
