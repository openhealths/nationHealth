<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Dto\Identifier\Model as IdentifierData;

class Reference
{
    public function __construct(public IdentifierData $identifier, public ?string $displayValue)
    {
    }
}
