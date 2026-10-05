<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-brand-700">Aqd — Mudarabah</p>
        <h1 class="font-display text-2xl font-semibold text-ink-900">Create Mudarabah Project</h1>
        <p class="mt-1 text-sm text-ink-600">The Rabb-ul-Mal (capital provider) supplies Ras-ul-Mal (capital); you, the Mudarib, provide management. Actual profit is shared by an agreed ratio; ordinary loss falls on the capital.</p>
    </div>
    <x-aqd.progress :steps="$steps" :step="$step" />
    @if($error)<x-ui.alert type="error">{{ $error }}</x-ui.alert>@endif

    <x-ui.card :title="'Step '.$step.' — '.$current['title']">
        <p class="text-sm text-ink-600">{{ $current['intro'] }}</p>
        <div class="mt-4 space-y-4">
            @if($current['key'] === 'parties')
                <div class="grid gap-3 rounded-card border border-ink-200 p-3 text-sm sm:grid-cols-2">
                    <div><p class="font-semibold">Rabb-ul-Mal (capital provider)</p><p class="text-ink-600">The participating investors. Each signs an individual participation agreement for the amount they provide.</p></div>
                    <div><p class="font-semibold">Mudarib (entrepreneur / working partner)</p><p class="text-ink-600">{{ auth()->user()->business?->name }} — identity {{ auth()->user()->business?->kyc_status?->label() }}.</p></div>
                </div>
            @elseif($current['key'] === 'profit')
                @if(isset($preview['ratio_ok']))<p class="text-sm font-medium {{ $preview['ratio_ok'] ? 'text-brand-700' : 'text-red-700' }}">{{ $preview['ratio_ok'] ? '✓ Rabb-ul-Mal '.$preview['rabb'].' / Mudarib '.$preview['mudarib'].' of actual profit.' : '✗ Both ratios must be positive and total 100%.' }}</p>@endif
            @elseif($current['key'] === 'loss')
                <x-ui.alert type="warning" title="Ordinary loss is borne by the Rabb-ul-Mal">The Mudarib does not guarantee the capital. Liability arises only for misconduct, negligence or breach of the agreed terms. A loss is never turned into a debt automatically.</x-ui.alert>
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
