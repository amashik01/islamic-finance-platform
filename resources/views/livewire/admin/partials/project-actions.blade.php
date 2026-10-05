@php use App\Enums\ProjectStatus as S; $s = $row->status; $u = auth()->user(); @endphp
<a href="{{ route('admin.projects.show', $row) }}" class="btn-secondary btn-sm">View</a>
@if($s === S::Review && $u->can('projects.approve'))
    <button type="button" class="btn-primary btn-sm" wire:click="ask('approve', {{ $row->id }}, 'Approve project')">Approve</button>
@endif
@if($s === S::Review && $u->can('projects.review'))
    <button type="button" class="btn-secondary btn-sm" wire:click="ask('revision', {{ $row->id }}, 'Request revision', true)">Revision</button>
@endif
@if(in_array($s, [S::Review, S::Approved]) && $u->can('projects.reject'))
    <button type="button" class="btn-danger btn-sm" wire:click="ask('reject', {{ $row->id }}, 'Reject project', true, 'danger')">Reject</button>
@endif
@if($s === S::Approved && $u->can('projects.approve'))
    <button type="button" class="btn-gold btn-sm" wire:click="ask('publish', {{ $row->id }}, 'Publish for funding')">Publish</button>
@endif
@if($s === S::Funding && $u->can('projects.approve'))
    <button type="button" class="btn-secondary btn-sm" wire:click="ask('pause', {{ $row->id }}, 'Pause funding', true)">Pause</button>
@endif
@if($s === S::Paused && $u->can('projects.approve'))
    <button type="button" class="btn-secondary btn-sm" wire:click="ask('resume', {{ $row->id }}, 'Resume funding')">Resume</button>
@endif
