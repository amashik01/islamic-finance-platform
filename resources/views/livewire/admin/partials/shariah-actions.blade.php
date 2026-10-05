<a href="{{ route('admin.projects.show', $row->project) }}" class="btn-secondary btn-sm">Open project</a>
<button type="button" class="btn-primary btn-sm" wire:click="ask('approve', {{ $row->id }}, 'Record Shariah approval', true)">Approve</button>
<button type="button" class="btn-secondary btn-sm" wire:click="ask('revision', {{ $row->id }}, 'Needs revision', true)">Needs revision</button>
<button type="button" class="btn-danger btn-sm" wire:click="ask('reject', {{ $row->id }}, 'Record Shariah rejection', true, 'danger')">Reject</button>
