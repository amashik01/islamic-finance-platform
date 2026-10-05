<x-public-layout title="Islamic Finance">
    <section class="mx-auto max-w-4xl px-4 py-12 sm:px-6">
        <h1 class="font-display text-3xl font-semibold">Islamic finance on this platform</h1>
        <p class="mt-3 text-ink-600">Investment contracts (Mudarabah, Musharakah) and a sale contract (Murabaha) are modelled separately because their economics and workflows differ.</p>
        @foreach ([
            ['mudarabah', 'Mudarabah', 'The investor provides capital; the business provides management. Actual net profit is shared by a pre-agreed ratio (for example 70% investor / 30% business). A fixed, guaranteed return is not Mudarabah profit. A loss of capital falls on the investor, unless the manager is negligent or breaches the contract.'],
            ['musharakah', 'Musharakah', 'Both parties contribute capital and own the venture in proportion to it. The profit ratio is agreed in the contract and need not equal the capital ratio; losses follow the contract basis, typically capital contribution.'],
            ['murabaha', 'Murabaha', 'A cost-plus sale of an asset. The seller buys the asset, owns it, takes possession, then sells it to the buyer at a disclosed cost plus a disclosed Murabaha sale profit, payable in installments. The price is fixed at sale and does not grow over time. It is not a loan and the sale profit is not interest.'],
        ] as [$id, $t, $d])
            <article id="{{ $id }}" class="mt-10 scroll-mt-24"><h2 class="text-2xl font-semibold">{{ $t }}</h2><p class="mt-2 leading-relaxed text-ink-700">{{ $d }}</p></article>
        @endforeach
        <x-ui.alert type="warning" title="Shariah disclaimer" class="mt-10">{{ config('finance.shariah_disclaimer') }}</x-ui.alert>
    </section>
</x-public-layout>
