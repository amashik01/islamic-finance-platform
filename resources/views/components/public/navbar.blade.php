@php
    $links = [['Home', 'home'], ['How It Works', 'how-it-works'], ['Opportunities', 'opportunities'], ['For Businesses', 'for-businesses'], ['Islamic Finance', 'islamic-finance'], ['About', 'about'], ['FAQ', 'faq']];
@endphp
<header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-ink-100 bg-white/90 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center gap-4 px-4 sm:px-6">
        <button class="rounded-control p-2 text-ink-700 hover:bg-ink-100 lg:hidden" @click="open = ! open" :aria-expanded="open" aria-controls="mobile-menu" aria-label="Toggle menu"><x-icon name="menu" /></button>
        <a href="{{ route('home') }}" class="flex items-center gap-2 font-display text-lg font-semibold text-brand-900"><x-brand-mark class="h-7 w-7" />{{ config('app.name') }}</a>
        <nav class="ml-8 hidden items-center gap-1 lg:flex" aria-label="Primary">
            @foreach ($links as [$label, $route])
                <a href="{{ route($route) }}" @class(['rounded-control px-3 py-2 text-sm font-medium hover:bg-ink-100', 'text-brand-800' => request()->routeIs($route), 'text-ink-700' => ! request()->routeIs($route)])>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="ml-auto flex items-center gap-2">
            @auth
                <x-ui.button :href="route('dashboard')" size="sm">Go to dashboard</x-ui.button>
            @else
                <a href="{{ route('login') }}" class="btn-ghost btn-sm">Login</a>
                <a href="{{ route('register') }}" class="btn-primary btn-sm">Create Account</a>
            @endauth
        </div>
    </div>
    <nav id="mobile-menu" x-show="open" x-cloak x-transition class="border-t border-ink-100 bg-white px-4 py-3 lg:hidden" aria-label="Mobile">
        @foreach ($links as [$label, $route])<a href="{{ route($route) }}" class="block rounded-control px-3 py-2 text-sm font-medium text-ink-800 hover:bg-ink-50">{{ $label }}</a>@endforeach
    </nav>
</header>
