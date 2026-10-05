@php
    $inv = ['Create Account', 'Complete Verification', 'Explore Opportunities', 'Invest', 'Track Activity & Settlement'];
    $biz = ['Create Business Profile', 'Submit Project', 'Review & Approval', 'Receive Funding / Financing', 'Complete Business Activity', 'Settlement'];
@endphp
<section class="bg-ink-50 py-16">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <h2 class="font-display text-3xl font-semibold">How it works</h2>
        <p class="mt-2 max-w-2xl text-ink-600">Two paths, one transparent process: every movement of money is documented in the ledger.</p>
        <div class="mt-10 grid gap-8 lg:grid-cols-2">
            @foreach (['For Investors' => $inv, 'For Businesses' => $biz] as $heading => $steps)
                <x-ui.card :title="$heading">
                    <ol class="space-y-4">@foreach ($steps as $i => $s)<li class="flex items-center gap-4"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 font-display text-sm font-semibold text-brand-800 ring-1 ring-brand-200">{{ sprintf('%02d', $i + 1) }}</span><span class="font-medium text-ink-800">{{ $s }}</span></li>@endforeach</ol>
                </x-ui.card>
            @endforeach
        </div>
    </div>
</section>
