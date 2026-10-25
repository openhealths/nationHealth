<?php

declare(strict_types=1);

namespace App\Dto\MedicationRequest;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Livewire\MedicationRequest\MedicationRequestForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Standalone draft has string dosage and its own transport contract. */
#[Map(source: MedicationRequestForm::class)]
final class EhealthDraft
{
    use PreservesEhealthDocumentValues;

    #[Map(source: 'patientId')]
    public string $person_id;

    #[Map(source: 'medicalProgram')]
    public string $medical_program_id;

    #[Map(source: 'dosageInstruction')]
    public string $dosage_instruction;

    #[Map(source: 'duration', transform: [self::class, 'mapDispenseRequest'])]
    public array $dispense_request;

    public static function mapDispenseRequest(string $duration): array
    {
        return ['expected_supply_duration' => [
            'value' => (int) $duration, 'system' => 'http://unitsofmeasure.org', 'code' => 'd',
        ]];
    }
}
