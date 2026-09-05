<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class NotificationsDropdown extends Component
{
    public DatabaseNotificationCollection $notifications;

    public function mount(): void
    {
        $this->notifications = Auth::user()->unreadNotifications->take(4);
    }

    /**
     * Mark notification as read.
     *
     * @param  string  $id
     * @return void
     */
    public function markAsRead(string $id): void
    {
        $notification = Auth::user()?->unreadNotifications()->findOrFail($id);
        if ($notification) {
            $notification->markAsRead();
            $this->notifications = Auth::user()->unreadNotifications()->take(4)->get();
        }
    }

    /**
     * Mark all notifications as read.
     *
     * @return void
     */
    public function markAllAsRead(): void
    {
        Auth::user()?->unreadNotifications()->update(['read_at' => now()]);
        $this->notifications = new DatabaseNotificationCollection();
    }

    #[Computed]
    public function totalUnreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    public function getNotificationIconType(DatabaseNotification $notification): string
    {
        return $notification->data['action'] ?? '';
    }

    public function render(): View
    {
        return view('livewire.notifications-dropdown');
    }
}
