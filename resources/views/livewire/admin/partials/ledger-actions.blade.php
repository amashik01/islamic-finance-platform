@if($row->status === \App\Enums\TransactionStatus::Posted && $row->type !== \App\Enums\TransactionType::Reversal && auth()->user()->can('ledger.adjust'))
<button type="button" class="btn-danger btn-sm" wire:click="ask('reverse', {{ $row->id }}, 'Reverse transaction {{ $row->reference }}', true, 'danger')">Reverse</button>
@endif
