<?php

declare(strict_types=1);

namespace App\Dto\Equipment;

use App\Dto\EhealthMapping;

final class ChangeStatus
{
    use EhealthMapping;

    public function __construct(
        public string $status,
        public ?string $errorReason = null,
    ) {
    }
}
