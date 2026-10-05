<x-ui.card title="Notifications">
    @forelse($notifications as $n)
        <div wire:key="n-{{ $n->id }}" class="flex items-start justify-between gap-3 border-b border-ink-100 py-3 last:border-0">
            <div class="text-sm"><p @class(['font-semibold' => ! $n->read_at, 'text-ink-900'])>{{ $n->data['title'] ?? 'Notification' }} @unless($n->read_at)<x-ui.badge tone="warning">New</x-ui.badge>@endunless</p>
                <p class="text-ink-600">{{ $n->data['message'] ?? '' }}</p><p class="text-xs text-ink-500">{{ $n->created_at->diffForHumans() }}</p></div>
            @unless($n->read_at)<button wire:click="markRead('{{ $n->id }}')" class="btn-ghost btn-sm shrink-0">Mark read</button>@endunless
        </div>
    @empty<x-ui.empty-state title="You're all caught up." message="Updates about your investments, withdrawals and projects will appear here." />@endforelse
    <div class="mt-4 flex items-center justify-between">{{ $notifications->links() }}<button wire:click="markAllRead" class="btn-ghost btn-sm">Mark all as read</button></div>
</x-ui.card>
