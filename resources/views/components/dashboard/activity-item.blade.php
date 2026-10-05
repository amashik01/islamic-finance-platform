@props(['title', 'time' => null, 'done' => true])
<li class="relative pb-6 pl-8 last:pb-0">
    <span class="absolute left-0 top-1 flex h-5 w-5 items-center justify-center rounded-full {{ $done ? 'bg-brand-600 text-white' : 'border-2 border-ink-200 bg-white' }}" aria-hidden="true">@if($done)<svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path d="M7.7 13.3 4.4 10l-1.4 1.4 4.7 4.7L17 6.8l-1.4-1.4z"/></svg>@endif</span>
    <p class="text-sm font-medium text-ink-900">{{ $title }} <span class="sr-only">{{ $done ? '(completed)' : '(pending)' }}</span></p>
    @if($time)<p class="text-xs text-ink-500">{{ $time }}</p>@endif
    {{ $slot }}
</li>
