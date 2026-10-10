<?php

declare(strict_types=1);

namespace App\Enums;

enum CarePlanRehabilitationCategory: string
{
    case CLASS23 = 'CLASS23';
    case CLASS24 = 'CLASS24';
    case CLASS25 = 'CLASS25';

    public static function requiresReason(?string $code): bool
    {
        return $code !== null && self::tryFrom(strtoupper(preg_replace('/[\s_\-]+/', '', $code) ?? $code)) !== null;
    }
}
