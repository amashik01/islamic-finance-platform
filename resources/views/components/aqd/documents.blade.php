@props(['documents'])
<div class="space-y-3 rounded-card border border-ink-200 p-4">
    <p class="text-sm font-medium">Supporting documents</p>
    <p class="text-xs text-ink-500">Business plan, quotations, financial statements, supplier documents. They are shown to the Shariah reviewer.</p>
    <div class="grid gap-3 sm:grid-cols-3">
        <div><label for="dc" class="label">Category</label><select id="dc" wire:model="docCategory" class="input">@foreach([\App\Enums\DocumentCategory::FinancialStatement, \App\Enums\DocumentCategory::Invoice, \App\Enums\DocumentCategory::PurchaseOrder, \App\Enums\DocumentCategory::SupplierDocument, \App\Enums\DocumentCategory::BusinessRegistration] as $c)<option value="{{ $c->value }}">{{ $c->label() }}</option>@endforeach</select></div>
        <div><label for="dt" class="label">Title</label><input id="dt" wire:model="docTitle" class="input">@error('docTitle')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
        <div><label for="df" class="label">File</label><input id="df" type="file" wire:model="docFile" class="input" accept=".pdf,.jpg,.jpeg,.png">@error('docFile')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
    </div>
    <x-ui.button variant="secondary" wire:click="uploadDocument" loading="uploadDocument" loading-text="Uploading...">Upload document</x-ui.button>
    @foreach($documents as $d)<p class="text-sm" wire:key="doc-{{ $d->id }}">📎 {{ $d->title }} <span class="text-ink-500">· {{ $d->category->label() }} · v{{ $d->version }}</span></p>@endforeach
</div>
