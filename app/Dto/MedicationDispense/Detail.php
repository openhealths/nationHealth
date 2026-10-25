<?php

declare(strict_types=1);

namespace App\Dto\MedicationDispense;

use App\Dto\Concerns\PreservesEhealthDocumentValues;

final class Detail
{
    use PreservesEhealthDocumentValues;

    public ?string $medication_id;
    public float $medication_qty;
    public float $sell_price;
    public float $sell_amount;
    public float $discount_amount;
}
