<?php

declare(strict_types=1);

namespace App\Enums\Composition;

enum CompositionJobStatus: string
{
    case PENDING = 'PENDING';
    case DONE = 'DONE';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return __('compositions.async.status.' . strtolower($this->value));
    }
}
