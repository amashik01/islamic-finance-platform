@props(['title' => null, 'subtitle' => null, 'pad' => true])
<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($actions))
        <header class="flex items-start justify-between gap-3 border-b border-ink-100 px-5 py-4 sm:px-6">
            <div>
                @if($title)<h2 class="text-base font-semibold text-ink-900">{{ $title }}</h2>@endif
                @if($subtitle)<p class="mt-0.5 text-sm text-ink-500">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="shrink-0">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div @class(['card-pad' => $pad])>{{ $slot }}</div>
</section>
