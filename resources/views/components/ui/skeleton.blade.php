@props(['lines' => 3])
<div {{ $attributes->merge(['class' => 'animate-pulse space-y-3']) }} aria-hidden="true">
    @for($i = 0; $i < $lines; $i++)<div class="h-4 rounded bg-ink-100" style="width: {{ 100 - ($i * 12) }}%"></div>@endfor
</div>
