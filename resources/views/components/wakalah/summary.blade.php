@props(['project'])
@php $apps = $project->currentWakalahAppointments()->with('wakil.wakilProfile')->get(); @endphp
<x-ui.card title="Wakalah">
    @if($project->wakil && $apps->isNotEmpty())
        <p class="text-sm"><span class="text-ink-500">Appointed Wakil:</span> <strong>{{ $project->wakil->wakilProfile?->display_name ?? $project->wakil->name }}</strong></p>
        <ul class="mt-2 space-y-1 text-sm">
            @foreach($apps as $a)
                <li>{{ $a->wakalah_role?->label() ?? 'Wakalah appointment' }} · <span class="text-ink-500">{{ $a->status->label() }}</span></li>
            @endforeach
        </ul>
        <p class="mt-3 text-xs text-ink-500">Wakalah is an agency arrangement, separate from the {{ $project->contract_type->label() }} contract. Selecting a Wakil is not an approved Wakalah until the Shariah review has confirmed it.</p>
    @else
        <p class="text-sm text-ink-500">No Wakil appointed.</p>
    @endif
</x-ui.card>
