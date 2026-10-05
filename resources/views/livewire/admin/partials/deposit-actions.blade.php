@if($row->status === \App\Enums\DepositStatus::Pending && auth()->user()->can('deposits.verify'))
<button type="button" class="btn-primary btn-sm" wire:click="ask('verify', {{ $row->id }}, 'Verify deposit')">Verify</button>
<button type="button" class="btn-danger btn-sm" wire:click="ask('reject', {{ $row->id }}, 'Reject deposit', true, 'danger')">Reject</button>
@endif
