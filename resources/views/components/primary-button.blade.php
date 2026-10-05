<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-primary']) }} wire:loading.attr="disabled">{{ $slot }}</button>
