<?php

declare(strict_types=1);

namespace App\Enums\MergeRequest;

use App\Traits\EnumUtils;

enum Status: string
{
    use EnumUtils;

    case NEW = 'NEW';
    case APPROVED = 'APPROVED';
    case SIGNED = 'SIGNED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';

    /**
     * Human-readable label for the merge request status.
     *
     * @return string
     */
    public function label(): string
    {
        return __('preperson.statuses.' . $this->name);
    }

    /**
     * Badge CSS class for the merge request status.
     *
     * @return string
     */
    public function color(): string
    {
        return match ($this) {
            self::NEW => 'badge-yellow',
            self::APPROVED, self::SIGNED => 'badge-green',
            self::REJECTED, self::CANCELLED, self::EXPIRED => 'badge-red'
        };
    }
}
