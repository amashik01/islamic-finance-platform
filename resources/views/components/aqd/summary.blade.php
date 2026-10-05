@props(['steps', 'form', 'def'])
<div class="space-y-4">
    @foreach($steps as $s)
        @php $rows = collect($s['fields'])->filter(fn ($f) => $def->applies($f, $form) && filled($form[$f['key']] ?? null) && ! is_array($form[$f['key']] ?? null)); @endphp
        @if($rows->isNotEmpty())
            <section>
                <h3 class="text-sm font-semibold text-ink-800">{{ $s['title'] }}</h3>
                <dl class="mt-1 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    @foreach($rows as $f)
                        @php $v = $form[$f['key']]; $shown = $f['type'] === 'checkbox' ? 'Acknowledged' : ($f['type'] === 'select' ? ($f['options'][$v] ?? $v) : (in_array($f['type'], ['money']) ? 'BDT '.$v : ($f['type'] === 'percent' ? $v.'%' : $v))); @endphp
                        <div class="sm:col-span-{{ $f['type'] === 'textarea' ? 2 : 1 }}"><dt class="text-ink-500">{{ $f['label'] }}</dt><dd class="whitespace-pre-line font-medium">{{ $shown }}</dd></div>
                    @endforeach
                </dl>
            </section>
        @endif
    @endforeach
</div>
