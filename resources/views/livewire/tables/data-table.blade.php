<div class="space-y-4">
    @if($notice)<x-ui.alert type="success">{{ $notice }}</x-ui.alert>@endif

    <div class="flex flex-wrap items-end gap-3">
        <h2 class="mr-auto text-lg font-semibold text-ink-900">{{ $heading }}</h2>
        @if($searchable)
            <div><label for="table-search" class="sr-only">Search</label>
                <input id="table-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search..." class="input w-56"></div>
        @endif
        @foreach($filters as $key => $f)
            <div><label for="f-{{ $key }}" class="sr-only">{{ $f['label'] }}</label>
                <select id="f-{{ $key }}" wire:model.live="filter.{{ $key }}" class="input w-44">
                    <option value="">{{ $f['label'] }}: All</option>
                    @foreach($f['options'] as $v => $text)<option value="{{ $v }}">{{ $text }}</option>@endforeach
                </select></div>
        @endforeach
    </div>

    <div class="relative">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-card bg-white/60"><span class="m-4 inline-block text-sm text-ink-500">Loading...</span></div>

        @if($rows->isEmpty())
            <x-ui.empty-state :title="$search || array_filter($filter) ? 'No results match your search or filters.' : $emptyTitle" />
        @else
            {{-- Desktop table --}}
            <div class="table-wrap hidden md:block">
                <table class="table">
                    <thead><tr>
                        @foreach($columns as $key => $c)
                            <th scope="col" @if($c['sortable'] ?? false) aria-sort="{{ $sort === $key ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif>
                                @if($c['sortable'] ?? false)
                                    <button type="button" wire:click="sortBy('{{ $key }}')" class="inline-flex items-center gap-1 uppercase">{{ $c['label'] }}<span aria-hidden="true">{{ $sort === $key ? ($dir === 'asc' ? '↑' : '↓') : '' }}</span></button>
                                @else{{ $c['label'] }}@endif
                            </th>
                        @endforeach
                        @if($actionsView)<th scope="col"><span class="sr-only">Actions</span></th>@endif
                    </tr></thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach($rows as $row)
                            <tr wire:key="row-{{ $row->getKey() }}">
                                @foreach($columns as $c)<td>{{ ($c['render'])($row) }}</td>@endforeach
                                @if($actionsView)<td class="whitespace-nowrap text-right">@include($actionsView, ['row' => $row])</td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Mobile cards --}}
            <div class="space-y-3 md:hidden">
                @foreach($rows as $row)
                    <div wire:key="card-{{ $row->getKey() }}" class="card card-pad">
                        <dl class="space-y-1.5 text-sm">
                            @foreach($columns as $c)<div class="flex justify-between gap-3"><dt class="text-ink-500">{{ $c['label'] }}</dt><dd class="text-right font-medium text-ink-800">{{ ($c['render'])($row) }}</dd></div>@endforeach
                        </dl>
                        @if($actionsView)<div class="mt-3 flex flex-wrap justify-end gap-2 border-t border-ink-100 pt-3">@include($actionsView, ['row' => $row])</div>@endif
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2 text-sm text-ink-500"><label for="per-page">Rows</label>
                    <select id="per-page" wire:model.live="perPage" class="input w-20 py-1">@foreach([10,15,25,50] as $n)<option>{{ $n }}</option>@endforeach</select></div>
                <div class="flex-1">{{ $rows->links() }}</div>
            </div>
        @endif
    </div>

    {{-- Confirmation modal for sensitive actions --}}
    <x-ui.modal name="confirm-action" :title="$confirming['label'] ?? 'Confirm'">
        @if($error)<x-ui.alert type="error" class="mb-3">{{ $error }}</x-ui.alert>@endif
        <p>Please confirm this action. It will be recorded in the audit log.</p>
        @if($confirming['needsReason'] ?? false)
            <label for="reason" class="label mt-3">Reason</label>
            <textarea id="reason" wire:model="reason" rows="3" class="input"></textarea>
            @error('reason')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'confirm-action')">Cancel</x-ui.button>
            <x-ui.button :variant="($confirming['tone'] ?? 'primary') === 'danger' ? 'danger' : 'primary'" wire:click="confirm" loading="confirm" loading-text="Processing...">Confirm</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
