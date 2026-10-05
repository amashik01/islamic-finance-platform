@props(['label' => null, 'name', 'help' => null, 'type' => 'text'])
@php $id = $attributes->get('id', $name); @endphp
<div>
    @if($label)<label for="{{ $id }}" class="label">{{ $label }}</label>@endif
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
        aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}"
        @if($help) aria-describedby="{{ $id }}-help" @endif
        {{ $attributes->merge(['class' => 'input'.($errors->has($name) ? ' border-red-400' : '')]) }}>
    @if($help)<p id="{{ $id }}-help" class="help">{{ $help }}</p>@endif
    @error($name)<p class="field-error" role="alert">{{ $message }}</p>@enderror
</div>
