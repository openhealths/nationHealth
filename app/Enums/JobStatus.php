<?php

declare(strict_types=1);

namespace App\Enums;

use App\Traits\EnumUtils;

enum JobStatus: string
{
    use EnumUtils;

    case PENDING = 'PENDING';
    case SUSPENDED = 'SUSPENDED';
    case PARTIAL = 'PARTIAL';
    case PROCESSING = 'PROCESSING';
    case PROCESSED = 'PROCESSED';
    case PAUSED = 'PAUSED';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Очікується',
            self::SUSPENDED => 'Призупинено',
            self::PROCESSING => 'Обробляється',
            self::PROCESSED => 'Оброблено',
            self::PARTIAL => 'Частково виконано',
            self::PAUSED => 'На паузі',
            self::COMPLETED => 'Виконано',
            self::FAILED => 'Помилка'
        };
    }
}
