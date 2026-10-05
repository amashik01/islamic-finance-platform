<?php

use App\Enums\ContractStatus;
use App\Enums\LedgerAccountType as A;
use App\Enums\MurabahaStage;
use App\Enums\PaymentStatus;
use App\Enums\SettlementItemType as Item;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\LedgerAccount;
use App\Models\Transaction;
use App\Services\Murabaha\MurabahaService;
use App\Support\Money\Money;

function mrbBal(A $type, int $projectId): int
{
    return (int) LedgerAccount::where('type', $type)->where('project_id', $projectId)->value('balance');
}

it('walks purchase -> ownership -> possession -> sale with the ledger following each step', function () {
    [$contract, $m, $admin] = murabahaFixture();
    $svc = app(MurabahaService::class);
    $pid = $contract->project_id;

    $svc->verifySupplierAndAsset($m, $admin);
    expect(Transaction::count())->toBe(0);   // verification moves no money

    $svc->recordPurchase($m->fresh(), Money::minor(10000000), 'INV-1', now(), $admin);
    expect(mrbBal(A::MurabahaInventory, $pid))->toBe(10000000)   // asset acquisition cost
        ->and(Transaction::where('type', TransactionType::MurabahaPurchase)->count())->toBe(1);

    $svc->recordOwnership($m->fresh(), now(), $admin);
    $svc->recordPossession($m->fresh(), now(), 'Inspected and held', $admin);
    expect(mrbBal(A::MurabahaReceivable, $pid))->toBe(0)->and(reconcile(true)['passed'])->toBeTrue();   // no receivable before the sale

    readyToSell($m->fresh());
    $r = $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin);
    // Sale price 110,000 = cost 100,000 + Murabaha sale profit 10,000; inventory cleared.
    expect($r->total_amount)->toBe(11000000)->and(mrbBal(A::MurabahaReceivable, $pid))->toBe(11000000)
        ->and(mrbBal(A::MurabahaInventory, $pid))->toBe(0)->and(mrbBal(A::MurabahaSaleProfit, $pid))->toBe(1000000)
        ->and($m->fresh()->stage)->toBe(MurabahaStage::Sold);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('enforces the lifecycle order in the accounting too', function () {
    [$contract, $m, $admin] = murabahaFixture();
    $svc = app(MurabahaService::class);
    expect(fn () => $svc->recordPurchase($m, Money::minor(10000000), 'INV', now(), $admin))->toThrow(FinancialException::class, 'in order');
    expect(Transaction::count())->toBe(0)->and(mrbBal(A::MurabahaInventory, $contract->project_id))->toBe(0);   // a refused step leaves no ledger trace
    $svc->verifySupplierAndAsset($m, $admin);
    expect(fn () => $svc->recordPurchase($m->fresh(), Money::minor(9999999), 'INV', now(), $admin))->toThrow(FinancialException::class, 'approved purchase cost');
    $svc->recordPurchase($m->fresh(), Money::minor(10000000), 'INV', now(), $admin);
    expect(fn () => $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin))->toThrow(FinancialException::class, 'in possession');
    expect(mrbBal(A::MurabahaReceivable, $contract->project_id))->toBe(0);
});

function soldMurabahaFixture(): array
{
    [$contract, $m, $admin] = murabahaFixture();
    $svc = app(MurabahaService::class);
    $svc->verifySupplierAndAsset($m, $admin);
    $svc->recordPurchase($m->fresh(), Money::minor(10000000), 'INV-1', now(), $admin);
    $svc->recordOwnership($m->fresh(), now(), $admin);
    $svc->recordPossession($m->fresh(), now(), 'held', $admin);

    readyToSell($m->fresh());
    return [$svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin), $contract, $svc];
}

it('partial payments reduce the receivable and the ledger receivable account in step', function () {
    [$r, $contract, $svc] = soldMurabahaFixture();
    $pid = $contract->project_id;
    $svc->recordPayment($r, Money::minor(2750000), 'p1', now());
    $svc->recordPayment($r->fresh(), Money::minor(1000000), 'p2', now());   // partial instalment

    $r->refresh();
    expect($r->paid_amount)->toBe(3750000)->and($r->outstanding())->toBe(7250000)->and($r->status)->toBe(PaymentStatus::Partial)
        ->and(mrbBal(A::MurabahaReceivable, $pid))->toBe(7250000);   // sale price - payments = outstanding, in both books
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('rejects overpayment in the subledger and cannot be forced through the ledger', function () {
    [$r, $contract, $svc] = soldMurabahaFixture();
    expect(fn () => $svc->recordPayment($r, Money::minor(11000001), 'over', now()))->toThrow(FinancialException::class, 'exceeds');
    $svc->recordPayment($r->fresh(), Money::minor(11000000), 'full', now());
    expect(fn () => $svc->recordPayment($r->fresh(), Money::minor(1), 'extra', now()))->toThrow(FinancialException::class);
    expect($r->fresh()->paid_amount)->toBe(11000000)->and(mrbBal(A::MurabahaReceivable, $contract->project_id))->toBe(0);
});

it('the final payment settles with cost and sale profit kept separate, and everything reconciles', function () {
    [$r, $contract, $svc] = soldMurabahaFixture();
    $svc->recordPayment($r, Money::minor(5500000), 'a', now());
    $svc->recordPayment($r->fresh(), Money::minor(5500000), 'b', now());

    $s = $contract->fresh()->settlements()->with('items')->first();
    expect($r->fresh()->status)->toBe(PaymentStatus::Paid)->and($contract->fresh()->status)->toBe(ContractStatus::Completed)
        ->and($contract->murabaha->fresh()->stage)->toBe(MurabahaStage::Settled)
        ->and($s->items->firstWhere('item_type', Item::Principal)->amount)->toBe(10000000)
        ->and($s->items->firstWhere('item_type', Item::MurabahaSaleProfit)->amount)->toBe(1000000)
        ->and($s->currency)->toBe('BDT');
    expect(mrbBal(A::MurabahaReceivable, $contract->project_id))->toBe(0);
    $r2 = reconcile(true);
    expect($r2['passed'])->toBeTrue();
    // Platform cash: paid the supplier 100,000, collected 110,000 => +10,000 (the Murabaha sale profit).
    expect((int) LedgerAccount::where('type', A::PlatformCash)->value('balance'))->toBe(1000000);
});

it('Murabaha is never labelled interest in the data model', function () {
    expect(array_map(fn ($c) => $c->label(), Item::cases()))->toContain('Murabaha sale profit')->not->toContain('Interest');
    expect(A::MurabahaSaleProfit->label())->toBe('Murabaha sale profit');
});
