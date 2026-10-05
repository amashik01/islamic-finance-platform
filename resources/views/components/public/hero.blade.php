@php $flow = ['Investor', 'Capital', 'Islamic Contract', 'Real Economic Activity', 'Profit / Sale', 'Settlement']; @endphp
<section class="pattern-geo relative overflow-hidden bg-brand-900 text-white">
    <div class="mx-auto grid max-w-7xl gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
        <div>
            <p class="inline-block rounded-full border border-gold-300/40 px-3 py-1 text-xs font-medium text-gold-200">Mudarabah · Musharakah · Murabaha</p>
            <h1 class="mt-5 font-display text-4xl font-semibold leading-tight text-white sm:text-5xl">Build Wealth Through Ethical Islamic Finance</h1>
            <p class="mt-5 max-w-xl text-lg text-brand-100/90">Connect capital with real businesses and asset-based opportunities through transparent Islamic financial structures.</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('opportunities') }}" class="btn-gold btn-lg">Explore Opportunities</a>
                <a href="{{ route('for-businesses') }}" class="btn btn-lg border border-white/30 text-white hover:bg-white/10">Raise Business Capital</a>
            </div>
            <p class="mt-6 max-w-xl text-xs text-brand-200/70">Returns are never guaranteed. Capital is exposed to business risk according to each contract.</p>
        </div>
        <ol class="relative mx-auto w-full max-w-sm space-y-3" aria-label="How capital flows">
            @foreach ($flow as $i => $step)
                <li class="relative flex items-center gap-4 rounded-card border border-white/10 bg-white/5 px-4 py-3 backdrop-blur">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gold-400 text-sm font-bold text-ink-900">{{ $i + 1 }}</span>
                    <span class="font-medium">{{ $step }}</span>
                    @unless($loop->last)<span class="absolute -bottom-3 left-8 h-3 w-px bg-gold-300/50" aria-hidden="true"></span>@endunless
                </li>
            @endforeach
        </ol>
    </div>
</section>
