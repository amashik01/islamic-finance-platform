<?php

namespace Database\Seeders;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\KycStatus;
use App\Enums\LossAllocationBasis;
use App\Enums\MurabahaStage;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RiskLevel;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Contract;
use App\Models\Investor;
use App\Models\Project;
use App\Models\User;
use App\Services\Finance\MurabahaSaleCalculator;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** DEMO DATA ONLY. All parties and projects carry is_demo = true. Password for every demo user: "password". */
class DemoDataSeeder extends Seeder
{
    public function run(WalletService $wallets, InvestmentService $investments, MurabahaSaleCalculator $murabaha, \App\Services\Murabaha\MurabahaService $workflow): void
    {
        $staff = [
            ['Demo Admin', 'admin@demo.test', UserRole::Admin],
            ['Demo Manager', 'manager@demo.test', UserRole::Manager],
            ['Demo Staff', 'staff@demo.test', UserRole::Staff],
        ];
        foreach ($staff as [$name, $email, $role]) {
            $this->user($name, $email)->assignRole($role->value);
        }
        $admin = User::firstWhere('email', 'admin@demo.test');

        $investors = [];
        foreach ([['Amina Rahman', 'investor1@demo.test'], ['Karim Hossain', 'investor2@demo.test'], ['Nusrat Jahan', 'investor3@demo.test']] as $i => [$name, $email]) {
            $u = $this->user($name, $email);
            $u->assignRole(UserRole::Investor->value);
            $inv = new Investor(['user_id' => $u->id, 'bank_name' => 'Demo Bank', 'bank_account_name' => $name, 'bank_account_number' => '10020030'.$i]);
            $inv->forceFill(['kyc_status' => KycStatus::Approved, 'bank_verified' => true, 'is_demo' => true])->save();
            $wallets->verifyDeposit($wallets->requestDeposit($u, Money::minor(100000000), "demo-deposit-$email"), $admin);
            $investors[] = $inv;
        }

        $businesses = [];
        foreach ([['Dhaka Agro Ventures', 'business1@demo.test', 'Agriculture'], ['Padma Textiles Ltd', 'business2@demo.test', 'Textiles'], ['Surma Retail Co', 'business3@demo.test', 'Retail']] as [$name, $email, $industry]) {
            $u = $this->user($name.' Owner', $email);
            $u->assignRole(UserRole::Business->value);
            $b = new Business(['user_id' => $u->id, 'name' => $name, 'industry' => $industry, 'registration_number' => 'DEMO-'.Str::upper(Str::random(6))]);
            $b->forceFill(['kyc_status' => KycStatus::Approved, 'is_demo' => true])->save();
            $businesses[] = $b;
        }

        // 1) Mudarabah — capital 100,000; actual profit 20,000 at settlement; 70/30 ratio.
        $mud = $this->project($businesses[0], 'Poultry Farm Expansion (Demo)', ContractType::Mudarabah, 10000000, 500000, 12, RiskLevel::Medium, ProjectStatus::Funding);
        $mc = $this->contract($mud, ContractStatus::Approved, $admin);
        $mc->mudarabah()->create(['capital_required' => 10000000, 'investor_profit_bps' => 7000, 'business_profit_bps' => 3000, 'expected_revenue' => 18000000, 'expected_expenses' => 6000000, 'business_plan' => 'Expand to a second shed and cold storage.', 'loss_terms' => 'Loss of capital borne by the investor unless caused by manager negligence or breach.']);

        // 2) Musharakah — investor 700,000 + business 300,000 = 1,000,000.
        $msk = $this->project($businesses[1], 'Textile Dyeing Unit (Demo)', ContractType::Musharakah, 70000000, 2000000, 18, RiskLevel::Medium, ProjectStatus::Funding);
        $kc = $this->contract($msk, ContractStatus::Approved, $admin);
        $kc->musharakah()->create(['total_capital' => 100000000, 'investor_contribution' => 70000000, 'business_contribution' => 30000000, 'investor_ownership_bps' => 7000, 'business_ownership_bps' => 3000, 'investor_profit_bps' => 6500, 'business_profit_bps' => 3500, 'loss_allocation_basis' => LossAllocationBasis::CapitalRatio, 'project_activity' => 'Install a new dyeing line and sell processed fabric.', 'financial_assumptions' => 'Based on 60% capacity utilisation in year one.']);

        // 3) Murabaha — cost 100,000, sale profit 10,000, price 110,000 in 4 installments.
        //    Driven through the real service so purchase, sale and payments are all on the ledger.
        $mrb = $this->project($businesses[2], 'Shop Fit-out Equipment (Demo)', ContractType::Murabaha, 10000000, 10000000, 12, RiskLevel::Low, ProjectStatus::Approved);
        $rc = $this->contract($mrb, ContractStatus::Approved, $admin);
        $price = $murabaha->salePrice(Money::minor(10000000), Money::minor(1000000));
        $mt = $rc->murabaha()->create(['stage' => MurabahaStage::Requested, 'purchase_cost' => 10000000, 'sale_profit' => 1000000, 'sale_price' => $price->minor, 'installments_count' => 4, 'delivery_terms' => 'Delivered to buyer premises.', 'payment_terms' => '4 equal monthly installments.']);
        $mt->assets()->create(['name' => 'Commercial refrigeration units', 'supplier_name' => 'Chittagong Equipment Traders', 'quantity' => 4, 'unit_cost' => 2500000]);
        $workflow->verifySupplierAndAsset($mt, $admin);
        $workflow->recordPurchase($mt->fresh(), Money::minor(10000000), 'INV-DEMO-001', now()->subMonths(2), $admin);
        $workflow->recordOwnership($mt->fresh(), now()->subMonths(2), $admin);
        $workflow->recordPossession($mt->fresh(), now()->subMonths(2)->addDays(3), 'Assets inspected and held by the financier before sale.', $admin);
        $receivable = $workflow->executeSale($mt->fresh(), now()->subMonth(), now()->addDays(5), $admin);
        $workflow->recordPayment($receivable, Money::minor(2750000), 'demo-murabaha-pay-1', now()->subDays(2), $admin);

        // A few demo investments through the real service (ledger-backed, idempotent).
        $investments->invest($investors[0], $mud, Money::minor(3000000), 'demo-inv-1');
        $investments->invest($investors[1], $mud, Money::minor(2000000), 'demo-inv-2');
        $investments->invest($investors[2], $msk, Money::minor(5000000), 'demo-inv-3');
    }

    private function user(string $name, string $email): User
    {
        $u = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password']);
        $u->forceFill(['email_verified_at' => now()])->save();

        return $u;
    }

    private function project(Business $b, string $title, ContractType $type, int $target, int $min, int $months, RiskLevel $risk, ProjectStatus $status): Project
    {
        $p = new Project([
            'business_id' => $b->id, 'title' => $title, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'description' => 'Demo project for development. Not a real offering.', 'industry' => $b->industry,
            'purpose' => 'Demonstration purposes only.', 'contract_type' => $type, 'funding_target' => $target,
            'minimum_amount' => $min, 'duration_months' => $months, 'risk_level' => $risk,
            'key_risks' => 'Demand risk, execution risk, and the possible loss of capital. No return is guaranteed.',
            'closing_at' => now()->addMonths(2),
        ]);
        $p->forceFill(['status' => $status, 'published_at' => now(), 'is_demo' => true])->save();

        return $p;
    }

    private function contract(Project $p, ContractStatus $status, User $by): Contract
    {
        $c = new Contract(['contract_number' => Contract::nextNumber($p->contract_type), 'contract_type' => $p->contract_type, 'project_id' => $p->id, 'start_date' => now(), 'end_date' => now()->addMonths($p->duration_months), 'created_by' => $by->id]);
        $c->forceFill(['status' => $status, 'approved_by' => $by->id, 'approved_at' => now()])->save();

        return $c;
    }
}
