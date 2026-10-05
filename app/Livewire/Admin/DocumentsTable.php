<?php

namespace App\Livewire\Admin;

use App\Enums\DocumentCategory;
use App\Enums\DocumentVerificationStatus as V;
use App\Livewire\Tables\DataTable;
use App\Models\Document;
use App\Services\Document\DocumentService;
use Illuminate\Database\Eloquent\Builder;

class DocumentsTable extends DataTable
{
    protected function heading(): string
    {
        return 'Documents';
    }

    protected function authorizeTable(): void
    {
        abort_unless(auth()->user()->can('kyc.view') || auth()->user()->can('projects.view'), 403);
    }

    protected function query(): Builder
    {
        $q = Document::query()->with('uploader');
        // Reviewers without KYC permission never see identity documents in the list.
        if (! auth()->user()->can('kyc.view')) {
            $q->where('category', '!=', DocumentCategory::Kyc);
        }

        return $q;
    }

    protected function searchable(): array
    {
        return ['title', 'original_name', 'uploader.name'];
    }

    protected function filters(): array
    {
        return [
            'category' => ['label' => 'Category', 'options' => DocumentCategory::options(), 'apply' => fn ($q, $v) => $q->where('category', $v)],
            'status' => ['label' => 'Verification', 'options' => V::options(), 'apply' => fn ($q, $v) => $q->where('verification_status', $v)],
        ];
    }

    protected function emptyTitle(): string
    {
        return 'No documents have been uploaded yet.';
    }

    protected function columns(): array
    {
        return [
            'title' => ['label' => 'Document', 'sortable' => true, 'render' => fn ($d) => $d->title.' (v'.$d->version.')'],
            'category' => ['label' => 'Category', 'sortable' => true, 'render' => fn ($d) => $d->category->label()],
            'uploader' => ['label' => 'Uploaded by', 'render' => fn ($d) => $d->uploader?->name ?? '—'],
            'verification_status' => ['label' => 'Verification', 'sortable' => true, 'render' => fn ($d) => self::badge($d->verification_status)],
            'created_at' => ['label' => 'Uploaded', 'sortable' => true, 'render' => fn ($d) => $d->created_at->format('d M Y')],
        ];
    }

    protected function actionsView(): string
    {
        return 'livewire.admin.partials.document-actions';
    }

    protected function perform(string $action, int $id, ?string $reason): void
    {
        $doc = Document::findOrFail($id);
        $this->authorize('verify', $doc);
        app(DocumentService::class)->verify($doc, auth()->user(), $action === 'verify', $reason);
    }
}
