<?php

declare(strict_types=1);

namespace App\Enums\Declaration;

use App\Traits\EnumUtils;

/**
 * See: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18426003727/declaration_request_statuses
 */
enum RequestStatus: string
{
    use EnumUtils;

    case DRAFT = 'DRAFT';
    case NEW = 'NEW';
    case APPROVED = 'APPROVED';
    case SIGNED = 'SIGNED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('patients.status.draft'),
            self::NEW => __('patients.status.new'),
            self::APPROVED => __('patients.status.approved'),
            self::SIGNED => __('patients.status.signed'),
            self::CANCELLED => __('patients.status.cancelled'),
            self::EXPIRED => __('patients.status.expired'),
            self::REJECTED => __('patients.status.rejected')
        };
    }

    /**
     * Badge CSS class representing the status color.
     *
     * @return string
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'badge-dark',
            self::NEW, self::APPROVED => 'badge-yellow',
            self::SIGNED => 'badge-green',
            self::CANCELLED, self::EXPIRED, self::REJECTED => 'badge-red'
        };
    }
}
