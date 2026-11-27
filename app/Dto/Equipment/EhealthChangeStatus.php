<?php

declare(strict_types=1);

namespace App\Dto\Equipment;

use App\Dto\EhealthMapping;
use App\Livewire\Equipment\EquipmentIndex;
use App\Livewire\Equipment\EquipmentView;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: EquipmentIndex::class)]
#[Map(source: EquipmentView::class)]
class EhealthChangeStatus
{
    use EhealthMapping;

    public string $status;

    public ?string $errorReason = null;
}