@props(['variant' => 'primary', 'size' => null, 'loading' => null, 'href' => null, 'type' => 'button'])
@php $cls = 'btn-'.$variant.($size ? ' btn-'.$size : ''); @endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $cls]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $cls]) }}
        @if($loading) wire:loading.attr="disabled" wire:target="{{ $loading }}" @endif>
        @if($loading)
            <svg wire:loading wire:target="{{ $loading }}" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".25" stroke-width="4"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>
            <span wire:loading.remove wire:target="{{ $loading }}">{{ $slot }}</span>
            <span wire:loading wire:target="{{ $loading }}">{{ $attributes->get('loading-text', 'Processing...') }}</span>
        @else
            {{ $slot }}
        @endif
    </button>
@endif
