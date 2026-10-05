<?php

use App\Enums\ContractType;
use App\Enums\KycStatus;
use App\Enums\ProjectStatus;
use App\Enums\RiskLevel;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Investor;
use App\Models\Project;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

function seedRoles(): void
{
    test()->seed(RolesAndPermissionsSeeder::class);
}

function makeInvestor(int $availableMinor = 0, bool $verified = true): Investor
{
    seedRoles();
    $user = User::factory()->create();
    $user->assignRole(UserRole::Investor->value);
    $investor = new Investor(['user_id' => $user->id, 'bank_name' => 'Demo Bank', 'bank_account_number' => '1234567890']);
    $investor->forceFill([
        'user_id' => $user->id,
        'kyc_status' => $verified ? KycStatus::Approved : KycStatus::Pending,
        'bank_verified' => $verified,
    ])->save();

    $wallets = app(WalletService::class);
    $wallets->walletFor($user);
    if ($availableMinor > 0) {
        $admin = User::factory()->create();
        $dep = $wallets->requestDeposit($user, Money::minor($availableMinor), 'seed-'.uniqid());
        $wallets->verifyDeposit($dep, $admin);
    }

    return $investor->fresh();
}

function makeBusiness(): Business
{
    seedRoles();
    $user = User::factory()->create();
    $user->assignRole(UserRole::Business->value);
    $b = new Business(['user_id' => $user->id, 'name' => 'Demo Trading Co']);
    $b->forceFill(['kyc_status' => KycStatus::Approved])->save();

    return $b;
}

function makeProject(array $attrs = [], ?Business $business = null): Project
{
    $business ??= makeBusiness();
    $p = new Project($attrs + [
        'business_id' => $business->id,
        'title' => 'Demo Project',
        'slug' => 'demo-'.uniqid(),
        'description' => 'Demo',
        'contract_type' => ContractType::Mudarabah,
        'funding_target' => 100000000,
        'minimum_amount' => 500000,
        'duration_months' => 12,
        'risk_level' => RiskLevel::Medium,
    ]);
    $p->forceFill(['status' => $attrs['status'] ?? ProjectStatus::Funding, 'funded_amount' => 0])->save();

    return $p;
}

function activeContract(\App\Models\Project $project, array $terms = []): \App\Models\Contract
{
    $c = new \App\Models\Contract(['contract_number' => \App\Models\Contract::nextNumber($project->contract_type), 'contract_type' => $project->contract_type, 'project_id' => $project->id, 'currency' => 'BDT']);
    $c->forceFill(['status' => \App\Enums\ContractStatus::Approved])->save();
    match ($project->contract_type) {
        \App\Enums\ContractType::Mudarabah => $c->mudarabah()->create($terms + ['capital_required' => 10000000, 'investor_profit_bps' => 7000, 'business_profit_bps' => 3000]),
        \App\Enums\ContractType::Musharakah => $c->musharakah()->create($terms + ['total_capital' => 100000000, 'investor_contribution' => 70000000, 'business_contribution' => 30000000, 'investor_ownership_bps' => 7000, 'business_ownership_bps' => 3000, 'investor_profit_bps' => 5000, 'business_profit_bps' => 5000, 'loss_allocation_basis' => \App\Enums\LossAllocationBasis::CapitalRatio]),
        \App\Enums\ContractType::Murabaha => $c->murabaha()->create($terms + ['purchase_cost' => 10000000, 'sale_profit' => 1000000, 'sale_price' => 11000000, 'installments_count' => 4]),
    };
    // A Musharakah's business capital is a real, ledger-backed fact (recorded while the contract is still approved).
    if ($project->contract_type === \App\Enums\ContractType::Musharakah) {
        app(\App\Services\Contract\MusharakahCapitalService::class)->recordBusinessContribution($c->fresh(), \App\Support\Money\Money::minor(30000000), 'fixture-cap-'.$c->id, \App\Models\User::factory()->create());
    }
    // The real workflow never activates a contract without an approved Shariah review.
    $review = new \App\Models\ShariahReview(['project_id' => $project->id, 'contract_id' => $c->id]);
    $review->forceFill(['status' => \App\Enums\ShariahReviewStatus::Approved, 'reviewer_id' => \App\Models\User::factory()->create()->id, 'reviewed_at' => now()])->save();
    $c->fresh()->transitionTo(\App\Enums\ContractStatus::Active);   // fixture shortcut for activation; the real path is covered by ContractActivationTest

    return $c->fresh();
}

/** The business remits the actual profit into the project pool (required before a profitable settlement). */
function remit(\App\Models\Contract $contract, int $minor): void
{
    app(\App\Services\Settlement\SettlementService::class)->recordBusinessRemittance($contract->fresh(), \App\Support\Money\Money::minor($minor), 'remit-'.uniqid(), \App\Models\User::factory()->create());
}

/** Raw DB write that bypasses model guards. Returns false when a database CHECK constraint refused it (MySQL). */
function corrupt(string $table, array $where, array $values): bool
{
    try {
        \Illuminate\Support\Facades\DB::table($table)->where($where)->update($values);

        return true;
    } catch (\Illuminate\Database\QueryException) {
        return false;
    }
}

function reconcile(bool $strict = false): array
{
    $results = app(\App\Services\Finance\Reconciliation\ReconciliationService::class)->run();

    return ['passed' => \App\Services\Finance\Reconciliation\ReconciliationService::passed($results, $strict), 'results' => collect($results)->keyBy('name')];
}

/** Active Murabaha contract fixture at stage REQUESTED with an asset, plus an admin user. */
function murabahaFixture(): array
{
    $project = makeProject(['funding_target' => 10000000, 'contract_type' => \App\Enums\ContractType::Murabaha, 'status' => \App\Enums\ProjectStatus::Approved]);
    $contract = activeContract($project);
    $contract->forceFill(['status' => \App\Enums\ContractStatus::Approved])->save();
    $contract->murabaha->assets()->create(['name' => 'Refrigeration units', 'supplier_name' => 'Supplier Ltd', 'quantity' => 4, 'unit_cost' => 2500000]);

    return [$contract->fresh(), $contract->murabaha, \App\Models\User::factory()->create()];
}

/**
 * A project taken through the REAL workflow — builder -> submit -> approve -> Shariah review -> publish — never a fixture.
 * Mudarabah: BDT 100,000 target, 70/30 profit. Musharakah: investors 700,000 target + business 300,000, 70/30 profit.
 */
function realProject(\App\Enums\ContractType $type = \App\Enums\ContractType::Mudarabah, array $overrides = []): \App\Models\Project
{
    $business = makeBusiness();
    $admin = \App\Models\User::factory()->create();
    $base = ['title' => 'Real '.$type->label().' '.uniqid(), 'description' => 'Real workflow project', 'industry' => 'Trade', 'purpose' => 'Grow', 'duration_months' => 12, 'risk_level' => 'MEDIUM', 'minimum_amount' => '5000', 'key_risks' => 'Demand'];
    $terms = $type === \App\Enums\ContractType::Mudarabah
        ? ['contract_type' => 'MUDARABAH', 'capital_required' => '100000', 'investor_profit' => '70', 'business_profit' => '30']
        : ['contract_type' => 'MUSHARAKAH', 'total_capital' => '1000000', 'investor_contribution' => '700000', 'business_contribution' => '300000', 'investor_profit' => '70', 'business_profit' => '30'];
    $project = app(\App\Services\Project\ProjectBuilder::class)->saveDraft($business, $overrides + $terms + $base);
    $wf = app(\App\Services\Project\ProjectWorkflow::class);
    $wf->submit($project, $business->user);
    $wf->approve($project->fresh(), $admin);
    $wf->recordShariahReview($project->fresh(), $admin, \App\Enums\ShariahReviewStatus::Approved, 'Structure reviewed');
    $wf->publish($project->fresh(), $admin);

    return $project->fresh()->load('contract');
}

function fund(\App\Models\Investor $investor, \App\Models\Project $project, int $minor, ?string $key = null): \App\Models\Investment
{
    return app(\App\Services\Wallet\InvestmentService::class)->invest($investor, $project->fresh(), \App\Support\Money\Money::minor($minor), $key ?? 'f-'.uniqid());
}

function recordBusinessCapital(\App\Models\Contract $contract, ?int $minor = null): \App\Models\MusharakahCapitalContribution
{
    return app(\App\Services\Contract\MusharakahCapitalService::class)->recordBusinessContribution(
        $contract->fresh(), \App\Support\Money\Money::minor($minor ?? (int) $contract->musharakah->business_contribution), 'cap-'.uniqid(), \App\Models\User::factory()->create(),
    );
}

function pool(\App\Enums\LedgerAccountType $type, int $projectId): int
{
    return (int) \App\Models\LedgerAccount::where('type', $type)->where('project_id', $projectId)->value('balance');
}
