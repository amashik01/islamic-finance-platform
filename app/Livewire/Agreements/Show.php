<?php

namespace App\Livewire\Agreements;

use App\Exceptions\FinancialException;
use App\Models\ContractDocument;
use App\Services\Aqd\ContractSigningService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Component;

/** Reads one agreement and, for a signatory, signs it: the exact text and hash shown here are what the signature binds. */
class Show extends Component
{
    public ContractDocument $document;

    public string $typedName = '';

    public string $password = '';

    public bool $consent = false;

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(ContractDocument $document, ContractSigningService $signing): void
    {
        $this->authorize('view', $document);
        $this->document = $document;
        $signing->recordViewed($document, auth()->user());
    }

    public function sign(ContractSigningService $signing): void
    {
        $this->reset('error', 'notice');
        $this->authorize('view', $this->document);
        try {
            $signing->sign($this->document, auth()->user(), $this->typedName, $this->password, $this->consent);
            $this->notice = 'Signed. Your signature is bound to this exact document.';
            $this->reset('typedName', 'password', 'consent');
        } catch (FinancialException|AuthorizationException $e) {
            $this->error = $e->getMessage();
        }
        $this->document->refresh();
    }

    public function render(ContractSigningService $signing)
    {
        $u = auth()->user();
        $layout = match (true) {
            $u->isInvestor() => 'investor-layout', $u->isBusiness() => 'business-layout', $u->isWakil() => 'wakil-layout', default => 'admin-layout',
        };
        $d = $this->document->load(['signatures.signer', 'project']);

        return view('livewire.agreements.show', [
            'd' => $d, 'role' => $signing->roleFor($d, $u), 'consentText' => $signing->consentText($d), 'intact' => $d->hashIntact(),
            'signatures' => $d->signatures->map(fn ($s) => ['sig' => $s, 'valid' => $signing->signatureValid($s)]),
        ])->layout('components.'.$layout, ['title' => $d->reference]);
    }
}
