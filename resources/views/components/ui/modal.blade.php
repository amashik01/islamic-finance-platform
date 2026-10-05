{{-- Alpine-driven modal. Open with: $dispatch('open-modal', 'name') --}}
@props(['name', 'title', 'maxWidth' => 'md'])
@php $w = ['sm' => 'max-w-sm', 'md' => 'max-w-md', 'lg' => 'max-w-lg', 'xl' => 'max-w-2xl'][$maxWidth]; @endphp
<div x-data="{ show: false }" x-on:open-modal.window="if ($event.detail === '{{ $name }}') show = true"
     x-on:close-modal.window="if (! $event.detail || $event.detail === '{{ $name }}') show = false"
     x-on:keydown.escape.window="show = false" x-show="show" x-cloak
     class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="{{ $name }}-title">
    <div x-show="show" x-transition.opacity class="absolute inset-0 bg-ink-900/50" x-on:click="show = false"></div>
    <div x-show="show" x-transition x-trap.noscroll="show" class="relative w-full {{ $w }} rounded-card bg-white p-6 shadow-lift">
        <h2 id="{{ $name }}-title" class="text-lg font-semibold text-ink-900">{{ $title }}</h2>
        <div class="mt-3 text-sm text-ink-600">{{ $slot }}</div>
        @isset($footer)<div class="mt-6 flex justify-end gap-2">{{ $footer }}</div>@endisset
    </div>
</div>
