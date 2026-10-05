<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-brand-700">Aqd — Murabaha</p>
        <h1 class="font-display text-2xl font-semibold text-ink-900">Create Murabaha Project</h1>
        <p class="mt-1 text-sm text-ink-600">A Murabaha is a sale, not a loan: the seller buys the asset, owns it and takes possession, and only then sells it to you at a disclosed cost plus a disclosed profit, payable by instalments.</p>
    </div>
    <ol class="flex flex-wrap items-center gap-1 text-xs text-ink-600" aria-label="Required order">
        @foreach(['Request', 'Promise', 'Wakalah', 'Purchase', 'Ownership', 'Qabd', 'Risk period', 'Sale', 'Receivable', 'Instalments'] as $n => $label)<li class="rounded bg-ink-100 px-2 py-0.5">{{ $label }}</li>@if(! $loop->last)<span aria-hidden="true">→</span>@endif @endforeach
    </ol>
    <x-aqd.progress :steps="$steps" :step="$step" />
    @if($error)<x-ui.alert type="error">{{ $error }}</x-ui.alert>@endif

    <x-ui.card :title="'Step '.$step.' — '.$current['title']">
        <p class="text-sm text-ink-600">{{ $current['intro'] }}</p>
        <div class="mt-4 space-y-4">
            @if($current['key'] === 'promise')
                <x-ui.alert type="info" title="A promise is not the sale">The sale is a separate contract made only after the seller owns and possesses the asset. A mutual promise is permissible only with an option for one or both parties.</x-ui.alert>
            @endif

            @foreach($current['fields'] as $f)
                @if($def->applies($f, $form))
                    @if($f['key'] === 'wakil_id')
                        <div class="space-y-3 rounded-card border border-ink-200 p-3">
                            <x-ui.field label="Appointed Wakil (optional)" model="form.wakil_id" type="select" help="Only approved, active Wakils are listed. Selecting a Wakil is a proposal; it is not an effective Wakalah until the Wakil accepts and a Shariah reviewer reviews it."><option value="">Select Wakil</option>@foreach($wakils as $w)<option value="{{ $w->user_id }}">{{ $w->display_name }} — Wakil</option>@endforeach</x-ui.field>
                            <fieldset><legend class="label">Wakalah Role</legend>
                                @foreach(\App\Enums\WakalahRole::forContract(\App\Enums\ContractType::Murabaha) as $role)
                                    <div wire:key="wr-{{ $role->value }}"><label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.wakalah_roles" value="{{ $role->value }}"> {{ $role->label() }}</label>
                                    @foreach($role->acts() as $act => $desc)<label class="ml-6 flex items-center gap-2 text-xs text-ink-600"><input type="checkbox" wire:model="form.wakalah_authority" value="{{ $act }}"> {{ $desc }}</label>@endforeach</div>
                                @endforeach
                                <p class="mt-1 text-xs text-ink-500">Each role is its own appointment. Selling or consuming the goods is never delegable.</p>
                            </fieldset>
                            <x-ui.field label="Muwakkil (principal)" model="form.muwakkil" type="select" help="Who appoints the Wakil. Never assumed; it is reviewed."><option value="">Select the principal</option>@foreach(\App\Enums\WakalahPrincipal::options() as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</x-ui.field>
                            <x-ui.field label="Scope of the Wakalah" model="form.wakalah_scope" :rows="3" help="What the Wakil may do and for which asset. Outside this scope the Wakil has no authority." />
                        </div>
                    @else
                        <x-aqd.field :spec="$f" />
                    @endif
                @endif
            @endforeach

            @if($current['key'] === 'sale' && ! empty($preview))
                <dl class="space-y-1 rounded-card bg-brand-50 p-3 text-sm">
                    <p class="flex justify-between"><span>Acquisition cost (quantity × unit cost)</span><strong>{{ $preview['cost'] }}</strong></p>
                    <p class="flex justify-between"><span>+ Disclosed Murabaha sale profit</span><strong>{{ $preview['profit'] }}</strong></p>
                    <p class="flex justify-between border-t border-brand-200 pt-1"><span>= Sale price</span><strong>{{ $preview['price'] }}</strong></p>
                    <p class="text-xs text-ink-500">A disclosed sale profit — not interest, and not a rate on money advanced.</p>
                </dl>
            @endif

            @if($current['key'] === 'review')<x-aqd.documents :documents="$documents" />@endif
            @if($current['key'] === 'preview')
                <x-aqd.summary :steps="$steps" :form="$form" :def="$def" />
                <p class="text-xs text-ink-500">The sale agreement is generated only after the asset is acquired, owned and in possession. {{ config('finance.shariah_disclaimer') }} Not legal advice. Not a fatwa. Not Shariah certification.</p>
            @endif
        </div>
        <x-aqd.buttons :step="$step" :count="count($steps)" />
    </x-ui.card>
</div>
