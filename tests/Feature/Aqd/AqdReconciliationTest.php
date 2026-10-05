<?php

use App\Enums\ContractType;
use App\Models\ContractDocument;
use Illuminate\Support\Facades\DB;

function failing(string $name): array
{
    $r = reconcile(true);

    return $r['results'][$name]->errors;
}

function mudarabahWithInvestor(): array
{
    $p = realProject(ContractType::Mudarabah);
    $inv = makeInvestor(20000000);
    $fund = fund($inv, $p, 5000000);

    return [$p, $inv, $fund];
}

it('a clean Aqd flow passes every Aqd check, including strict', function () {
    mudarabahWithInvestor();
    expect(reconcile(true)['passed'])->toBeTrue();
    foreach (['Aqd Document Integrity', 'Aqd Shariah Activation', 'Aqd Term Integrity', 'Investor Contract Linkage', 'Wakalah Integrity', 'Murabaha Sequence'] as $name) {
        expect(failing($name))->toBe([]);
    }
});

it('detects an agreement whose text no longer matches its hash, naming it by id', function () {
    [$p] = mudarabahWithInvestor();
    $part = ContractDocument::where('kind', 'PARTICIPATION')->firstOrFail();
    $pending = app(\App\Services\Aqd\ContractGenerator::class)->participation($p->fresh(), \App\Models\Investor::first(), \App\Support\Money\Money::minor(500000), \App\Models\Investor::first()->user);
    DB::table('contract_documents')->where('id', $pending->id)->update(['content' => $pending->content.' tampered']);
    expect(failing('Aqd Document Integrity'))->toContain("Agreement #{$pending->id} (PARTICIPATION, project #{$p->id}): the stored text no longer matches its recorded document hash.");
    expect(reconcile(true)['passed'])->toBeFalse();
});

it('detects an executed agreement that is missing a signature', function () {
    [$p] = mudarabahWithInvestor();
    $pending = app(\App\Services\Aqd\ContractGenerator::class)->participation($p->fresh(), \App\Models\Investor::first(), \App\Support\Money\Money::minor(500000), \App\Models\Investor::first()->user);
    DB::table('contract_documents')->where('id', $pending->id)->update(['status' => 'EXECUTED']);
    expect(collect(failing('Aqd Document Integrity'))->implode(' '))->toContain("Agreement #{$pending->id}")->toContain('signature is missing');
});

it('detects an executed agreement that is not the terms the Shariah reviewer approved', function () {
    [$p] = mudarabahWithInvestor();
    DB::table('shariah_reviews')->where('project_id', $p->id)->update(['reviewed_terms_hash' => str_repeat('0', 64)]);
    expect(collect(failing('Aqd Shariah Activation'))->implode(' '))->toContain("project #{$p->id}")->toContain('differs from the terms the Shariah reviewer approved');
});

it('detects a funding project without an executed agreement', function () {
    [$p] = mudarabahWithInvestor();
    DB::table('contract_documents')->where('contract_id', $p->contract->id)->where('kind', 'MASTER_AQD')->where('status', 'EXECUTED')->update(['status' => 'CANCELLED']);
    expect(collect(failing('Aqd Shariah Activation'))->implode(' '))->toContain('has no executed master agreement');
});

it('flags a missing platform role outside the sandbox but not inside it', function () {
    mudarabahWithInvestor();
    config(['finance.sandbox' => true]);
    expect(failing('Aqd Shariah Activation'))->toBe([]);
    config(['finance.sandbox' => false]);
    expect(collect(failing('Aqd Shariah Activation'))->implode(' '))->toContain('contractual role has not been approved');
    app(\App\Services\Settings\SettingsService::class)->save(['shariah.platform_role' => 'DIRECT_ARRANGER'], 'test');
    expect(failing('Aqd Shariah Activation'))->toBe([]);
});

it('detects terms that drifted away from the executed agreement', function () {
    [$p] = mudarabahWithInvestor();
    DB::table('mudarabah_contracts')->where('contract_id', $p->contract->id)->update(['investor_profit_bps' => 8000, 'business_profit_bps' => 2000]);
    expect(collect(failing('Aqd Term Integrity'))->implode(' '))->toContain("contract #{$p->contract->id}")->toContain('rabb_ratio');
});

it('detects an investment that is not backed by its participation agreement', function () {
    [, , $investment] = mudarabahWithInvestor();
    $doc = ContractDocument::findOrFail($investment->participation_document_id);
    DB::table('contract_documents')->where('id', $doc->id)->update(['consumed_by_investment_id' => null]);
    expect(collect(failing('Investor Contract Linkage'))->implode(' '))->toContain("Investment #{$investment->id}")->toContain('not recorded as consumed');
    DB::table('investments')->where('id', $investment->id)->update(['participation_document_id' => null]);
    expect(collect(failing('Investor Contract Linkage'))->implode(' '))->toContain("Investment #{$investment->id} has no participation agreement");
});

it('detects a Wakil appointed on a Mudarabah project', function () {
    [$p] = mudarabahWithInvestor();
    $w = makeWakil('X');
    DB::table('wakalah_appointments')->insert(['project_id' => $p->id, 'wakil_id' => $w->id, 'slot' => 'GENERAL', 'status' => 'PROPOSED', 'is_current' => 1, 'appointed_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'muwakkil' => 'BUSINESS']);
    expect(collect(failing('Wakalah Integrity'))->implode(' '))->toContain("project #{$p->id}")->toContain('defines no Wakalah role');
});

it('detects a Murabaha sale without its risk confirmation or executed sale agreement', function () {
    [$contract, $m, $admin] = murabahaFixture();
    $svc = app(\App\Services\Murabaha\MurabahaService::class);
    $svc->verifySupplierAndAsset($m, $admin);
    $svc->recordPurchase($m->fresh(), \App\Support\Money\Money::minor(10000000), 'INV', now(), $admin);
    $svc->recordOwnership($m->fresh(), now(), $admin);
    $svc->recordPossession($m->fresh(), now(), 'held', $admin);
    readyToSell($m->fresh());
    $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin);
    expect(failing('Murabaha Sequence'))->toBe([]);

    DB::table('murabaha_purchases')->where('murabaha_contract_id', $m->id)->update(['risk_confirmed_on' => null]);
    DB::table('murabaha_sales')->where('murabaha_contract_id', $m->id)->update(['sale_document_id' => null]);
    $msg = collect(failing('Murabaha Sequence'))->implode(' ');
    expect($msg)->toContain("Murabaha #{$m->id}")->toContain('without the seller\'s risk confirmation')->toContain('without an executed sale agreement');
});

it('detects a Murabaha receivable in the ledger with no sale', function () {
    [$contract] = murabahaFixture();
    $acct = app(\App\Services\Ledger\LedgerService::class)->systemAccount(\App\Enums\LedgerAccountType::MurabahaReceivable, 'BDT', $contract->project_id);
    DB::table('ledger_accounts')->where('id', $acct->id)->update(['balance' => 500]);
    expect(collect(failing('Murabaha Sequence'))->implode(' '))->toContain("project #{$contract->project_id}")->toContain('has no sale');
});

it('finance:reconcile --strict exits non-zero on a broken Shariah invariant and names the record', function () {
    [$p] = mudarabahWithInvestor();
    DB::table('shariah_reviews')->where('project_id', $p->id)->update(['reviewed_terms_hash' => str_repeat('0', 64)]);
    $this->artisan('finance:reconcile', ['--strict' => true])->expectsOutputToContain("project #{$p->id}")->assertExitCode(1);
    $this->artisan('finance:reconcile')->assertExitCode(1);
});
