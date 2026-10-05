@props(['step', 'count'])
<div class="mt-6 flex items-center justify-between border-t border-ink-100 pt-4">
    <div>@if($step > 1)<x-ui.button variant="secondary" wire:click="back">Back</x-ui.button>@endif</div>
    @if($step < $count)<x-ui.button wire:click="next" loading="next" loading-text="Saving...">Continue</x-ui.button>
    @else<x-ui.button wire:click="submit" loading="submit" loading-text="Submitting...">Submit for Shariah review</x-ui.button>@endif
</div>
