@props(['project'])
@php
    use App\Enums\ContractType;
    $c = $project->contract;
    $t = $c?->terms;
    $isMurabaha = $project->contract_type === ContractType::Murabaha;
@endphp
<article class="card flex flex-col overflow-hidden">
    <div class="card-pad flex-1">
        <div class="flex items-center justify-between gap-2">
            <x-ui.badge tone="info">{{ $project->contract_type->label() }}</x-ui.badge>
            <x-status-badge :status="$project->status" />
        </div>
        <h3 class="mt-3 text-lg font-semibold text-ink-900">{{ $project->title }}</h3>
        <p class="text-sm text-ink-500">{{ $project->business->name }}</p>

        <dl class="mt-4 space-y-1.5 text-sm">
            @if($isMurabaha && $t)
                <div class="flex justify-between"><dt class="text-ink-500">Asset</dt><dd class="font-medium">{{ $t->assets->first()?->name ?? 'Asset purchase' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-500">Purchase cost</dt><dd class="font-medium">{{ \App\Support\Money\Money::minor($t->purchase_cost)->format() }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-500">Sale price</dt><dd class="font-medium">{{ \App\Support\Money\Money::minor($t->sale_price)->format() }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-500">Murabaha sale profit</dt><dd class="font-medium">{{ \App\Support\Money\Money::minor($t->sale_profit)->format() }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-500">Payment terms</dt><dd class="font-medium">{{ $t->installments_count }} installments</dd></div>
            @elseif($t)
                <div class="flex justify-between"><dt class="text-ink-500">Investor profit share</dt><dd class="font-medium">{{ rtrim(rtrim(number_format($t->investor_profit_bps / 100, 2), '0'), '.') }}% of actual profit</dd></div>
                <div class="flex justify-between"><dt class="text-ink-500">Business profit share</dt><dd class="font-medium">{{ rtrim(rtrim(number_format($t->business_profit_bps / 100, 2), '0'), '.') }}%</dd></div>
            @endif
            <div class="flex justify-between"><dt class="text-ink-500">Funding target</dt><dd class="font-medium">{{ $project->fundingTarget()->format() }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-500">Minimum</dt><dd class="font-medium">{{ \App\Support\Money\Money::minor($project->minimum_amount)->format() }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-500">Duration</dt><dd class="font-medium">{{ $project->duration_months }} months</dd></div>
            <div class="flex justify-between"><dt class="text-ink-500">Risk level</dt><dd class="font-medium">{{ $project->risk_level->label() }}</dd></div>
            @if($project->closing_at)<div class="flex justify-between"><dt class="text-ink-500">Closes</dt><dd class="font-medium">{{ $project->closing_at->format('d M Y') }}</dd></div>@endif
        </dl>
        @unless($isMurabaha)
            <div class="mt-4"><x-ui.progress :value="$project->fundingPercent()" /></div>
            <p class="mt-1 text-xs text-ink-500">{{ $project->fundedAmount()->format() }} raised. Returns depend on actual results and are not guaranteed.</p>
        @endunless
    </div>
    <a href="{{ route('opportunities.show', $project) }}" class="block border-t border-ink-100 px-5 py-3 text-center text-sm font-semibold text-brand-700 hover:bg-ink-50">View details</a>
</article>
