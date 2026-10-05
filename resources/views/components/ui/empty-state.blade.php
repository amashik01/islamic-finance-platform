@props(['title', 'message' => null, 'action' => null, 'actionLabel' => null])
<div class="flex flex-col items-center rounded-card border border-dashed border-ink-200 bg-white px-6 py-12 text-center">
    <svg class="h-10 w-10 text-brand-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 2l2.6 6.4L21 11l-6.4 2.6L12 20l-2.6-6.4L3 11l6.4-2.6z"/></svg>
    <h3 class="mt-3 text-base font-semibold text-ink-900">{{ $title }}</h3>
    @if($message)<p class="mt-1 max-w-sm text-sm text-ink-500">{{ $message }}</p>@endif
    @if($action)<x-ui.button :href="$action" class="mt-5">{{ $actionLabel }}</x-ui.button>@endif
    {{ $slot }}
</div>
