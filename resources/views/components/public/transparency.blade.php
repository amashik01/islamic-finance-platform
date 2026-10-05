@php
    $flow = ['Capital', 'Approved Contract', 'Real Business / Asset', 'Economic Activity', 'Profit / Sale', 'Settlement'];
    $features = [['Transparent contracts', 'Every project has a documented contract with stated terms.'], ['Documented transactions', 'Each movement of money is recorded as a balanced ledger entry.'], ['Clear financial terms', 'Principal, profit and sale profit are always shown separately.'], ['Transaction history', 'Investors and businesses can review their full statement.'], ['Audit trail', 'Sensitive actions are logged and cannot be edited.']];
@endphp
<section class="bg-ink-50 py-16">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <h2 class="font-display text-3xl font-semibold">Transparent from capital to settlement</h2>
        <ol class="mt-8 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">@foreach ($flow as $i => $f)<li class="rounded-card border border-ink-100 bg-white p-4 text-center text-sm font-medium"><span class="mb-1 block text-xs font-semibold text-gold-600">{{ $i + 1 }}</span>{{ $f }}</li>@endforeach</ol>
        <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">@foreach ($features as [$t, $d])<div><h3 class="text-sm font-semibold text-brand-900">{{ $t }}</h3><p class="mt-1 text-sm text-ink-600">{{ $d }}</p></div>@endforeach</div>
    </div>
</section>
