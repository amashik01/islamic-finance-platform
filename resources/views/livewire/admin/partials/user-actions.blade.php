@if(auth()->user()->can('roles.manage') && $row->id !== auth()->id() && $row->hasAnyRole(['ADMIN', 'MANAGER', 'STAFF']))
    @foreach(['ADMIN' => 'Admin', 'MANAGER' => 'Manager', 'STAFF' => 'Staff'] as $r => $label)
        @unless($row->hasRole($r))<button type="button" class="btn-secondary btn-sm" wire:click="ask('role:{{ $r }}', {{ $row->id }}, 'Change role to {{ $label }}', true, '{{ $r === 'ADMIN' ? 'danger' : 'primary' }}')">Make {{ $label }}</button>@endunless
    @endforeach
@endif

@if(app()->environment('local') && auth()->user()->hasRole('ADMIN') && ! session('impersonator_id') && $row->id !== auth()->id() && ! $row->hasAnyRole(['ADMIN', 'MANAGER']))
    <form method="POST" action="{{ route('impersonate.start', $row->id) }}" class="inline">@csrf<button class="btn-secondary btn-sm" title="Development only: open a session as this user">Log in as</button></form>
@endif
