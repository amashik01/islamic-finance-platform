@php
    $projects = \App\Models\Project::with(['business', 'contract.mudarabah', 'contract.musharakah', 'contract.murabaha.assets'])
        ->whereIn('status', [\App\Enums\ProjectStatus::Funding, \App\Enums\ProjectStatus::Active])->latest('published_at')->limit(3)->get();
@endphp
<section class="bg-ink-50 py-16">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <div class="flex items-end justify-between gap-4"><h2 class="font-display text-3xl font-semibold">Featured opportunities</h2><a href="{{ route('opportunities') }}" class="text-sm font-semibold text-brand-700">View all →</a></div>
        <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @forelse ($projects as $p)<x-opportunity-card :project="$p" />@empty
                <div class="md:col-span-3"><x-ui.empty-state title="No opportunities are open right now." message="Approved projects appear here once they are published for funding." /></div>
            @endforelse
        </div>
    </div>
</section>
