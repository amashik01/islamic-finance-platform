@php $u = auth()->user(); @endphp
@foreach($row->documents()->latest()->get() as $doc)
    <a href="{{ route('documents.show', ['document' => $doc, 'inline' => 1]) }}" target="_blank" rel="noopener" class="btn-ghost btn-sm" title="{{ $doc->title }} (v{{ $doc->version }})">{{ Str::limit($doc->title, 14) }}</a>
@endforeach
@if($row->kyc_status === \App\Enums\KycStatus::Pending)
    @can('kyc.approve')<button type="button" class="btn-primary btn-sm" wire:click="ask('approve', {{ $row->id }}, 'Approve verification')">Approve</button>@endcan
    @can('kyc.reject')<button type="button" class="btn-danger btn-sm" wire:click="ask('reject', {{ $row->id }}, 'Reject verification', true, 'danger')">Reject</button>@endcan
@endif
