<?php

namespace App\Http\Controllers;

use App\Models\ContractDocument;
use App\Services\Aqd\ContractSigningService;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Streams an agreement as a PDF of the stored text and hash; authorised exactly like the on-screen view. */
class AgreementPdfController extends Controller
{
    public function __invoke(ContractDocument $document, ContractSigningService $signing)
    {
        $this->authorize('view', $document);
        $signing->recordViewed($document, auth()->user());
        abort_unless($document->hashIntact(), 409, 'The stored agreement does not match its recorded hash.');

        $sigs = $document->signatures()->get()->map(fn ($s) => e($s->signer_role->label().' — '.$s->signature_data.' — '.$s->signed_at->toDateTimeString().' — bound to hash '.$s->document_hash))->implode('<br>');
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;line-height:1.5}h1{font-size:15px}pre{white-space:pre-wrap;font-family:DejaVu Sans,sans-serif}</style></head><body>'
            .'<h1>'.e($document->kind->label()).' — '.e($document->reference).'</h1>'
            .'<p>Status: '.e($document->status->label()).' · Version '.$document->version_no.' · SHA-256: '.e($document->document_hash).'</p>'
            .'<pre>'.e($document->content).'</pre>'
            .'<h1>Signatures</h1><p>'.($sigs ?: 'Not signed yet.').'</p>'
            .'<p><em>Subject to qualified Shariah review. Not legal advice, not a fatwa, not a Shariah certification.</em></p></body></html>';

        $pdf = new Dompdf((new Options)->set('isRemoteEnabled', false));
        $pdf->loadHtml($html);
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$document->reference.'.pdf"']);
    }
}
