<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SyncNotification extends Notification
{
    use Queueable;

    /** @var array Entity types mapping for sync operations with Ukrainian descriptions */
    protected const array SYNC_ENTITIES = [
        'legators' => 'Синхронізація залежних закладів',
        'legal_entity' => 'Синхронізація даних медичного закладу',
        'employee' => 'Синхронізація співробітників',
        'employee_request' => 'Синхронізація заявок',
        'division' => 'Синхронізація підрозділів',
        'healthcare_service' => 'Синхронізація послуг',
        'equipment' => 'Синхронізація обладнання',
        'employee_role' => 'Синхронізація ролей',
        'patient' => 'Синхронізація пацієнтів',
        'license' => 'Синхронізація ліцензій',
        'declaration' => 'Синхронізація декларацій',
        'episode' => 'Синхронізація епізодів',
    ];

    /** @var array Sync action statuses mapping with Ukrainian descriptions */
    protected const array SYNC_ACTIONS = [
        'started' => 'розпочата',
        'completed' => 'завершена',
        'failed' => 'не вдалася',
        'paused' => 'призупинена',
        'resumed' => 'відновлена'
    ];

    protected string $type;

    protected string $action;

    /**
     * TODO: implement enum
     * Create a new notification instance.
     */
    public function __construct(?string $type, string $action)
    {
        $this->type = $type ?? '';
        $this->action = $action;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'action' => $this->action,
            'message' => $this->formatMessage(),
            'time' => now()->toDateTimeString(),
        ];
    }

    /**
     * Format the notification message by combining entity type and action.
     *
     * This method creates a human-readable message in Ukrainian by looking up
     * the entity description and action status from the predefined constants.
     * Falls back to default values if the provided type or action is not found.
     *
     * @return string
     */
    protected function formatMessage(): string
    {
        $entity = data_get(self::SYNC_ENTITIES, $this->type, 'Синхронізація даних');
        $action = data_get(self::SYNC_ACTIONS, $this->action, '');

        return "{$entity} {$action}.";
    }
}
