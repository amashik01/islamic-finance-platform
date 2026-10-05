<x-public-layout title="Opportunities">
    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6">
        <h1 class="font-display text-3xl font-semibold">Opportunities</h1>
        <p class="mt-2 max-w-2xl text-ink-600">Reviewed projects open for funding. Each shows its contract structure, terms and risk level. Nothing here is a guaranteed return.</p>
        <nav class="mt-6 flex flex-wrap gap-2" aria-label="Filter by contract">
            <a href="{{ route('opportunities') }}" @class(['btn-sm', 'btn-primary' => ! $type, 'btn-secondary' => $type])>All</a>
            @foreach (\App\Enums\ContractType::cases() as $c)<a href="{{ route('opportunities', ['type' => $c->value]) }}" @class(['btn-sm', 'btn-primary' => $type === $c, 'btn-secondary' => $type !== $c])>{{ $c->label() }}</a>@endforeach
        </nav>
        <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @forelse ($projects as $p)<x-opportunity-card :project="$p" />@empty
                <div class="md:col-span-3"><x-ui.empty-state title="No opportunities match your filter." message="Try another contract type or check back soon." /></div>
            @endforelse
        </div>
        <div class="mt-8">{{ $projects->links() }}</div>
    </section>
</x-public-layout>
