<?php

namespace App\Http\Controllers\Public;

use App\Enums\ContractType;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

class PageController extends Controller
{
    private const PUBLIC_STATUSES = [ProjectStatus::Funding, ProjectStatus::Active, ProjectStatus::Completed];

    public function opportunities(Request $request)
    {
        $type = ContractType::tryFrom((string) $request->query('type'));

        $projects = Project::with(['business', 'contract.mudarabah', 'contract.musharakah', 'contract.murabaha.assets'])
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->when($type, fn ($q) => $q->where('contract_type', $type))
            ->orderByRaw("CASE status WHEN 'FUNDING' THEN 0 ELSE 1 END")
            ->latest('published_at')
            ->paginate(9)->withQueryString();

        return view('public.opportunities', compact('projects', 'type'));
    }

    public function opportunity(Project $project)
    {
        abort_unless(in_array($project->status, self::PUBLIC_STATUSES, true), 404);
        $project->load(['business', 'contract.mudarabah', 'contract.musharakah', 'contract.murabaha.assets', 'shariahReviews']);

        return view('public.opportunity', compact('project'));
    }

    private const LEGAL = [
        'terms' => 'Terms of Use',
        'privacy' => 'Privacy Policy',
        'risk-disclosure' => 'Risk Disclosure',
        'shariah-disclaimer' => 'Shariah Disclaimer',
        'compliance' => 'Compliance',
    ];

    public function legal(string $page)
    {
        abort_unless(isset(self::LEGAL[$page]), 404);

        return view('public.legal', ['title' => self::LEGAL[$page], 'page' => $page]);
    }
}
