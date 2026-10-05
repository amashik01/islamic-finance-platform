<a href="{{ route('documents.show', ['document' => $row, 'inline' => 1]) }}" target="_blank" rel="noopener" class="btn-secondary btn-sm">Open</a>
@if($row->verification_status === \App\Enums\DocumentVerificationStatus::Pending && auth()->user()->can('kyc.review'))
<button type="button" class="btn-primary btn-sm" wire:click="ask('verify', {{ $row->id }}, 'Verify document')">Verify</button>
<button type="button" class="btn-danger btn-sm" wire:click="ask('reject', {{ $row->id }}, 'Reject document', true, 'danger')">Reject</button>
@endif
