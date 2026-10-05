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

/** The business remits INTERIM PROCEEDS (an advance pending the final determination; not final profit). */
function remit(\App\Models\Contract $contract, int $minor): void
{
    app(\App\Services\Contract\VentureRemittanceService::class)->record($contract->fresh(), \App\Models\VentureRemittance::INTERIM_PROCEEDS, \App\Support\Money\Money::minor($minor), 'receipt-'.uniqid(), 'remit-'.uniqid(), \App\Models\User::factory()->create());
}

/** The business returns capital value. */
function returnCapital(\App\Models\Contract $contract, int $minor): void
{
    app(\App\Services\Contract\VentureRemittanceService::class)->record($contract->fresh(), \App\Models\VentureRemittance::CAPITAL_RETURN, \App\Support\Money\Money::minor($minor), 'receipt-'.uniqid(), 'ret-'.uniqid(), \App\Models\User::factory()->create());
}

/** Records the real delivery of the committed capital to the business. */
function deployCapital(\App\Models\Contract $contract): \App\Models\CapitalDeployment
{
    $svc = app(\App\Services\Contract\CapitalDeploymentService::class);
    $c = $contract->fresh();

    return $svc->deploy($c, $svc->ventureCapital($c), 'DELIVERY-'.uniqid(), 'deploy-'.$c->id, \App\Models\User::factory()->create());
}

/**
 * The recorded events that imply a final result of $netMinor: deploy (if not yet), return the capital that is left, and for a
 * profit remit it as interim proceeds. After this, SettlementService::settle($contract, $netMinor) is consistent with the books.
 */
function closeOut(\App\Models\Contract $contract, int $netMinor): void
{
    $c = $contract->fresh();
    if (! \App\Models\CapitalDeployment::where('contract_id', $c->id)->exists()) {
        deployCapital($c);
    }
    $venture = (int) \App\Models\CapitalDeployment::where('contract_id', $c->id)->value('amount');
    $back = $netMinor > 0 ? $venture : $venture + $netMinor;
    $back > 0 && returnCapital($c, $back);
    $netMinor > 0 && remit($c, $netMinor);
}

/** closeOut + settle in one call, for tests whose subject is the settlement itself. */
function settleNow(\App\Models\Contract $contract, int $netMinor, bool $fault = false, ?string $reason = null): \App\Models\Settlement
{
    closeOut($contract, $netMinor);

    return app(\App\Services\Settlement\SettlementService::class)->settle($contract->fresh(), \App\Support\Money\Money::minor($netMinor), \App\Models\User::factory()->create(), $fault, $reason);
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
    $publish = $overrides['_publish'] ?? true;   // false leaves the project APPROVED (Shariah-reviewed) but unpublished
    unset($overrides['_publish']);
    $business = makeBusiness();
    $admin = \App\Models\User::factory()->create();
    $base = ['title' => 'Real '.$type->label().' '.uniqid(), 'description' => 'Real workflow project', 'industry' => 'Trade', 'purpose' => 'Grow', 'duration_months' => 12, 'risk_level' => 'MEDIUM', 'minimum_amount' => '5000', 'key_risks' => 'Demand'];
    $terms = match ($type) {
        \App\Enums\ContractType::Mudarabah => ['contract_type' => 'MUDARABAH', 'capital_required' => '100000', 'investor_profit' => '70', 'business_profit' => '30'],
        \App\Enums\ContractType::Musharakah => ['contract_type' => 'MUSHARAKAH', 'total_capital' => '1000000', 'investor_contribution' => '700000', 'business_contribution' => '300000', 'investor_profit' => '70', 'business_profit' => '30'],
        \App\Enums\ContractType::Murabaha => ['contract_type' => 'MURABAHA', 'asset_name' => 'Cold room', 'supplier' => 'Supplier Ltd', 'quantity' => 2, 'unit_cost' => '50000', 'sale_profit' => '10000', 'installments' => 4, 'delivery_terms' => 'Delivery to premises'],
    };
    $project = app(\App\Services\Project\ProjectBuilder::class)->saveDraft($business, $overrides + $terms + $base + ['aqd_terms' => completeAqdTerms($type)]);
    $wf = app(\App\Services\Project\ProjectWorkflow::class);
    $wf->submit($project, $business->user);
    $wf->approve($project->fresh(), $admin);
    $wf->recordShariahReview($project->fresh(), $admin, \App\Enums\ShariahReviewStatus::Approved, 'Structure reviewed');
    if ($publish) {
        // A proposed Wakalah is never effective by itself: the Wakil accepts and a reviewer reviews it before publication.
        foreach ($project->fresh()->currentWakalahAppointments()->get() as $a) {
            confirmWakalah($a, $a->wakil);
        }
        $wf->publish($project->fresh(), $admin);
    }

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

/** A registered, verified, active Wakil (organisation profile + WAKIL role). Pass overrides to make an ineligible one. */
function makeWakil(string $name = 'Rahim Enterprise', array $o = []): \App\Models\User
{
    seedRoles();
    $u = \App\Models\User::factory()->create(array_filter(['email_verified_at' => array_key_exists('verified', $o) && ! $o['verified'] ? null : now()], fn ($v) => true));
    if ($o['active'] ?? true) {
        $u->forceFill(['status' => 'ACTIVE'])->save();
    } else {
        $u->forceFill(['status' => 'SUSPENDED'])->save();
    }
    if ($o['role'] ?? true) {
        $u->assignRole(\App\Enums\UserRole::Wakil->value);
    }
    $p = \App\Models\WakilProfile::create(['user_id' => $u->id, 'display_name' => $name]);
    $p->forceFill(['kyc_status' => $o['kyc'] ?? \App\Enums\KycStatus::Approved, 'status' => $o['profile_status'] ?? 'ACTIVE'])->save();

    return $u;
}

/** Valid Mudarabah wizard input; pass overrides (e.g. wakil_id) to extend it. */
/** Wakalah terms for a Murabaha project: explicit principal, scope and authority. */
function wakalahTerms(array $roles = ['PURCHASE'], array $over = []): array
{
    $acts = collect($roles)->flatMap(fn ($r) => array_keys(\App\Enums\WakalahRole::from($r)->acts()))->all();

    return $over + ['wakalah_roles' => $roles, 'muwakkil' => 'BUSINESS', 'wakalah_scope' => 'Purchase and take delivery of the cold room units described in the request, from the named supplier only.', 'wakalah_authority' => $acts];
}

function wakilFormData(array $over = []): array
{
    return $over + ['title' => 'Wakil Project '.uniqid(), 'description' => 'A real business activity for the Wakalah tests.', 'industry' => 'Trade', 'purpose' => 'Grow', 'duration_months' => 12, 'risk_level' => 'MEDIUM', 'minimum_amount' => '5000', 'key_risks' => 'Demand',
        'contract_type' => 'MUDARABAH', 'capital_required' => '100000', 'investor_profit' => '70', 'business_profit' => '30'];
}

function murabahaFormData(array $over = []): array
{
    return $over + wakilFormData(['contract_type' => 'MURABAHA']) + ['asset_name' => 'Cold room', 'supplier' => 'Supplier Ltd', 'quantity' => 2, 'unit_cost' => '50000', 'sale_profit' => '10000', 'installments' => 4, 'delivery_terms' => 'Delivery to premises'];
}

/** A complete, valid set of aqd-specific terms (every required field the form asks for) for the contract type. */
function completeAqdTerms(\App\Enums\ContractType $type, array $over = []): array
{
    $def = \App\Domain\Aqd\AqdRegistry::for($type);
    $terms = [];
    foreach ($def->fieldMap() as $f) {
        if (in_array($f['key'], $def->commonKeys(), true) || in_array($f['key'], $def->typedKeys(), true)) {
            continue;
        }
        $terms[$f['key']] = match ($f['type']) {
            'checkbox' => true, 'select' => $f['default'] ?? array_key_first($f['options']), 'date' => now()->addDays(30)->toDateString(), 'number' => 3, 'money' => '1000', 'percent' => '50',
            default => 'Sample '.strtolower($f['label']).' for the test project.',
        };
    }
    $terms['use_promise'] = false;

    return $over + $terms;
}

/** Walks one Wakalah appointment through the Wakil's acceptance and the appointment-level Shariah review. */
function confirmWakalah(\App\Models\WakalahAppointment $a, \App\Models\User $wakil): \App\Models\WakalahAppointment
{
    seedRoles();
    $reviewer = \App\Models\User::factory()->create();
    $reviewer->givePermissionTo('shariah.review');
    $svc = app(\App\Services\Wakalah\WakalahService::class);
    $svc->accept($a->fresh(), $wakil);
    $svc->review($a->fresh(), $reviewer, \App\Enums\ShariahReviewStatus::Approved, 'Scope and principal reviewed.');

    return $a->fresh();
}

/** A complete, valid wizard form for the aqd: identification + typed terms + every required aqd field. */
function aqdForm(\App\Enums\ContractType $type, array $over = []): array
{
    $id = ['title' => 'Dairy Expansion', 'description' => 'Expanding our dairy farm with a second shed and cold chain storage for growth.', 'industry' => 'Agriculture', 'purpose' => 'Build shed',
        'duration_months' => '12', 'risk_level' => 'MEDIUM', 'key_risks' => 'Milk price and disease risk', 'closing_at' => ''];
    $typed = match ($type) {
        \App\Enums\ContractType::Mudarabah => ['capital_required' => '100000', 'minimum_amount' => '5000', 'investor_profit' => '70', 'business_profit' => '30', 'business_plan' => 'Detailed business plan', 'expected_revenue' => '', 'expected_expenses' => ''],
        \App\Enums\ContractType::Musharakah => ['total_capital' => '1000000', 'investor_contribution' => '700000', 'business_contribution' => '300000', 'investor_profit' => '60', 'business_profit' => '40', 'minimum_amount' => '5000',
            'project_activity' => 'Joint dairy trade', 'financial_assumptions' => 'Conservative demand assumptions'],
        \App\Enums\ContractType::Murabaha => ['asset_name' => 'Refrigerators', 'asset_description' => 'Four commercial units', 'quantity' => '4', 'unit_cost' => '25000', 'supplier' => 'Supplier Ltd', 'sale_profit' => '10000', 'installments' => '4',
            'delivery_terms' => 'Delivered to the shop', 'payment_terms' => '4 monthly instalments', 'ownership_info' => 'Bought by the seller in its own name', 'possession_info' => 'Held in the seller warehouse before the sale'],
    };

    return $over + $id + $typed + completeAqdTerms($type) + ['wakil_id' => '', 'wakalah_roles' => [], 'muwakkil' => '', 'wakalah_scope' => '', 'wakalah_authority' => []];
}

/** Gives a fixture contract the complete aqd-specific terms a real project has (fixtures predate the aqd forms). */
function withAqdTerms(\App\Models\Contract $contract): \App\Models\Contract
{
    $contract->forceFill(['aqd_terms' => completeAqdTerms($contract->contract_type), 'aqd_form_version' => \App\Domain\Aqd\AqdRegistry::for($contract->contract_type)->version()])->save();

    return $contract->fresh();
}
