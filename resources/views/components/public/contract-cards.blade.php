@php
    $cards = [
        ['mudarabah', 'Mudarabah', 'Profit-sharing partnership where capital is provided by the investor and business management is provided by the entrepreneur.', 'Actual profit is shared by an agreed ratio. Loss of capital falls on the investor unless there is negligence or breach by the manager.'],
        ['musharakah', 'Musharakah', 'Partnership where both investor and business contribute capital.', 'Profit is shared by an agreed ratio; loss follows the contract basis, usually capital contribution.'],
        ['murabaha', 'Murabaha', 'Asset and goods-based cost-plus sale structure.', 'The business is sold an asset the seller owns and possesses, for a disclosed cost plus a disclosed sale profit, payable on schedule.'],
    ];
@endphp
<section class="bg-white py-16">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <h2 class="font-display text-3xl font-semibold">Three clear contract structures</h2>
        <div class="mt-10 grid gap-6 md:grid-cols-3">
            @foreach ($cards as [$slug, $title, $text, $how])
                <article class="card card-pad flex flex-col">
                    <span class="flex h-11 w-11 items-center justify-center rounded-control bg-brand-50 text-brand-700"><x-icon :name="['mudarabah' => 'scale', 'musharakah' => 'users', 'murabaha' => 'briefcase'][$slug]" class="h-6 w-6" /></span>
                    <h3 class="mt-4 text-xl font-semibold">{{ $title }}</h3>
                    <p class="mt-2 text-sm text-ink-600">{{ $text }}</p>
                    <p class="mt-3 text-sm text-ink-500"><span class="font-semibold text-ink-700">How it works: </span>{{ $how }}</p>
                    <a href="{{ route('islamic-finance') }}#{{ $slug }}" class="mt-auto pt-5 text-sm font-semibold text-brand-700 hover:text-brand-900">Learn more →</a>
                </article>
            @endforeach
        </div>
    </div>
</section>
