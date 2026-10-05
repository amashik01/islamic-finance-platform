@props(['type' => 'info', 'title' => null])
@php
    $map = ['info' => 'border-sky-200 bg-sky-50 text-sky-900', 'success' => 'border-brand-200 bg-brand-50 text-brand-900', 'warning' => 'border-gold-200 bg-gold-50 text-gold-700', 'error' => 'border-red-200 bg-red-50 text-red-800'];
    $icon = ['info' => 'Information', 'success' => 'Success', 'warning' => 'Warning', 'error' => 'Error'][$type];
@endphp
<div role="{{ $type === 'error' ? 'alert' : 'status' }}" {{ $attributes->merge(['class' => 'rounded-control border px-4 py-3 text-sm '.$map[$type]]) }}>
    <p class="font-semibold"><span class="sr-only">{{ $icon }}: </span>{{ $title }}</p>
    <div @class(['mt-0.5' => $title])>{{ $slot }}</div>
</div>
