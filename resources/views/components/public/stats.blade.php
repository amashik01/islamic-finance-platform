@php $s = app(\App\Services\Platform\PlatformStats::class)->public(); @endphp
<section class="border-b border-ink-100 bg-white" aria-label="Platform statistics">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6">
        <dl class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            @foreach ([['Active Investors', number_format($s['investors'])], ['Funded Businesses', number_format($s['funded_businesses'])], ['Active Projects', number_format($s['active_projects'])], ['Capital Deployed', $s['capital_deployed']->format()], ['Completed Projects', number_format($s['completed_projects'])]] as [$label, $value])
                <div class="rounded-card bg-ink-50 p-4"><dt class="text-xs font-semibold uppercase tracking-wide text-ink-500">{{ $label }}</dt><dd class="mt-1 font-display text-2xl font-semibold text-brand-900">{{ $value }}</dd></div>
            @endforeach
        </dl>
        @if($s['includes_demo'])<p class="mt-3 text-xs font-medium text-gold-700">Demo data: figures above include seeded sample records for development and are not production statistics.</p>@endif
    </div>
</section>
