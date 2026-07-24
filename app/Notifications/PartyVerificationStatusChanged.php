<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LegalEntity;
use App\Models\Relations\Party;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PartyVerificationStatusChanged extends Notification
{
    use Queueable;

    public Party $party;
    public string $newStatus;
    public LegalEntity $legalEntity;

    /**
     * Create a new notification instance.
     */
    public function __construct(Party $party, string $newStatus, LegalEntity $legalEntity)
    {
        $this->party = $party;
        $this->newStatus = $newStatus;
        $this->legalEntity = $legalEntity;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(mixed $notifiable): array
    {
        $legalEntity = $this->legalEntity;

        return [
            'party_id' => $this->party->id,
            'party_name' => $this->party->fullName,
            'new_status' => $this->newStatus,
            'legal_entity_name' => $legalEntity?->edr['name'] ?? 'N/A',
            'legal_entity_id' => $legalEntity?->id,
        ];
    }
}
