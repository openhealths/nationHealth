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

    /**
     * UI-only filter key: local drafts share DB status NEW but have no eHealth UUID.
     */
    public const string FILTER_DRAFT = 'DRAFT';

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

    public function color(): string
    {
        return match ($this) {
            self::NEW, self::SIGNED => 'badge-yellow',
            self::APPROVED => 'badge-green',
            self::REJECTED, self::EXPIRED => 'badge-red',
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
     * Status filter options for employee-request index.
     * DRAFT and NEW are split in UI even though both use DB status NEW.
     *
     * @return array<string, string>
     */
    public static function filterChoices(): array
    {
        return [
            self::FILTER_DRAFT => __('forms.status.draft'),
            self::NEW->value => __('forms.status.new'),
            self::APPROVED->value => self::APPROVED->label(),
            self::REJECTED->value => self::REJECTED->label(),
            self::EXPIRED->value => self::EXPIRED->label(),
        ];
    }
}
