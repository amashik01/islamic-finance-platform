@use('App\Enums\ContractType')
@php $type = $form['contract_type']; @endphp
<div class="mx-auto max-w-3xl space-y-6">
    {{-- Progress --}}
    <nav aria-label="Progress"><ol class="flex flex-wrap gap-2">
        @foreach($steps as $n => $label)
            <li @class(['rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset', 'bg-brand-700 text-white ring-brand-700' => $n === $step, 'bg-brand-50 text-brand-800 ring-brand-200' => $n < $step, 'bg-white text-ink-500 ring-ink-200' => $n > $step])
                @if($n === $step) aria-current="step" @endif>{{ $n }}. {{ $label }}</li>
        @endforeach
    </ol></nav>

    @if($error)<x-ui.alert type="error">{{ $error }}</x-ui.alert>@endif

    <x-ui.card :title="'Step '.$step.' — '.$steps[$step]">
        <div class="space-y-4">
        @if($step === 1)
            <x-ui.field label="Project name" model="form.title" />
            <x-ui.field label="Description" model="form.description" :rows="4" help="At least 30 characters. Describe the real business activity." />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Industry" model="form.industry" />
                <x-ui.field label="Expected duration (months)" model="form.duration_months" type="number" />
            </div>
            <x-ui.field label="Purpose of funds" model="form.purpose" :rows="2" />
            <x-ui.field label="Key risks" model="form.key_risks" :rows="3" help="Be honest about what could go wrong. Investors see this." />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Risk level" model="form.risk_level" type="select">@foreach(\App\Enums\RiskLevel::options() as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</x-ui.field>
                <x-ui.field label="Funding closing date (optional)" model="form.closing_at" type="date" />
            </div>

        @elseif($step === 2)
            <fieldset><legend class="label">Choose the contract structure</legend>
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach([['MUDARABAH', 'Mudarabah', 'Investor provides capital, you provide management. Actual profit shared by agreed ratio.'], ['MUSHARAKAH', 'Musharakah', 'You and investors both contribute capital as partners.'], ['MURABAHA', 'Murabaha', 'Asset-based cost-plus sale: the asset is bought, owned, then sold to you on instalments.']] as [$v, $t, $d])
                    <label class="flex cursor-pointer flex-col gap-1 rounded-card border border-ink-200 p-4 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                        <input type="radio" wire:model.live="form.contract_type" value="{{ $v }}" class="sr-only"><span class="font-semibold">{{ $t }}</span><span class="text-xs text-ink-600">{{ $d }}</span>
                    </label>
                @endforeach
            </div>@error('form.contract_type')<p class="field-error" role="alert">{{ $message }}</p>@enderror</fieldset>
            <p class="text-xs text-ink-500">{{ config('finance.shariah_disclaimer') }}</p>

        @elseif($step === 3 && $type === 'MUDARABAH')
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Investor profit share (%)" model="form.investor_profit" live help="Share of actual profit, not a promised return." />
                <x-ui.field label="Business profit share (%)" model="form.business_profit" live />
            </div>
            @if(isset($preview['ratio_ok']))<p class="text-sm font-medium {{ $preview['ratio_ok'] ? 'text-brand-700' : 'text-red-700' }}">{{ $preview['ratio_ok'] ? '✓ Ratios total 100%.' : '✗ Investor and business ratios must total 100%.' }}</p>@endif
            <x-ui.field label="Loss terms (optional)" model="form.loss_terms" :rows="2" help="Default: capital loss falls on the investor unless caused by your negligence or breach." />
            <x-ui.field label="Business plan" model="form.business_plan" :rows="4" />

        @elseif($step === 3 && $type === 'MUSHARAKAH')
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Investor profit share (%)" model="form.investor_profit" help="Agreed in the contract; not assumed equal to capital share." />
                <x-ui.field label="Business profit share (%)" model="form.business_profit" />
            </div>
            <x-ui.alert type="info" title="Loss allocation">If the venture makes a loss, each party bears it in proportion to its capital contribution. This cannot be changed in the application form.</x-ui.alert>
            <x-ui.field label="Project activity" model="form.project_activity" :rows="4" />

        @elseif($step === 3 && $type === 'MURABAHA')
            <x-ui.field label="Delivery terms" model="form.delivery_terms" :rows="2" />
            <x-ui.field label="Payment terms" model="form.payment_terms" :rows="2" />
            <x-ui.field label="Number of installments" model="form.installments" type="number" />
            <x-ui.field label="Ownership / acquisition information" model="form.ownership_info" :rows="2" help="How the seller will acquire legal ownership of the asset before selling it to you." />
            <x-ui.field label="Possession (qabd) information" model="form.possession_info" :rows="2" help="How the seller takes possession of the asset before the sale." />

        @elseif($step === 4 && $type === 'MUDARABAH')
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Capital required" model="form.capital_required" prefix="BDT" />
                <x-ui.field label="Your own contribution (optional)" model="form.business_contribution" prefix="BDT" />
                <x-ui.field label="Expected revenue (optional)" model="form.expected_revenue" prefix="BDT" />
                <x-ui.field label="Expected expenses (optional)" model="form.expected_expenses" prefix="BDT" />
                <x-ui.field label="Minimum investment" model="form.minimum_amount" prefix="BDT" />
            </div>

        @elseif($step === 4 && $type === 'MUSHARAKAH')
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Total capital" model="form.total_capital" prefix="BDT" live />
                <x-ui.field label="Minimum investment" model="form.minimum_amount" prefix="BDT" />
                <x-ui.field label="Investor contribution" model="form.investor_contribution" prefix="BDT" live />
                <x-ui.field label="Business contribution" model="form.business_contribution" prefix="BDT" live />
            </div>
            @if(isset($preview['sum_ok']))<p class="text-sm font-medium {{ $preview['sum_ok'] ? 'text-brand-700' : 'text-red-700' }}">{{ $preview['sum_ok'] ? '✓ Contributions add up to the total capital.' : '✗ Contributions must add up to the total capital.' }}</p>@endif
            <x-ui.field label="Financial assumptions" model="form.financial_assumptions" :rows="3" />

        @elseif($step === 4 && $type === 'MURABAHA')
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Asset / product" model="form.asset_name" />
                <x-ui.field label="Supplier" model="form.supplier" />
                <x-ui.field label="Quantity" model="form.quantity" type="number" live />
                <x-ui.field label="Unit cost" model="form.unit_cost" prefix="BDT" live />
                <x-ui.field label="Proposed Murabaha sale profit" model="form.sale_profit" prefix="BDT" live help="A disclosed sale profit on the asset — not interest." />
            </div>
            @if($preview)
                <div class="rounded-card bg-ink-50 p-4 text-sm" aria-live="polite">
                    <p class="flex justify-between"><span>Purchase cost</span><strong>{{ $preview['cost'] }}</strong></p>
                    <p class="flex justify-between"><span>+ Murabaha sale profit</span><strong>{{ $preview['profit'] }}</strong></p>
                    <p class="mt-1 flex justify-between border-t border-ink-200 pt-1"><span>= Sale price</span><strong>{{ $preview['price'] }}</strong></p>
                </div>
            @endif

        @elseif($step === 5)
            <p class="text-sm text-ink-600">Upload supporting documents (business plan, quotations, financial statements). Verification is by our review team.</p>
            <div class="grid gap-3 sm:grid-cols-3">
                <div><label for="dc" class="label">Category</label><select id="dc" wire:model="docCategory" class="input">@foreach([\App\Enums\DocumentCategory::FinancialStatement, \App\Enums\DocumentCategory::Invoice, \App\Enums\DocumentCategory::PurchaseOrder, \App\Enums\DocumentCategory::SupplierDocument, \App\Enums\DocumentCategory::BusinessRegistration] as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach</select></div>
                <div><label for="dt" class="label">Title</label><input id="dt" wire:model="docTitle" class="input">@error('docTitle')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
                <div><label for="df" class="label">File</label><input id="df" type="file" wire:model="docFile" class="input" accept=".pdf,.jpg,.jpeg,.png">@error('docFile')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
            </div>
            <x-ui.button variant="secondary" wire:click="uploadDocument" loading="uploadDocument" loading-text="Uploading...">Upload document</x-ui.button>
            @foreach($documents as $d)<p class="text-sm" wire:key="doc-{{ $d->id }}">📎 {{ $d->title }} <span class="text-ink-500">· {{ $d->category->label() }} · v{{ $d->version }}</span></p>@endforeach

        @elseif($step === 6)
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-ink-500">Project</dt><dd class="font-medium">{{ $form['title'] }}</dd></div>
                <div><dt class="text-ink-500">Contract</dt><dd class="font-medium">{{ $type ? ContractType::from($type)->label() : '—' }}</dd></div>
                <div><dt class="text-ink-500">Funding target</dt><dd class="font-medium">{{ $project ? $project->fundingTarget()->format() : '—' }}</dd></div>
                <div><dt class="text-ink-500">Duration</dt><dd class="font-medium">{{ $form['duration_months'] }} months</dd></div>
                @if($type === 'MURABAHA' && $project?->contract?->murabaha)@php $m = $project->contract->murabaha; @endphp
                    <div><dt class="text-ink-500">Purchase cost</dt><dd class="font-medium">{{ \App\Support\Money\Money::minor($m->purchase_cost)->format() }}</dd></div>
                    <div><dt class="text-ink-500">Murabaha sale profit</dt><dd class="font-medium">{{ \App\Support\Money\Money::minor($m->sale_profit)->format() }}</dd></div>
                    <div><dt class="text-ink-500">Sale price</dt><dd class="font-medium">{{ \App\Support\Money\Money::minor($m->sale_price)->format() }}</dd></div>
                @endif
                <div class="sm:col-span-2"><dt class="text-ink-500">Documents</dt><dd class="font-medium">{{ $documents->count() }} uploaded</dd></div>
            </dl>
            <p class="text-xs text-ink-500">Your draft is saved. Go back to change any step.</p>

        @elseif($step === 7)
            <p class="text-sm text-ink-700">Submitting sends the project to our review team, followed by Shariah review. You cannot edit it while it is under review, unless a revision is requested.</p>
            <x-ui.alert type="warning" title="No guarantees">{{ config('finance.shariah_disclaimer') }} Approval does not guarantee funding.</x-ui.alert>
        @endif
        </div>

        <div class="mt-6 flex items-center justify-between border-t border-ink-100 pt-4">
            <div>@if($step > 1)<x-ui.button variant="secondary" wire:click="back">Back</x-ui.button>@endif</div>
            @if($step < 7)<x-ui.button wire:click="next" loading="next" loading-text="Saving...">Continue</x-ui.button>
            @else<x-ui.button wire:click="submit" loading="submit" loading-text="Submitting...">Submit project</x-ui.button>@endif
        </div>
    </x-ui.card>
</div>
