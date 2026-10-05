@php use App\Enums\WithdrawalStatus as W; $s = $row->status; $u = auth()->user(); @endphp
@if($s === W::Pending && $u->can('withdrawals.approve'))<button type="button" class="btn-secondary btn-sm" wire:click="ask('review', {{ $row->id }}, 'Start review')">Start review</button>@endif
@if($s === W::UnderReview && $u->can('withdrawals.approve'))<button type="button" class="btn-primary btn-sm" wire:click="ask('approve', {{ $row->id }}, 'Approve withdrawal')">Approve</button>@endif
@if($s === W::Approved && $u->can('withdrawals.approve'))<button type="button" class="btn-primary btn-sm" wire:click="ask('process', {{ $row->id }}, 'Mark as processing')">Processing</button>@endif
@if($s === W::Processing && $u->can('withdrawals.approve'))<button type="button" class="btn-gold btn-sm" wire:click="ask('paid', {{ $row->id }}, 'Mark as paid')">Mark paid</button>@endif
@if(in_array($s, [W::Pending, W::UnderReview, W::Approved]) && $u->can('withdrawals.reject'))<button type="button" class="btn-danger btn-sm" wire:click="ask('reject', {{ $row->id }}, 'Reject withdrawal', true, 'danger')">Reject</button>@endif
