<?php

namespace App\Services\Reports;

use App\Enums\ContractType;
use App\Enums\ProjectStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType as T;
use App\Models\Business;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Project;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Aggregations for dashboards and reports. Grouped in PHP so it behaves the same on MySQL and SQLite. */
class ChartData
{
    public const RANGES = ['7D' => 7, '30D' => 30, '3M' => 90, '6M' => 180, '1Y' => 365, 'ALL' => null];

    /** @return array{from: ?Carbon, unit: string} */
    public function window(string $range): array
    {
        $days = self::RANGES[$range] ?? null;
        $unit = match (true) { $days !== null && $days <= 7 => 'day', $days !== null && $days <= 90 => 'week', default => 'month' };

        return ['from' => $days ? now()->subDays($days)->startOfDay() : null, 'unit' => $unit];
    }

    private function bucket(Carbon $d, string $unit): string
    {
        return match ($unit) { 'day' => $d->format('d M'), 'week' => $d->copy()->startOfWeek()->format('d M'), default => $d->format('M Y') };
    }

    /**
     * @param  array<string, list<T>>  $series  label => transaction types
     * @return array{labels: list<string>, series: array<string, list<int>>}  amounts in minor units
     */
    public function transactionsByBucket(array $series, string $range, ?int $userId = null): array
    {
        ['from' => $from, 'unit' => $unit] = $this->window($range);
        $all = array_merge(...array_values($series));
        $tx = Transaction::query()->where('status', '!=', TransactionStatus::Failed)->whereIn('type', $all)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($from, fn ($q) => $q->where('posted_at', '>=', $from))->get(['type', 'amount', 'posted_at']);

        $labels = $this->labels($from ?? ($tx->min('posted_at') ? Carbon::parse($tx->min('posted_at')) : now()), $unit);
        $out = array_fill_keys(array_keys($series), array_fill_keys($labels, 0));
        foreach ($tx as $t) {
            foreach ($series as $name => $types) {
                if (in_array($t->type, $types, true)) {
                    $out[$name][$this->bucket($t->posted_at, $unit)] = ($out[$name][$this->bucket($t->posted_at, $unit)] ?? 0) + $t->amount;
                }
            }
        }

        return ['labels' => $labels, 'series' => array_map('array_values', $out)];
    }

    /** @return list<string> */
    private function labels(Carbon $from, string $unit): array
    {
        $labels = [];
        $cursor = $from->copy();
        $end = now();
        while ($cursor <= $end) {
            $labels[] = $this->bucket($cursor, $unit);
            $cursor = match ($unit) { 'day' => $cursor->addDay(), 'week' => $cursor->addWeek(), default => $cursor->addMonth() };
        }

        return array_values(array_unique($labels));
    }

    /** Investor portfolio totals inside a range (minor units). */
    public function portfolio(int $userId, string $range): array
    {
        ['from' => $from] = $this->window($range);
        $sum = fn (T $type) => (int) Transaction::where('user_id', $userId)->where('type', $type)->where('status', '!=', TransactionStatus::Failed)
            ->when($from, fn ($q) => $q->where('posted_at', '>=', $from))->sum('amount');
        $invested = $sum(T::Investment);
        $returned = $sum(T::PrincipalReturn);

        return ['invested' => $invested, 'returned' => $returned, 'profit' => $sum(T::ProfitDistribution), 'pending' => max(0, $invested - $returned)];
    }

    /** @return array<string, int> */
    public function contractDistribution(): array
    {
        return collect(ContractType::cases())->mapWithKeys(fn ($t) => [$t->label() => Project::where('contract_type', $t)->count()])->all();
    }

    /** @return array<string, int> */
    public function projectLifecycle(): array
    {
        $order = [ProjectStatus::Draft, ProjectStatus::Review, ProjectStatus::Approved, ProjectStatus::Funding, ProjectStatus::Active, ProjectStatus::Completed, ProjectStatus::Defaulted];

        return collect($order)->mapWithKeys(fn ($s) => [$s->label() => Project::where('status', $s)->count()])->all();
    }

    /** Counts (not money) per month for the last 6 months. */
    public function monthlyActivity(): array
    {
        $from = now()->subMonths(5)->startOfMonth();
        $labels = $this->labels($from, 'month');
        $count = function (string $model, string $col = 'created_at') use ($from, $labels) {
            $rows = array_fill_keys($labels, 0);
            $model::where($col, '>=', $from)->get([$col])->each(function ($r) use (&$rows, $col) {
                $k = Carbon::parse($r->$col)->format('M Y');
                $rows[$k] = ($rows[$k] ?? 0) + 1;
            });

            return array_values($rows);
        };

        return ['labels' => $labels, 'series' => [
            'New investors' => $count(Investor::class), 'New businesses' => $count(Business::class), 'Investments' => $count(Investment::class),
            'Settlements' => $count(Settlement::class), 'Withdrawals' => $count(Withdrawal::class),
        ]];
    }
}
