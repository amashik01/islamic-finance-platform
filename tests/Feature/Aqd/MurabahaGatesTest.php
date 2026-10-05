<?php

use App\Enums\ContractDocumentKind as K;
use App\Enums\ContractDocumentStatus as S;
use App\Enums\ContractType;
use App\Exceptions\FinancialException;
use App\Models\ContractDocument;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Murabaha\MurabahaService;
use App\Support\Money\Money;

/** A Murabaha contract in possession of the asset (everything before the sale). */
function possessedMurabaha(array $terms = []): array
{
    [$contract, $m, $admin] = murabahaFixture();
    $contract->forceFill(['aqd_terms' => $terms + completeAqdTerms(ContractType::Murabaha), 'aqd_form_version' => 'MURABAHA-FORM-1'])->save();
    $svc = app(MurabahaService::class);
    $svc->verifySupplierAndAsset($m, $admin);
    $svc->recordPurchase($m->fresh(), Money::minor(10000000), 'INV-1', now(), $admin);
    $svc->recordOwnership($m->fresh(), now(), $admin);
    $svc->recordPossession($m->fresh(), now(), 'Held in the warehouse', $admin);

    return [$contract->fresh(), $m->fresh(), $admin, $svc];
}

it('no sale and no receivable without the seller risk confirmation', function () {
    [$contract, $m, $admin, $svc] = possessedMurabaha();
    expect(fn () => $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin))->toThrow(FinancialException::class, 'risk');
    expect(fn () => $svc->prepareSaleAgreement($m->fresh(), $admin))->toThrow(FinancialException::class, 'risk');
    expect(Receivable::count())->toBe(0);
});

it('the risk-bearing period is enforced and cannot be confirmed for the future', function () {
    [$contract, $m, $admin, $svc] = possessedMurabaha();   // risk_bearing_days = 3 in the fixture terms
    expect(fn () => $svc->confirmRiskBorne($m->fresh(), now(), 'Insured warehouse', $admin))->toThrow(FinancialException::class, 'at least 3 day')
        ->and(fn () => $svc->confirmRiskBorne($m->fresh(), now()->addDays(10), 'Insured warehouse', $admin))->toThrow(FinancialException::class, 'future');
    $m->purchase->forceFill(['possession_on' => now()->subDays(5)])->save();
    expect(fn () => $svc->confirmRiskBorne($m->fresh(), now(), '  ', $admin))->toThrow(FinancialException::class, 'Describe');
    $svc->confirmRiskBorne($m->fresh(), now(), 'Insured warehouse', $admin);
    expect($m->purchase->fresh()->risk_confirmed_on)->not->toBeNull();
});

it('no sale or receivable before the sale agreement is executed by both parties', function () {
    [$contract, $m, $admin, $svc] = possessedMurabaha();
    $m->purchase->forceFill(['possession_on' => now()->subDays(10)])->save();
    $svc->confirmRiskBorne($m->fresh(), now(), 'Insured warehouse', $admin);
    expect(fn () => $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin))->toThrow(FinancialException::class, 'sale agreement');

    $staff = User::factory()->create();
    $staff->assignRole(\App\Enums\UserRole::Admin->value);
    $doc = $svc->prepareSaleAgreement($m->fresh(), $staff);
    expect($doc->kind)->toBe(K::MurabahaSale)->and($doc->content)->toContain('cost')->and($doc->content)->toContain('possession')->not->toContain('{{');
    signDoc($doc, $contract->project->business->user);   // the buyer alone is not enough
    expect($doc->fresh()->status)->toBe(S::PendingSignature)->and(fn () => $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin))->toThrow(FinancialException::class, 'sale agreement');
    signDoc($doc->fresh(), $staff);
    expect($doc->fresh()->status)->toBe(S::Executed);

    $r = $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin);
    expect($r->total_amount)->toBe(11000000)->and($m->fresh()->sale->sale_document_id)->toBe($doc->id)->and(reconcile(true)['passed'])->toBeTrue();
});

it('a tampered sale agreement cannot be used to execute the sale', function () {
    [$contract, $m, $admin, $svc] = possessedMurabaha();
    readyToSell($m->fresh());
    $doc = ContractDocument::where('kind', K::MurabahaSale->value)->firstOrFail();
    \DB::statement('DROP TRIGGER IF EXISTS contract_documents_no_edit');   // an attacker with raw DB access and no trigger
    \DB::table('contract_documents')->where('id', $doc->id)->update(['content' => $doc->content.' (changed)']);
    expect(fn () => $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin))->toThrow(FinancialException::class, 'sale agreement');
});

it('a promise is recorded as a promise: mutual promises need an option, one per contract, and it creates no receivable', function () {
    [$contract, $m, $admin] = murabahaFixture();
    $svc = app(MurabahaService::class);
    expect(fn () => $svc->recordPromise($m, 'BILATERAL', 'BUSINESS', null, 'x', $admin))->toThrow(FinancialException::class, 'without an option')
        ->and(fn () => $svc->recordPromise($m, 'BILATERAL_WITH_OPTION', 'BUSINESS', null, 'x', $admin))->toThrow(FinancialException::class, 'needs an option');
    $p = $svc->recordPromise($m, 'BILATERAL_WITH_OPTION', 'BUSINESS', 'BUYER', 'Subject to inspection.', $admin);
    expect($p->promise_type)->toBe('BILATERAL_WITH_OPTION')->and(fn () => $svc->recordPromise($m->fresh(), 'UNILATERAL', 'BUSINESS', null, null, $admin))->toThrow(FinancialException::class, 'already recorded');
    expect(Receivable::count())->toBe(0)->and(\App\Models\Transaction::count())->toBe(0);
});

it('a contract that uses a promise cannot purchase or sell without recording it', function () {
    [$contract, $m, $admin] = murabahaFixture();
    $contract->forceFill(['aqd_terms' => ['use_promise' => true] + completeAqdTerms(ContractType::Murabaha), 'aqd_form_version' => 'MURABAHA-FORM-1'])->save();
    $svc = app(MurabahaService::class);
    $svc->verifySupplierAndAsset($m, $admin);
    expect(fn () => $svc->recordPurchase($m->fresh(), Money::minor(10000000), 'INV-1', now(), $admin))->toThrow(FinancialException::class, 'promise');
    $svc->recordPromise($m->fresh(), 'UNILATERAL', 'BUSINESS', null, 'Will buy on delivery.', $admin);
    $svc->recordPurchase($m->fresh(), Money::minor(10000000), 'INV-1', now(), $admin);
    expect($m->fresh()->stage->value)->toBe('PURCHASED');
});

it('a Wakil may perform only the acts its confirmed Wakalah grants', function () {
    $w = makeWakil('Agent');
    $project = realProject(ContractType::Murabaha, ['wakil_id' => (string) $w->id] + wakalahTerms(['PURCHASE']));
    $m = $project->contract->murabaha;
    $svc = app(MurabahaService::class);
    $staff = User::factory()->create();
    $staff->assignRole(\App\Enums\UserRole::Admin->value);
    $svc->verifySupplierAndAsset($m->fresh(), $staff);
    $svc->recordPurchase($m->fresh(), Money::minor($m->purchase_cost), 'INV-W', now(), $w);     // within the PURCHASE role
    expect($m->fresh()->purchase->acting_wakil_id)->toBe($w->id);
    expect(fn () => $svc->recordOwnership($m->fresh(), now(), $w))->toThrow(FinancialException::class, 'no confirmed Wakalah')           // not appointed for acquisition
        ->and(fn () => $svc->recordPossession($m->fresh(), now(), 'taken', $w))->toThrow(FinancialException::class, 'no confirmed Wakalah');   // nor for qabd
    expect($m->fresh()->stage->value)->toBe('PURCHASED');
});

it('a Wakil whose Wakalah is revoked or unconfirmed cannot act', function () {
    $w = makeWakil('Agent');
    $project = realProject(ContractType::Murabaha, ['wakil_id' => (string) $w->id, '_publish' => false] + wakalahTerms(['PURCHASE']));
    $m = $project->contract->murabaha;
    $svc = app(MurabahaService::class);
    $staff = User::factory()->create();
    $staff->assignRole(\App\Enums\UserRole::Admin->value);
    $svc->verifySupplierAndAsset($m->fresh(), $staff);
    expect(fn () => $svc->recordPurchase($m->fresh(), Money::minor($m->purchase_cost), 'INV-W', now(), $w))->toThrow(FinancialException::class, 'no confirmed Wakalah');
});
