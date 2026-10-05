<?php

namespace App\Services\Reports;

use App\Enums\ContractStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType as T;
use App\Models\Business;
use App\Models\Contract;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\PaymentSchedule;
use App\Models\Project;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * Builds report tables. Scope + report decide the data; every query is limited to what the user may see.
 * Each report returns ['title' => string, 'headers' => list<string>, 'rows' => list<list<scalar>>].
 */
class ReportService
{
    /** scope => report key => [label, required permission|null] */
    public const CATALOG = [
        'admin' => [
            'capital-flow' => ['Capital flow', 'reports.view'], 'investors' => ['Investor report', 'reports.view'], 'businesses' => ['Business report', 'reports.view'],
            'contracts' => ['Contract report', 'reports.view'], 'settlements' => ['Settlement report', 'reports.view'],
            'profit-distribution' => ['Profit distribution', 'reports.view'], 'overdue' => ['Overdue / default report', 'reports.view'],
        ],
        'investor' => [
            'portfolio' => ['Portfolio statement', null], 'transactions' => ['Transaction statement', null],
            'profit' => ['Profit statement', null], 'principal-returns' => ['Principal-return statement', null],
        ],
        'business' => [
            'funding' => ['Funding report', null], 'projects' => ['Project report', null], 'payments' => ['Payment report', null], 'outstanding' => ['Outstanding report', null],
        ],
    ];

    public function allowed(string $scope, string $report, User $user): bool
    {
        $entry = self::CATALOG[$scope][$report] ?? null;
        if (! $entry) {
            return false;
        }
        $roleOk = match ($scope) { 'admin' => $user->isStaffMember(), 'investor' => $user->isInvestor(), 'business' => $user->isBusiness(), default => false };

        return $roleOk && (! $entry[1] || $user->can($entry[1]));
    }

    public function build(string $scope, string $report, User $user): array
    {
        abort_unless($this->allowed($scope, $report, $user), 403);
        $title = self::CATALOG[$scope][$report][0];
        [$headers, $rows] = $this->{'r_'.str_replace('-', '_', $scope.'_'.$report)}($user);

        return compact('title', 'headers', 'rows');
    }

    private static function m(int $minor): string
    {
        return Money::minor($minor)->toDecimal();
    }

    private static function d($date): string
    {
        return $date ? $date->format('Y-m-d') : '';
    }

    /* ----------------------------------- admin ----------------------------------- */

    private function r_admin_capital_flow(): array
    {
        $rows = Transaction::with(['user', 'project'])->latest('posted_at')->limit(5000)->get()
            ->map(fn ($t) => [self::d($t->posted_at), $t->reference, $t->type->label(), $t->user?->name ?? 'Platform', $t->project?->title ?? '', self::m($t->amount), $t->status->label()])->all();

        return [['Date', 'Reference', 'Type', 'Party', 'Project', 'Amount (BDT)', 'Status'], $rows];
    }

    private function r_admin_investors(): array
    {
        $rows = Investor::with('user')->withSum('investments as invested', 'amount')->get()
            ->map(fn ($i) => [$i->user->name, $i->user->email, $i->kyc_status->label(), self::m((int) $i->invested), self::d($i->created_at)])->all();

        return [['Investor', 'Email', 'KYC', 'Invested (BDT)', 'Joined'], $rows];
    }

    private function r_admin_businesses(): array
    {
        $rows = Business::with('user')->withCount('projects')->withSum('projects as raised', 'funded_amount')->get()
            ->map(fn ($b) => [$b->name, $b->industry ?? '', $b->kyc_status->label(), $b->projects_count, self::m((int) $b->raised), self::d($b->created_at)])->all();

        return [['Business', 'Industry', 'KYC', 'Projects', 'Raised (BDT)', 'Joined'], $rows];
    }

    private function r_admin_contracts(): array
    {
        $rows = Contract::with('project.business')->get()
            ->map(fn ($c) => [$c->contract_number, $c->contract_type->label(), $c->project->title, $c->project->business->name, $c->status->label(), self::d($c->start_date), self::d($c->end_date)])->all();

        return [['Contract', 'Type', 'Project', 'Business', 'Status', 'Start', 'End'], $rows];
    }

    private function r_admin_settlements(): array
    {
        $rows = Settlement::with(['project', 'contract', 'items'])->get()->flatMap(fn ($s) => $s->items->isEmpty()
            ? [[$s->reference, $s->project->title, $s->contract->contract_type->label(), $s->status->label(), '', '', self::d($s->posted_at)]]
            : $s->items->map(fn ($i) => [$s->reference, $s->project->title, $s->contract->contract_type->label(), $s->status->label(), $i->item_type->label(), self::m($i->amount), self::d($s->posted_at)]))->all();

        return [['Settlement', 'Project', 'Contract', 'Status', 'Item', 'Amount (BDT)', 'Posted'], $rows];
    }

    private function r_admin_profit_distribution(): array
    {
        $rows = Transaction::with(['user', 'project'])->where('type', T::ProfitDistribution)->latest('posted_at')->get()
            ->map(fn ($t) => [self::d($t->posted_at), $t->reference, $t->user?->name ?? '', $t->project?->title ?? '', self::m($t->amount)])->all();

        return [['Date', 'Reference', 'Investor', 'Project', 'Profit (BDT)'], $rows];
    }

    private function r_admin_overdue(): array
    {
        $overdue = PaymentSchedule::with('receivable.business', 'receivable.sale.murabahaContract.contract')->where('status', PaymentStatus::Overdue)->get()
            ->map(fn ($s) => [$s->receivable->sale->murabahaContract->contract->contract_number, $s->receivable->business->name, 'Installment '.$s->sequence, self::d($s->due_date), (int) now()->diffInDays($s->due_date, true), self::m($s->amount - $s->paid_amount)])->all();
        $defaulted = Contract::with('project.business')->where('status', ContractStatus::Defaulted)->get()
            ->map(fn ($c) => [$c->contract_number, $c->project->business->name, 'Defaulted', self::d($c->end_date), '', ''])->all();

        return [['Contract', 'Business', 'Item', 'Due', 'Days overdue', 'Outstanding (BDT)'], array_merge($overdue, $defaulted)];
    }

    /* ---------------------------------- investor --------------------------------- */

    private function r_investor_portfolio(User $u): array
    {
        $rows = Investment::with('project')->where('investor_id', $u->investor->id)->get()
            ->map(fn ($i) => [$i->project->title, $i->project->contract_type->label(), self::m($i->amount), $i->status->label(), self::d($i->invested_at), self::d($i->maturity_date)])->all();

        return [['Project', 'Contract', 'Invested (BDT)', 'Status', 'Invested on', 'Maturity'], $rows];
    }

    private function r_investor_transactions(User $u): array
    {
        $rows = Transaction::with('project')->where('user_id', $u->id)->orderBy('posted_at')->get()
            ->map(fn ($t) => [self::d($t->posted_at), $t->reference, $t->type->label(), $t->project?->title ?? '', self::m($t->amount), $t->status->label()])->all();

        return [['Date', 'Reference', 'Type', 'Project', 'Amount (BDT)', 'Status'], $rows];
    }

    private function typed(User $u, T $type, string $col): array
    {
        $rows = Transaction::with('project')->where('user_id', $u->id)->where('type', $type)->orderBy('posted_at')->get()
            ->map(fn ($t) => [self::d($t->posted_at), $t->reference, $t->project?->title ?? '', self::m($t->amount)])->all();

        return [['Date', 'Reference', 'Project', $col], $rows];
    }

    private function r_investor_profit(User $u): array
    {
        return $this->typed($u, T::ProfitDistribution, 'Profit (BDT)');
    }

    private function r_investor_principal_returns(User $u): array
    {
        return $this->typed($u, T::PrincipalReturn, 'Principal returned (BDT)');
    }

    /* ---------------------------------- business --------------------------------- */

    private function r_business_funding(User $u): array
    {
        $rows = Project::where('business_id', $u->business->id)->get()->map(fn ($p) => [$p->title, $p->contract_type->label(), self::m($p->funding_target), self::m($p->funded_amount), $p->fundingPercent().'%', $p->status->label()])->all();

        return [['Project', 'Contract', 'Target (BDT)', 'Received (BDT)', 'Progress', 'Status'], $rows];
    }

    private function r_business_projects(User $u): array
    {
        $rows = Project::where('business_id', $u->business->id)->get()->map(fn ($p) => [$p->title, $p->contract_type->label(), $p->status->label(), $p->duration_months, self::d($p->created_at), self::d($p->published_at)])->all();

        return [['Project', 'Contract', 'Status', 'Months', 'Created', 'Published'], $rows];
    }

    private function schedules(User $u): Collection
    {
        return PaymentSchedule::with('receivable.sale.murabahaContract.contract.project')->whereHas('receivable', fn ($q) => $q->where('business_id', $u->business->id))->orderBy('due_date')->get();
    }

    private function r_business_payments(User $u): array
    {
        $rows = $this->schedules($u)->map(fn ($s) => [$s->receivable->sale->murabahaContract->contract->project->title, $s->sequence, self::d($s->due_date), self::m($s->amount), self::m($s->paid_amount), $s->status->label()])->all();

        return [['Project', 'Installment', 'Due', 'Amount (BDT)', 'Paid (BDT)', 'Status'], $rows];
    }

    private function r_business_outstanding(User $u): array
    {
        $rows = $this->schedules($u)->filter(fn ($s) => $s->amount > $s->paid_amount)->map(fn ($s) => [$s->receivable->sale->murabahaContract->contract->project->title, $s->sequence, self::d($s->due_date), self::m($s->amount - $s->paid_amount), $s->status->label()])->values()->all();

        return [['Project', 'Installment', 'Due', 'Outstanding (BDT)', 'Status'], $rows];
    }
}
