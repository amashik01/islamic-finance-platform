@php
    $faqs = [
        ['Is my return guaranteed?', 'No. Mudarabah and Musharakah returns depend on actual business results and capital can be lost. Murabaha is a sale with a disclosed price; the business may still fail to pay.'],
        ['Is Murabaha profit interest?', 'No. It is a disclosed sale profit on an asset the seller owns and possesses, fixed at the time of sale.'],
        ['Does the platform certify Shariah compliance?', 'No. Shariah review status shows the outcome of review by qualified scholars; the software is not a religious authority.'],
        ['Where is my money held?', 'Your balances are tracked in an immutable ledger split into available, invested and pending amounts.'],
    ];
@endphp
<section class="bg-white py-16">
    <div class="mx-auto max-w-3xl px-4 sm:px-6">
        <h2 class="font-display text-3xl font-semibold">Frequently asked questions</h2>
        <div class="mt-6 divide-y divide-ink-100 rounded-card border border-ink-100">
            @foreach ($faqs as [$q, $a])
                <details class="group p-4"><summary class="flex cursor-pointer list-none items-center justify-between font-medium text-ink-900">{{ $q }}<x-icon name="chevron" class="h-4 w-4 transition group-open:rotate-180" /></summary><p class="mt-2 text-sm text-ink-600">{{ $a }}</p></details>
            @endforeach
        </div>
    </div>
</section>
