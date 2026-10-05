@php
    $cols = [
        'Platform' => [['About', route('about')], ['How It Works', route('how-it-works')], ['Contact', route('contact')], ['FAQ', route('faq')]],
        'Investors' => [['Opportunities', route('opportunities')], ['How to Invest', route('how-it-works')], ['Investment Guide', route('islamic-finance')]],
        'Businesses' => [['Raise Capital', route('for-businesses')], ['Business Guide', route('for-businesses')], ['Submit Project', route('business.projects.create')]],
        'Islamic Finance' => [['Mudarabah', route('islamic-finance').'#mudarabah'], ['Musharakah', route('islamic-finance').'#musharakah'], ['Murabaha', route('islamic-finance').'#murabaha']],
        'Legal' => [['Terms', route('legal', 'terms')], ['Privacy', route('legal', 'privacy')], ['Risk Disclosure', route('legal', 'risk-disclosure')], ['Shariah Disclaimer', route('legal', 'shariah-disclaimer')], ['Compliance', route('legal', 'compliance')]],
    ];
@endphp
<footer class="pattern-geo bg-brand-950 text-brand-100">
    <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-6">
            <div class="lg:col-span-1">
                <a href="{{ route('home') }}" class="flex items-center gap-2 font-display text-lg font-semibold text-white"><x-brand-mark class="h-7 w-7" />{{ config('app.name') }}</a>
                <p class="mt-3 text-sm text-brand-200/80">Ethical finance connected to real economic activity.</p>
            </div>
            @foreach ($cols as $heading => $links)
                <div>
                    <h3 class="text-sm font-semibold text-white">{{ $heading }}</h3>
                    <ul class="mt-3 space-y-2 text-sm">@foreach ($links as [$l, $u])<li><a href="{{ $u }}" class="text-brand-200/80 hover:text-white">{{ $l }}</a></li>@endforeach</ul>
                </div>
            @endforeach
        </div>
        <div class="mt-10 border-t border-white/10 pt-6 text-xs leading-relaxed text-brand-200/70">
            <p>{{ config('finance.shariah_disclaimer') }}</p>
            <p class="mt-2">Investing involves risk, including possible loss of capital. No return is guaranteed. © {{ date('Y') }} {{ config('app.name') }}.</p>
        </div>
    </div>
</footer>
