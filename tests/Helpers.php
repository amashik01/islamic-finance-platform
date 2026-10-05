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
    $c->forceFill(['status' => \App\Enums\ContractStatus::Active])->save();
    match ($project->contract_type) {
        \App\Enums\ContractType::Mudarabah => $c->mudarabah()->create($terms + ['capital_required' => 10000000, 'investor_profit_bps' => 7000, 'business_profit_bps' => 3000]),
        \App\Enums\ContractType::Musharakah => $c->musharakah()->create($terms + ['total_capital' => 100000000, 'investor_contribution' => 70000000, 'business_contribution' => 30000000, 'investor_ownership_bps' => 7000, 'business_ownership_bps' => 3000, 'investor_profit_bps' => 5000, 'business_profit_bps' => 5000, 'loss_allocation_basis' => \App\Enums\LossAllocationBasis::CapitalRatio]),
        \App\Enums\ContractType::Murabaha => $c->murabaha()->create($terms + ['purchase_cost' => 10000000, 'sale_profit' => 1000000, 'sale_price' => 11000000, 'installments_count' => 4]),
    };

    return $c->fresh();
}
