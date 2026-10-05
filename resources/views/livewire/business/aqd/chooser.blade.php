<div class="mx-auto max-w-4xl space-y-6">
    <div>
        <h1 class="font-display text-2xl font-semibold text-ink-900">Select Aqd / Contract Structure</h1>
        <p class="mt-1 text-sm text-ink-600">Each structure is a different Islamic contract with its own parties, rules and form. Choose the one that matches what you actually do.</p>
    </div>
    <div class="grid gap-4 md:grid-cols-3">
        <a href="{{ route('business.projects.create.mudarabah') }}" class="card block p-5 hover:border-brand-600"><p class="font-display text-lg font-semibold">Mudarabah</p><p class="mt-2 text-sm text-ink-600">Investors provide the capital (Rabb-ul-Mal); you manage it (Mudarib). Actual profit is shared by an agreed ratio; ordinary loss falls on the capital.</p></a>
        <a href="{{ route('business.projects.create.musharakah') }}" class="card block p-5 hover:border-brand-600"><p class="font-display text-lg font-semibold">Musharakah</p><p class="mt-2 text-sm text-ink-600">You and the investors are partners (Musharik). Each contributes capital; profit follows the agreed ratio; loss follows capital.</p></a>
        <a href="{{ route('business.projects.create.murabaha') }}" class="card block p-5 hover:border-brand-600"><p class="font-display text-lg font-semibold">Murabaha</p><p class="mt-2 text-sm text-ink-600">A sale of an asset the seller has bought, owns and possesses, at disclosed cost plus disclosed profit, paid by instalments. Not a cash loan.</p></a>
    </div>
    <p class="text-xs text-ink-500">{{ config('finance.shariah_disclaimer') }} Not legal advice. Not a fatwa. Not Shariah certification.</p>
</div>
