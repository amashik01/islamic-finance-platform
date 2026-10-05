@props(['spec', 'model' => 'form'])
@php
    $k = $spec['key']; $path = $model.'.'.$k; $id = 'f-'.$k;
    $reg = app(\App\Services\Shariah\ShariahRuleRegistry::class);
    $rules = collect($spec['rules'] ?? [])->map(fn ($c) => $reg->all()->get($c))->filter();
    $hasGuidance = $spec['islamic'] || $spec['valid'] || $spec['invalid'] || $rules->isNotEmpty();
@endphp
<div class="space-y-1" wire:key="field-{{ $k }}">
    @if($spec['type'] === 'checkbox')
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" id="{{ $id }}" wire:model="{{ $path }}" class="mt-1"> <span>{{ $spec['label'] }}@if($spec['required']) <span class="text-red-600" aria-hidden="true">*</span>@endif</span></label>
    @else
        <label for="{{ $id }}" class="label">{{ $spec['label'] }}@if($spec['required']) <span class="text-red-600" aria-hidden="true">*</span>@endif</label>
        @if($spec['type'] === 'textarea')
            <textarea id="{{ $id }}" wire:model="{{ $path }}" rows="3" class="input"></textarea>
        @elseif($spec['type'] === 'select')
            <select id="{{ $id }}" wire:model.live="{{ $path }}" class="input"><option value="">Select…</option>@foreach($spec['options'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
        @elseif($spec['type'] === 'money')
            <div class="flex"><span class="inline-flex items-center rounded-l-control border border-r-0 border-ink-200 bg-ink-50 px-3 text-sm text-ink-500">BDT</span><input id="{{ $id }}" wire:model.live.debounce.400ms="{{ $path }}" inputmode="decimal" class="input rounded-l-none"></div>
        @elseif($spec['type'] === 'percent')
            <div class="flex"><input id="{{ $id }}" wire:model.live.debounce.400ms="{{ $path }}" inputmode="decimal" class="input rounded-r-none"><span class="inline-flex items-center rounded-r-control border border-l-0 border-ink-200 bg-ink-50 px-3 text-sm text-ink-500">%</span></div>
        @else
            <input id="{{ $id }}" type="{{ in_array($spec['type'], ['date', 'number']) ? $spec['type'] : 'text' }}" wire:model="{{ $path }}" class="input">
        @endif
    @endif
    @error($path)<p class="field-error" role="alert">{{ $message }}</p>@enderror
    @if($spec['what'] || $spec['help'])<p class="text-xs text-ink-500">{{ $spec['what'] }} {{ $spec['help'] }}</p>@endif
    @if($hasGuidance)
        <details class="text-xs text-ink-600">
            <summary class="cursor-pointer font-medium text-brand-700">Islamic guidance</summary>
            <div class="mt-1 space-y-1 rounded-control bg-brand-50 p-2">
                @if($spec['islamic'])<p>{{ $spec['islamic'] }}</p>@endif
                @if($spec['valid'])<p><span class="font-medium text-brand-800">Valid example:</span> {{ $spec['valid'] }}</p>@endif
                @if($spec['invalid'])<p><span class="font-medium text-red-700">Not valid:</span> {{ $spec['invalid'] }}</p>@endif
                @foreach($rules as $r)
                    <p class="text-ink-500"><span class="font-mono">{{ $r['code'] }}</span> — {{ $r['source_name'] }}@if($r['clause_reference']), {{ $r['clause_reference'] }}@endif
                        <span class="rounded bg-white px-1">{{ $r['verification'] === 'UNVERIFIED' ? 'source verification required' : ($r['verification'] === 'TEXT_READ' ? 'source text read' : 'source cited, confirm') }}</span> · under review, not a fatwa</p>
                @endforeach
            </div>
        </details>
    @endif
</div>
