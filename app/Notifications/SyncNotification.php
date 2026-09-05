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
        'employee' => 'Синхронізація працівників',
        'employee_request' => 'Синхронізація заявок',
        'division' => 'Синхронізація місць надання послуг',
        'healthcare_service' => 'Синхронізація послуг',
        'equipment' => 'Синхронізація обладнання',
        'employee_role' => 'Синхронізація ролей',
        'patient' => 'Синхронізація пацієнтів',
        'license' => 'Синхронізація ліцензій',
        'contract' => 'Синхронізація договорів',
        'contract_request' => 'Синхронізація заявок на договори',
        'declaration' => 'Синхронізація декларацій',
        'declaration_request' => 'Синхронізація заявок на декларації',
        'episode' => 'Синхронізація епізодів',
        'encounter' => 'Синхронізація взаємодій',
        'clinical_impression' => 'Синхронізація клінічних оцінок',
        'immunization' => 'Синхронізація вакцинацій',
        'observation' => 'Синхронізація обстежень',
        'condition' => 'Синхронізація станів',
        'diagnostic_report' => 'Синхронізація діагностичних звітів',
        'procedure' => 'Синхронізація процедур',
        'party_verification' => 'Синхронізація верифікацій працівників',
        'division_' => 'Синхронізація місць надання послуг',
        'hcs_' => 'Синхронізація послуг',
        'employee_' => 'Синхронізація працівників',
        'employee_role_' => 'Синхронізація ролей',
        'employee_request_' => 'Синхронізація заявок',
        'license_' => 'Синхронізація ліцензій',
        'contract_' => 'Синхронізація договорів',
        'contract_request_' => 'Синхронізація заявок на договори',
        'declaration_' => 'Синхронізація декларацій',
        'declaration_request_' => 'Синхронізація заявок на декларації',
        'equipment_' => 'Синхронізація обладнання',
        'episode_' => 'Синхронізація епізодів',
        'encounter_' => 'Синхронізація взаємодій',
        'clinical_impression_' => 'Синхронізація клінічних оцінок',
        'immunization_' => 'Синхронізація вакцинацій',
        'observation_' => 'Синхронізація обстежень',
        'condition_' => 'Синхронізація станів',
        'diagnostic_report_' => 'Синхронізація діагностичних звітів',
        'procedure_' => 'Синхронізація процедур',
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
