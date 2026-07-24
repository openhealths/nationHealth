<?php

declare(strict_types=1);

namespace App\Enums\Contract;

use App\Traits\EnumUtils;

enum Type: string
{
    use EnumUtils;

    case REIMBURSEMENT = 'REIMBURSEMENT';
    case CAPITATION = 'CAPITATION';

    public function label(): string
    {
        return match ($this) {
            self::REIMBURSEMENT => __('contracts.reimbursement'),
            self::CAPITATION => __('contracts.capitation'),
        };
    }

    public static function resolveLabel(mixed $type): string
    {
        if ($type instanceof self) {
            return $type->label();
        }

        $value = strtoupper((string) (is_object($type) && property_exists($type, 'value')
            ? $type->value
            : $type));

        if ($value === '') {
            return __('contracts.missing');
        }

        return self::tryFrom($value)?->label() ?? $value;
    }
}
