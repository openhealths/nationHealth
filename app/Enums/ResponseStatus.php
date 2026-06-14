<?php

declare(strict_types=1);

namespace App\Enums;

use App\Traits\EnumUtils;

enum ResponseStatus: string
{
    use EnumUtils;

    case SYNC = 'SYNC';
    case ASYNC = 'ASYNC';
    case SUCCESS = 'SUCCESS';
    case NOT_FOUND = 'NOT_FOUND';
   
    public function label(): string
    {
        return match($this) {
            self::SYNC => 'Синхронно',
            self::ASYNC => 'Асинхронно',
            self::SUCCESS => 'Успішно',
            self::NOT_FOUND => 'Не знайдено',
        };
    }

    public static function only(array $names): array
    {
        return collect(self::cases())
            ->filter(fn ($case) => in_array($case->name, $names, true))
            ->map(fn ($case) => $case->value)
            ->values()
            ->all();
    }
}
