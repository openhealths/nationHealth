<?php

declare(strict_types=1);

namespace App\Enums\Employee;

use App\Traits\EnumUtils;

enum RequestStatus: string
{
    use EnumUtils;

    case NEW = 'NEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case SIGNED = 'SIGNED';
    case EXPIRED = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Новий',
            self::APPROVED => 'Підтверджено',
            self::REJECTED => 'Відхилено',
            // Legacy local-only; UI for pending uses isPendingEhealth() → «Новий».
            self::SIGNED => 'Надіслано',
            self::EXPIRED => 'Протермінований',
        };
    }

    /**
     * Statuses that may still need sync against eHealth after login.
     * NEW covers drafts and submitted-but-unresolved requests; SIGNED is legacy.
     */
    public static function getStatusesForSync(): array
    {
        return [
            self::NEW->value,
            self::SIGNED->value,
        ];
    }

    /**
     * Statuses shown in employee-request list filters (SIGNED is legacy, not selectable).
     *
     * @return list<self>
     */
    public static function filterChoices(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => $status !== self::SIGNED
        ));
    }
}
