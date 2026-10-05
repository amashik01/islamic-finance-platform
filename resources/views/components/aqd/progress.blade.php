@props(['steps', 'step'])
<nav aria-label="Progress"><ol class="flex flex-wrap gap-2">
    @foreach($steps as $i => $s)
        @php $n = $i + 1; @endphp
        <li @class(['rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset', 'bg-brand-700 text-white ring-brand-700' => $n === $step, 'bg-brand-50 text-brand-800 ring-brand-200' => $n < $step, 'bg-white text-ink-500 ring-ink-200' => $n > $step]) @if($n === $step) aria-current="step" @endif>{{ $n }}. {{ $s['title'] }}</li>
    @endforeach
</ol></nav>
