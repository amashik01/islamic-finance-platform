<div class="space-y-4">
    <div class="flex items-center gap-3"><h2 class="mr-auto text-lg font-semibold">Transactions</h2>
        <label for="type" class="sr-only">Type</label>
        <select id="type" wire:model.live="type" class="input w-52"><option value="">All types</option>@foreach(\App\Enums\TransactionType::options() as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
    <div wire:loading.delay class="text-sm text-ink-500">Loading...</div>
    @include('livewire.investor.partials.transactions', ['transactions' => $transactions])
</div>
