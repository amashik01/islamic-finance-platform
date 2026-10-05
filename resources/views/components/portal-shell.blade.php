@props(['portal', 'title' => null, 'portalLabel'])
@php
    $user = auth()->user();
    $sections = config("navigation.$portal");
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ $portalLabel }} · {{ config('app.name') }}</title>
    @include('partials.head-assets')
</head>
<body class="min-h-screen" x-data="{ drawer: false }">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-3">Skip to content</a>

{{-- Mobile drawer backdrop --}}
<div x-show="drawer" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-ink-900/50 lg:hidden" @click="drawer = false"></div>

<aside :class="drawer ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="pattern-geo fixed inset-y-0 left-0 z-40 flex w-sidebar flex-col bg-brand-900 transition-transform duration-200" aria-label="{{ $portalLabel }} navigation">
    <div class="flex h-16 items-center justify-between px-5">
        <a href="{{ route('home') }}" class="flex items-center gap-2 font-display text-lg font-semibold text-white"><x-brand-mark class="h-7 w-7" />{{ config('app.name') }}</a>
        <button class="rounded p-1 text-brand-200 lg:hidden" @click="drawer = false" aria-label="Close menu"><x-icon name="x" /></button>
    </div>
    <p class="px-5 pb-1 text-xs font-medium text-gold-300">{{ $portalLabel }}</p>
    <nav class="flex-1 overflow-y-auto px-3 pb-6">
        @foreach ($sections as $heading => $items)
            @php $visible = collect($items)->filter(fn ($i) => Route::has($i[1]) && (! isset($i[3]) || $user?->can($i[3]))); @endphp
            @continue($visible->isEmpty())
            @if($heading)<p class="nav-heading">{{ $heading }}</p>@endif
            @foreach ($visible as $item)
                @php
                    // Active on its own route, or a child route unless a sibling nav item owns that child.
                    $siblingOwnsIt = $visible->contains(fn ($o) => $o[1] !== $item[1] && str_starts_with($o[1], $item[1].'.') && request()->routeIs($o[1]));
                    $active = request()->routeIs($item[1]) || (request()->routeIs($item[1].'.*') && ! $siblingOwnsIt);
                @endphp
                <a href="{{ route($item[1]) }}" @class(['nav-item', 'nav-item-active' => $active]) @if($active) aria-current="page" @endif>
                    <x-icon :name="$item[2]" class="h-5 w-5 shrink-0 opacity-80" />{{ $item[0] }}
                </a>
            @endforeach
        @endforeach
    </nav>
    <p class="border-t border-white/10 px-5 py-3 text-[11px] leading-snug text-brand-200/70">Compliance depends on structure, assets, documentation and qualified scholarly review.</p>
</aside>

<div class="lg:pl-sidebar">
    <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-ink-100 bg-white/90 px-4 backdrop-blur sm:px-6">
        <button class="rounded-control p-2 text-ink-700 hover:bg-ink-100 lg:hidden" @click="drawer = true" aria-label="Open menu"><x-icon name="menu" /></button>
        @isset($topbarLeft){{ $topbarLeft }}@else<h1 class="truncate text-base font-semibold text-ink-900">{{ $title }}</h1>@endisset
        <div class="ml-auto flex items-center gap-2">
            @if($portal === 'admin')<livewire:admin.global-search />@endif
            <a href="{{ route($portal.'.notifications') ?? '#' }}" class="relative rounded-control p-2 text-ink-600 hover:bg-ink-100" aria-label="Notifications">
                <x-icon name="bell" />
                @if(($n = $user?->unreadNotifications()->count()) > 0)<span class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-gold-500 px-1 text-[10px] font-bold text-white">{{ $n }}</span><span class="sr-only">{{ $n }} unread</span>@endif
            </a>
            <div class="relative" x-data="{ open: false }" @keydown.escape="open = false">
                <button class="flex items-center gap-2 rounded-control px-2 py-1.5 hover:bg-ink-100" @click="open = ! open" :aria-expanded="open" aria-haspopup="true">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-800">{{ mb_substr($user->name, 0, 1) }}</span>
                    <span class="hidden text-sm font-medium text-ink-800 sm:block">{{ $user->name }}</span>
                    <x-icon name="chevron" class="h-4 w-4 text-ink-500" />
                </button>
                <div x-show="open" x-cloak @click.outside="open = false" x-transition class="absolute right-0 mt-2 w-56 rounded-card border border-ink-100 bg-white p-1 shadow-lift">
                    <p class="truncate px-3 py-2 text-xs text-ink-500">{{ $user->email }}</p>
                    @if(Route::has($portal.'.profile'))<a href="{{ route($portal.'.profile') }}" class="block rounded-control px-3 py-2 text-sm hover:bg-ink-50">Profile</a>@endif
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="block w-full rounded-control px-3 py-2 text-left text-sm hover:bg-ink-50">Log out</button></form>
                </div>
            </div>
        </div>
    </header>

    @if(session('impersonator_id') && app()->environment('local'))
        <div class="bg-gold-300 px-4 py-2 text-center text-sm font-medium text-ink-900" role="status">
            Development: you are viewing the platform as <strong>{{ $user->name }}</strong> ({{ $user->email }}).
            <form method="POST" action="{{ route('impersonate.stop') }}" class="ml-2 inline">@csrf<button class="underline">Return to my admin account</button></form>
        </div>
    @endif
    <main id="main" class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:py-8">
        @if (session('status'))<x-ui.alert type="success" class="mb-4">{{ session('status') }}</x-ui.alert>@endif
        {{ $slot }}
        <p class="mt-10 text-xs text-ink-500">{{ config('finance.shariah_disclaimer') }}</p>
    </main>
</div>
@livewireScripts
</body>
</html>
