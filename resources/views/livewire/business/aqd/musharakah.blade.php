<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-brand-700">Aqd — Musharakah</p>
        <h1 class="font-display text-2xl font-semibold text-ink-900">Create Musharakah Project</h1>
        <p class="mt-1 text-sm text-ink-600">You and the participating investors are Musharik (partners): each contributes capital, profit is shared by an agreed ratio of actual profit, and loss follows each partner's capital contribution.</p>
    </div>
    <x-aqd.progress :steps="$steps" :step="$step" />
    @if($error)<x-ui.alert type="error">{{ $error }}</x-ui.alert>@endif

    <x-ui.card :title="'Step '.$step.' — '.$current['title']">
        <p class="text-sm text-ink-600">{{ $current['intro'] }}</p>
        <div class="mt-4 space-y-4">
            @if($current['key'] === 'partners')
                <div class="grid gap-3 rounded-card border border-ink-200 p-3 text-sm sm:grid-cols-2">
                    <div><p class="font-semibold">Musharik — investor side</p><p class="text-ink-600">The participating investors, collectively; each signs an individual participation agreement.</p></div>
                    <div><p class="font-semibold">Musharik — business partner</p><p class="text-ink-600">{{ auth()->user()->business?->name }} — identity {{ auth()->user()->business?->kyc_status?->label() }}.</p></div>
                </div>
            @elseif($current['key'] === 'capital')
                @if(isset($preview['sum_ok']))<p class="text-sm font-medium {{ $preview['sum_ok'] ? 'text-brand-700' : 'text-red-700' }}">{{ $preview['sum_ok'] ? '✓ Contributions add up to the total capital.' : '✗ The two contributions must add up to the total capital.' }}</p>@endif
                @if(! empty($preview['capital_ratio']))<p class="text-sm text-ink-700">Capital ratio (investors / business): <strong>{{ $preview['capital_ratio'] }}</strong></p>@endif
            @elseif($current['key'] === 'loss')
                <div class="rounded-card border border-ink-200 p-3 text-sm">
                    <p class="font-semibold">Loss-sharing ratio (system enforced)</p>
                    <p class="text-ink-700">Investors / business: <strong>{{ $preview['capital_ratio'] ?? 'equal to the capital ratio' }}</strong> — always the capital contribution ratio. It cannot be changed, and no partner can guarantee another partner's capital.</p>
                </div>
            @endif

            @foreach($current['fields'] as $f)
                @if($def->applies($f, $form))<x-aqd.field :spec="$f" />@endif
            @endforeach

            @if($current['key'] === 'review')<x-aqd.documents :documents="$documents" />@endif
            @if($current['key'] === 'preview')
                <x-aqd.summary :steps="$steps" :form="$form" :def="$def" />
                <p class="text-xs text-ink-500">{{ config('finance.shariah_disclaimer') }} Not legal advice. Not a fatwa. Not Shariah certification.</p>
            @endif
        </div>
        <x-aqd.buttons :step="$step" :count="count($steps)" />
    </x-ui.card>
</div>
