@props(['project'])
@php $apps = $project->currentWakalahAppointments()->with('wakil.wakilProfile')->get(); @endphp
<x-ui.card title="Wakalah">
    @if($project->wakil && $apps->isNotEmpty())
        <p class="text-sm"><span class="text-ink-500">Appointed Wakil:</span> <strong>{{ $project->wakil->wakilProfile?->display_name ?? $project->wakil->name }}</strong></p>
        <ul class="mt-2 space-y-2 text-sm">
            @foreach($apps as $a)
                <li>
                    <strong>{{ $a->wakalah_role?->label() ?? 'Wakalah appointment' }}</strong> · <span class="text-ink-500">{{ $a->status->label() }}</span>
                    <div class="text-xs text-ink-600">Muwakkil (principal): {{ $a->muwakkil ? \App\Enums\WakalahPrincipal::from($a->muwakkil)->label() : 'not recorded (legacy)' }}@if($a->scope) · Scope: {{ \Illuminate\Support\Str::limit($a->scope, 160) }}@endif</div>
                </li>
            @endforeach
        </ul>
        <p class="mt-3 text-xs text-ink-500">Wakalah is an agency arrangement, separate from the {{ $project->contract_type->label() }} contract. A selected Wakil is only a proposal: it becomes effective after the Wakil accepts and a Shariah reviewer reviews this Wakalah. This is software, not a religious authority.</p>
    @else
        <p class="text-sm text-ink-500">No Wakil appointed.</p>
    @endif
</x-ui.card>
