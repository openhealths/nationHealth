<?php

declare(strict_types=1);

namespace App\Enums\MedicalEvents;

/** Only the stored states used by activity quantity queries; clinical lifecycle enums remain separate. */
enum RequestQuantityStatus: string
{
    case DRAFT = 'draft';
    case NEW = 'new';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';
    case DECLINED = 'declined';
    case ENTERED_IN_ERROR = 'entered-in-error';
    case EXPIRED = 'expired';

    public static function excluded(bool $reserveDrafts = false): array
    {
        $values = array_map(static fn (self $status): string => $status->value, self::cases());
        // Keep both legacy spellings and letter cases used in the SQL records.
        array_splice($values, 6, 0, ['entered_in_error']);
        if ($reserveDrafts) {
            $values = array_values(array_filter($values, static fn (string $value): bool => !in_array($value, ['draft', 'new'], true)));
        }

        return [...$values, ...array_map('strtoupper', $values)];
    }
}
