<?php

namespace App\Livewire\Portal;

use App\Enums\DocumentCategory;
use App\Exceptions\FinancialException;
use App\Services\Document\DocumentService;
use App\Services\Kyc\KycService;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Documents + verification status for the signed-in investor or business. */
#[Title('Documents')]
class DocumentManager extends Component
{
    use WithFileUploads;

    public $file = null;

    public string $category = 'KYC';

    public string $title = '';

    public ?string $error = null;

    public ?string $notice = null;

    private function owner()
    {
        $u = auth()->user();

        return $u->isBusiness() ? $u->business : $u->investor;
    }

    private function categories(): array
    {
        $cats = auth()->user()->isBusiness()
            ? [DocumentCategory::Kyc, DocumentCategory::BusinessRegistration, DocumentCategory::FinancialStatement, DocumentCategory::Invoice, DocumentCategory::PurchaseOrder, DocumentCategory::SupplierDocument, DocumentCategory::DeliveryProof, DocumentCategory::PaymentProof]
            : [DocumentCategory::Kyc];

        return collect($cats)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }

    public function upload(DocumentService $documents): void
    {
        $this->reset('error', 'notice');
        $this->validate([
            'file' => ['required', 'file', 'max:'.config('finance.documents.max_kb')],
            'category' => ['required', 'in:'.implode(',', array_keys($this->categories()))],
            'title' => ['required', 'string', 'max:120'],
        ], ['file.required' => 'Choose a file to upload.', 'file.max' => 'The document is too large.']);

        try {
            $documents->upload($this->owner(), $this->file, DocumentCategory::from($this->category), $this->title, auth()->user());
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (\Throwable) {
            $this->error = 'The document could not be uploaded.';

            return;
        }
        $this->reset('file', 'title');
        $this->notice = 'Document uploaded.';
    }

    public function submitKyc(KycService $kyc): void
    {
        $this->reset('error', 'notice');
        try {
            $kyc->submit($this->owner());
            $this->notice = 'Submitted for verification. We will notify you when it has been reviewed.';
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $portal = auth()->user()->isBusiness() ? 'business' : 'investor';
        $owner = $this->owner();

        return view('livewire.portal.document-manager', [
            'owner' => $owner,
            'documents' => $owner->documents()->latest('id')->get(),
            'categories' => $this->categories(),
        ])->layout("components.$portal-layout", ['title' => 'Documents']);
    }
}
