@php $targetId = $row->user_id ?? null; @endphp
@if(app()->environment('local') && auth()->user()->hasRole('ADMIN') && ! session('impersonator_id') && $targetId && $targetId !== auth()->id())
    <form method="POST" action="{{ route('impersonate.start', $targetId) }}" class="inline">@csrf<button class="btn-secondary btn-sm" title="Development only: open a session as this user">Log in as</button></form>
@endif
