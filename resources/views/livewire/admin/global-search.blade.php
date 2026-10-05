<div class="flex items-center gap-2" x-data="{ open: false }" @keydown.escape="open = false">
    <div class="relative hidden sm:block">
        <label for="global-search" class="sr-only">Search users, businesses, projects, contracts and transactions</label>
        <input id="global-search" type="search" wire:model.live.debounce.300ms="q" @focus="open = true" @click.outside="open = false" autocomplete="off" placeholder="Search…" class="input w-64 py-1.5" role="combobox" :aria-expanded="open && {{ count($results) ? 'true' : 'false' }}" aria-controls="search-results">
        @if(count($results))
            <ul id="search-results" x-show="open" x-cloak role="listbox" class="absolute left-0 z-30 mt-1 max-h-80 w-96 overflow-auto rounded-card border border-ink-100 bg-white p-1 shadow-lift">
                @foreach(collect($results)->groupBy('group') as $group => $rows)
                    <li class="px-3 pt-2 text-[11px] font-semibold uppercase tracking-wide text-ink-500" role="presentation">{{ $group }}</li>
                    @foreach($rows as $r)<li role="option"><a href="{{ $r['url'] }}" class="block rounded-control px-3 py-1.5 text-sm hover:bg-ink-50"><span class="font-medium">{{ $r['label'] }}</span> <span class="text-xs text-ink-500">{{ $r['sub'] }}</span></a></li>@endforeach
                @endforeach
            </ul>
        @elseif(mb_strlen(trim($q)) >= 2)
            <p x-show="open" x-cloak class="absolute left-0 z-30 mt-1 w-64 rounded-card border border-ink-100 bg-white p-3 text-sm text-ink-500 shadow-lift">No matches.</p>
        @endif
    </div>
    @if($pending > 0)<a href="{{ route('admin.withdrawals') }}" class="rounded-full bg-gold-100 px-2.5 py-1 text-xs font-semibold text-gold-700 ring-1 ring-gold-200" title="Items waiting for action">{{ $pending }} pending <span class="sr-only">actions</span></a>@endif
</div>
