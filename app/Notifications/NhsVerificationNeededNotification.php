<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NhsVerificationNeededNotification extends Notification
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * Nothing is delivered while the user has not read the previous notification of this kind,
     * so that re-saving the same person request does not pile up identical notifications.
     *
     * @return array
     */
    public function via(object $notifiable): array
    {
        $isPending = $notifiable->unreadNotifications()
            ->where('type', self::class)
            ->exists();

        return $isPending ? [] : ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('patient-verifications.warning_title'),
            'message' => __('patient-verifications.warning_message'),
            'time' => now()->toDateTimeString()
        ];
    }
}
