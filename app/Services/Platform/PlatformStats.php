<?php

namespace App\Services\Platform;

use App\Enums\InvestmentStatus;
use App\Enums\ProjectStatus;
use App\Models\Business;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Project;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Cache;

/** Database-driven public statistics. Nothing here is hard-coded. */
class PlatformStats
{
    /** @return array{investors:int, funded_businesses:int, active_projects:int, capital_deployed:Money, completed_projects:int, includes_demo:bool} */
    public function public(): array
    {
        return Cache::remember('platform-stats:public', 300, function () {
            $deployed = Investment::whereIn('status', [InvestmentStatus::Confirmed, InvestmentStatus::Active, InvestmentStatus::Completed])->sum('amount');

            return [
                'investors' => Investor::count(),
                'funded_businesses' => Business::whereHas('projects', fn ($q) => $q->whereIn('status', [ProjectStatus::Active, ProjectStatus::Completed]))->count(),
                'active_projects' => Project::whereIn('status', [ProjectStatus::Funding, ProjectStatus::Active])->count(),
                'capital_deployed' => Money::minor((int) $deployed),
                'completed_projects' => Project::where('status', ProjectStatus::Completed)->count(),
                'includes_demo' => Investor::where('is_demo', true)->exists() || Project::where('is_demo', true)->exists(),
            ];
        });
    }
}
