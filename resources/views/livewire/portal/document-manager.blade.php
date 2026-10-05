<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-ui.card title="Identity & verification" subtitle="Current status">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><x-status-badge :status="$owner->kyc_status" />
                    <p class="mt-2 text-sm text-ink-600">
                        @switch($owner->kyc_status->value)
                            @case('NOT_SUBMITTED') Upload the required documents, then submit for review. @break
                            @case('PENDING') Your documents are being reviewed. @break
                            @case('APPROVED') You are verified. @break
                            @case('REJECTED') Your submission was not accepted. Upload corrected documents and submit again. @break
                        @endswitch
                    </p></div>
                @if(in_array($owner->kyc_status->value, ['NOT_SUBMITTED', 'REJECTED']))<x-ui.button wire:click="submitKyc" loading="submitKyc" loading-text="Submitting...">Submit for verification</x-ui.button>@endif
            </div>
        </x-ui.card>

        <x-ui.card title="Your documents">
            @forelse($documents as $d)
                <div wire:key="d-{{ $d->id }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 py-3 text-sm last:border-0">
                    <div><p class="font-medium">{{ $d->title }} <span class="text-ink-500">v{{ $d->version }}</span></p><p class="text-xs text-ink-500">{{ $d->category->label() }} · {{ $d->created_at->format('d M Y') }} · {{ number_format($d->size / 1024) }} KB</p></div>
                    <div class="flex items-center gap-2"><x-status-badge :status="$d->verification_status" /><a href="{{ route('documents.show', $d) }}" class="btn-secondary btn-sm">Download</a></div>
                </div>
            @empty<x-ui.empty-state title="No documents yet." message="Upload your identity documents to begin verification." />@endforelse
        </x-ui.card>
    </div>

    <x-ui.card title="Upload a document" class="lg:self-start">
        @if($notice)<x-ui.alert type="success" class="mb-3">{{ $notice }}</x-ui.alert>@endif
        @if($error)<x-ui.alert type="error" class="mb-3">{{ $error }}</x-ui.alert>@endif
        <form wire:submit="upload" class="space-y-3">
            <div><label for="cat" class="label">Category</label><select id="cat" wire:model="category" class="input">@foreach($categories as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>@error('category')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
            <div><label for="title" class="label">Title</label><input id="title" wire:model="title" class="input" placeholder="e.g. National ID (front)">@error('title')<p class="field-error" role="alert">{{ $message }}</p>@enderror<p class="help">Uploading the same title again saves a new version.</p></div>
            <div><label for="file" class="label">File (PDF, JPG or PNG, max {{ config('finance.documents.max_kb') / 1024 }} MB)</label><input id="file" type="file" wire:model="file" class="input" accept=".pdf,.jpg,.jpeg,.png">@error('file')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                <div wire:loading wire:target="file" class="help">Uploading...</div></div>
            <x-ui.button type="submit" class="w-full" loading="upload" loading-text="Uploading...">Upload</x-ui.button>
        </form>
    </x-ui.card>
</div>
