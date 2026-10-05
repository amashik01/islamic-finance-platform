@props(['label' => null, 'name', 'options' => []])
@php $id = $attributes->get('id', $name); @endphp
<div>
    @if($label)<label for="{{ $id }}" class="label">{{ $label }}</label>@endif
    <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->merge(['class' => 'input']) }}>
        {{ $slot }}
        @foreach($options as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
    </select>
    @error($name)<p class="field-error" role="alert">{{ $message }}</p>@enderror
</div>
