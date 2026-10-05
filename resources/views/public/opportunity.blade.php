<x-public-layout :title="$project->title">
    <section class="mx-auto max-w-5xl px-4 py-12 sm:px-6">
        <a href="{{ route('opportunities') }}" class="text-sm font-medium text-brand-700">← All opportunities</a>
        <div class="mt-4 grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-6">
                <div>
                    <div class="flex gap-2"><x-ui.badge tone="info">{{ $project->contract_type->label() }}</x-ui.badge><x-status-badge :status="$project->status" /></div>
                    <h1 class="mt-3 font-display text-3xl font-semibold">{{ $project->title }}</h1>
                    <p class="text-ink-500">by {{ $project->business->name }} · {{ $project->industry }}</p>
                </div>
                <x-ui.card title="About this project"><p class="text-sm leading-relaxed text-ink-700">{{ $project->description }}</p>@if($project->purpose)<p class="mt-3 text-sm text-ink-700"><strong>Purpose:</strong> {{ $project->purpose }}</p>@endif</x-ui.card>
                <x-ui.card title="Key risks"><p class="text-sm text-ink-700">{{ $project->key_risks ?: 'Risk information will be published with the project documents.' }}</p><p class="mt-3 text-xs text-ink-500">Risk level: <strong>{{ $project->risk_level->label() }}</strong>. Capital may be lost; no return is guaranteed.</p></x-ui.card>
                <x-ui.card title="Shariah review">
                    @php $review = $project->shariahReviews->sortByDesc('id')->first(); @endphp
                    <p class="text-sm">Status: <x-status-badge :status="$review?->status ?? \App\Enums\ShariahReviewStatus::Pending" /></p>
                    <p class="mt-2 text-xs text-ink-500">{{ config('finance.shariah_disclaimer') }}</p>
                </x-ui.card>
            </div>
            <aside class="space-y-4">
                <x-opportunity-card :project="$project" />
                @auth
                    <x-ui.button :href="route('investor.opportunities')" class="w-full">Invest from your portal</x-ui.button>
                @else
                    <x-ui.button :href="route('register')" class="w-full">Create account to invest</x-ui.button>
                @endauth
            </aside>
        </div>
    </section>
</x-public-layout>
