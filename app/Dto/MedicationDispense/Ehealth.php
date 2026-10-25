<?php

declare(strict_types=1);

namespace App\Dto\MedicationDispense;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Livewire\MedicationRequest\MedicationRequestIndex;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: MedicationRequestIndex::class)]
final class Ehealth
{
    use PreservesEhealthDocumentValues;

    #[Map(if: false)]
    public string $medication_request_id;
    #[Map(if: false)]
    public string $dispensed_at;
    #[Map(if: false)]
    public string $division_id;
    #[Map(if: false)]
    public ?string $medical_program_id;

    #[Map(source: 'medicationQty', transform: [[self::class, 'details'], new MapCollection(targetClass: Detail::class)])]
    public array $dispense_details;

    public function __construct(
        Request $request,
        string $divisionUuid,
        string $dispensedAt,
        #[Map(if: false)] private readonly string $medicationId,
        #[Map(if: false)] private readonly ?float $minimumQuantity = null,
    ) {
        $this->medication_request_id = $request->id;
        $this->dispensed_at = $dispensedAt;
        $this->division_id = $divisionUuid;
        $this->medical_program_id = $request->programId !== '' ? $request->programId : null;
    }

    public static function details(string $value, MedicationRequestIndex $source, self $target): array
    {
        $quantity = (float) $value;
        if ($target->minimumQuantity !== null && $quantity < $target->minimumQuantity) {
            $quantity = $target->minimumQuantity;
        }

        // This screen has always used fixed price defaults, not arbitrary browser form keys.
        return [(object) [
            'medication_id' => $target->medicationId !== '' ? $target->medicationId : null,
            'medication_qty' => $quantity,
            'sell_price' => 10.0,
            'sell_amount' => round(10.0 * $quantity, 2),
            'discount_amount' => 0.0,
        ]];
    }
}
