@props(['label', 'value', 'hint' => null, 'href' => null, 'trend' => null])
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'card card-pad block h-full transition '.($href ? 'hover:shadow-lift' : '')]) }}>
    <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">{{ $label }}</p>
    <p class="mt-2 font-display text-xl font-semibold text-ink-900 whitespace-nowrap">{{ $value }}</p>
    @if($trend)<p class="mt-1 text-xs font-medium text-ink-600">{{ $trend }}</p>@endif
    @if($hint)<p class="mt-2 text-xs leading-relaxed text-ink-500">{{ $hint }}</p>@endif
</{{ $tag }}>
