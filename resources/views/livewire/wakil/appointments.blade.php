<div class="mx-auto max-w-4xl space-y-4">
    @if($notice)<x-ui.alert type="success">{{ $notice }}</x-ui.alert>@endif
    @if($error)<x-ui.alert type="error">{{ $error }}</x-ui.alert>@endif
    <p class="text-sm text-ink-600">A Wakalah is an agency arrangement: a principal (Muwakkil) authorises you to act for them within a stated scope. You act only inside that scope and only after the appointment is confirmed.</p>
    @forelse($appointments as $a)
        <x-ui.card :title="($a->wakalah_role?->label() ?? 'Wakalah').' — '.$a->project->title" wire:key="ap-{{ $a->id }}">
            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                <div><dt class="text-ink-500">Muwakkil (principal)</dt><dd class="font-medium">{{ $a->muwakkil ? \App\Enums\WakalahPrincipal::from($a->muwakkil)->label() : 'Not recorded (legacy)' }}</dd></div>
                <div><dt class="text-ink-500">Status</dt><dd class="font-medium">{{ $a->status->label() }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-ink-500">Scope</dt><dd>{{ $a->scope ?: '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-ink-500">Authorised acts</dt><dd>{{ collect($a->authority ?? [])->map(fn ($x) => $a->wakalah_role?->acts()[$x] ?? $x)->implode('; ') ?: '—' }}</dd></div>
            </dl>
            @if($a->is_current && $a->status === \App\Enums\WakalahStatus::PendingWakilAcceptance)
                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <x-ui.button wire:click="accept({{ $a->id }})" loading="accept" loading-text="Saving...">Accept appointment</x-ui.button>
                    <div><label for="reason-{{ $a->id }}" class="label">Reason, if declining</label><input id="reason-{{ $a->id }}" wire:model="reason" class="input"></div>
                    <x-ui.button variant="secondary" wire:click="decline({{ $a->id }})" loading="decline" loading-text="Saving...">Decline</x-ui.button>
                </div>
            @endif
        </x-ui.card>
    @empty
        <x-ui.empty-state title="No appointments yet." message="When a principal appoints you as Wakil, it appears here." />
    @endforelse
</div>
