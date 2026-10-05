<form wire:submit="save" class="mx-auto max-w-3xl space-y-6">
    @if($notice)<x-ui.alert type="success">{{ $notice }}</x-ui.alert>@endif
    @foreach($groups as $group => $items)
        <x-ui.card :title="ucfirst($group)">
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach($items as $name => [$label, $type])
                    @php $key = "$group.$name"; @endphp
                    @if($type === 'bool')
                        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" wire:model="values.{{ $group }}.{{ $name }}" class="rounded border-ink-300 text-brand-700"> {{ $label }}</label>
                    @else
                        <div><label for="s-{{ $key }}" class="label">{{ $label }}</label><input id="s-{{ $key }}" wire:model="values.{{ $group }}.{{ $name }}" class="input">@error('values.'.$key)<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
                    @endif
                @endforeach
            </div>
        </x-ui.card>
    @endforeach
    <x-ui.button type="submit" loading="save" loading-text="Saving...">Save settings</x-ui.button>
</form>
