<?php

namespace App\Services\Notify;

use App\Models\User;
use App\Notifications\PlatformNotification;
use Illuminate\Support\Facades\Notification;

class Notifier
{
    public function to(?User $user, string $title, string $message, string $kind = 'info', ?string $url = null): void
    {
        if ($user) {
            $user->notify(new PlatformNotification($title, $message, $kind, $url));
        }
    }

    /** Notify every staff member who holds a permission (e.g. new withdrawal -> withdrawals.approve). */
    public function toStaffWith(string $permission, string $title, string $message, ?string $url = null): void
    {
        $staff = User::permission($permission)->get()->merge(User::role('ADMIN')->get())->unique('id');
        Notification::send($staff, new PlatformNotification($title, $message, 'action', $url));
    }
}
