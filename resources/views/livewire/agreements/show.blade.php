@use('App\Enums\ContractDocumentStatus', 'S')
<div class="space-y-6">
    @if($notice)<x-ui.alert type="success">{{ $notice }}</x-ui.alert>@endif
    @if($error)<x-ui.alert type="error">{{ $error }}</x-ui.alert>@endif
    @unless($intact)<x-ui.alert type="error" title="Integrity check failed">The stored text does not match its recorded hash. Do not rely on or sign this document.</x-ui.alert>@endunless

    <x-ui.card :title="$d->kind->label().' · '.$d->reference" :subtitle="$d->project->title.' · version '.$d->version_no">
        <dl class="grid gap-3 text-sm sm:grid-cols-4">
            <div><dt class="text-ink-500">Status</dt><dd class="font-medium">{{ $d->status->label() }}</dd></div>
            <div><dt class="text-ink-500">Generated</dt><dd class="font-medium">{{ $d->generated_at?->format('d M Y H:i') }}</dd></div>
            <div><dt class="text-ink-500">Executed</dt><dd class="font-medium">{{ $d->executed_at?->format('d M Y H:i') ?: '—' }}</dd></div>
            <div><dt class="text-ink-500">Download</dt><dd><a class="text-brand-700 underline" href="{{ route('agreements.pdf', $d->reference) }}">PDF</a></dd></div>
        </dl>
        <p class="mt-3 break-all text-xs text-ink-500">Document hash (SHA-256): {{ $d->document_hash }}</p>
        <p class="mt-1 text-xs text-ink-500">Subject to qualified Shariah review. This is not legal advice, not a fatwa and not a Shariah certification.</p>
    </x-ui.card>

    <x-ui.card title="Agreement text">
        <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap text-sm" tabindex="0" aria-label="Agreement text">{{ $d->content }}</pre>
    </x-ui.card>

    <x-ui.card title="Signatures">
        @forelse($signatures as $row)
            @php $s = $row['sig']; @endphp
            <p class="border-b border-ink-100 py-2 text-sm last:border-0"><strong>{{ $s->signer_role->label() }}</strong> — {{ $s->signature_data }} · {{ $s->signed_at->format('d M Y H:i') }}
                <span class="text-xs {{ $row['valid'] ? 'text-brand-700' : 'text-red-700' }}">{{ $row['valid'] ? 'bound to this document' : 'DOES NOT MATCH this document' }}</span></p>
        @empty<p class="text-sm text-ink-500">Not signed yet.</p>@endforelse
    </x-ui.card>

    @if($role && $d->status === S::PendingSignature && $intact)
        <x-ui.card title="Sign this agreement" :subtitle="'You sign as '.$role->label().'.'">
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="consent" class="mt-1 rounded border-ink-300 text-brand-700"><span>{{ $consentText }}</span></label>
            <label for="sg-name" class="label mt-3">Type your full name ({{ auth()->user()->name }})</label>
            <input id="sg-name" type="text" wire:model="typedName" class="input" autocomplete="off">
            <label for="sg-pass" class="label mt-3">Confirm your password</label>
            <input id="sg-pass" type="password" wire:model="password" class="input" autocomplete="current-password">
            <x-ui.button class="mt-4" wire:click="sign" loading="sign" loading-text="Signing...">Sign agreement</x-ui.button>
        </x-ui.card>
    @endif
</div>
