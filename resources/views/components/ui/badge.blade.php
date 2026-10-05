@props(['tone' => 'neutral'])
<span {{ $attributes->merge(['class' => 'badge-'.$tone]) }}>{{ $slot }}</span>
