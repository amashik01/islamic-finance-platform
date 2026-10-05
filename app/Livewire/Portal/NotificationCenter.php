<?php

namespace App\Livewire\Portal;

use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Notifications')]
class NotificationCenter extends Component
{
    use WithPagination;

    public function markRead(string $id): void
    {
        auth()->user()->notifications()->whereKey($id)->first()?->markAsRead();
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function render()
    {
        $u = auth()->user();
        $portal = $u->isStaffMember() ? 'admin' : ($u->isBusiness() ? 'business' : ($u->isWakil() ? 'wakil' : 'investor'));

        return view('livewire.portal.notification-center', ['notifications' => $u->notifications()->latest()->paginate(15)])
            ->layout("components.$portal-layout", ['title' => 'Notifications']);
    }
}
