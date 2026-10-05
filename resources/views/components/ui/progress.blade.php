@props(['value' => 0, 'label' => 'Funding progress'])
<div>
    <div class="h-2 overflow-hidden rounded-full bg-ink-100" role="progressbar" aria-label="{{ $label }}" aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100">
        <div class="h-full rounded-full bg-brand-600 transition-all" style="width: {{ max(0, min(100, $value)) }}%"></div>
    </div>
    <p class="mt-1 text-xs font-medium text-ink-600">{{ $value }}% funded</p>
</div>
