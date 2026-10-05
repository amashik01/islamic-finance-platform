{{-- Livewire-bound form field with label, help and accessible error. Usage: <x-ui.field label="Title" model="form.title" /> --}}
@props(['label', 'model', 'type' => 'text', 'help' => null, 'rows' => null, 'prefix' => null, 'live' => false])
@php $id = str_replace('.', '-', $model); $wire = 'wire:model'.($live ? '.live.debounce.400ms' : ''); @endphp
<div>
    <label for="{{ $id }}" class="label">{{ $label }}</label>
    @if($rows)
        <textarea id="{{ $id }}" {{ $wire }}="{{ $model }}" rows="{{ $rows }}" aria-invalid="{{ $errors->has($model) ? 'true' : 'false' }}" @if($help) aria-describedby="{{ $id }}-help" @endif {{ $attributes->merge(['class' => 'input']) }}></textarea>
    @elseif($type === 'select')
        <select id="{{ $id }}" {{ $wire }}="{{ $model }}" {{ $attributes->merge(['class' => 'input']) }}>{{ $slot }}</select>
    @else
        <div class="relative">
            @if($prefix)<span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-ink-500">{{ $prefix }}</span>@endif
            <input id="{{ $id }}" type="{{ $type }}" {{ $wire }}="{{ $model }}" aria-invalid="{{ $errors->has($model) ? 'true' : 'false' }}" @if($help) aria-describedby="{{ $id }}-help" @endif {{ $attributes->merge(['class' => 'input'.($prefix ? ' pl-12' : '')]) }}>
        </div>
    @endif
    @if($help)<p id="{{ $id }}-help" class="help">{{ $help }}</p>@endif
    @error($model)<p class="field-error" role="alert">{{ $message }}</p>@enderror
</div>
