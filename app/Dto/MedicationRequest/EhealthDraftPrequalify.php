<?php

declare(strict_types=1);

namespace App\Dto\MedicationRequest;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Livewire\MedicationRequest\MedicationRequestForm;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: MedicationRequestForm::class)]
final class EhealthDraftPrequalify
{
    use PreservesEhealthDocumentValues;

    #[Map(source: 'patientId')]
    public string $person_id;

    #[Map(source: 'medicalProgram')]
    public string $medical_program_id;

    #[Map(source: 'medicalProgram', transform: [[self::class, 'programSources'], new MapCollection(targetClass: EhealthProgram::class)])]
    public array $programs;

    public static function programSources(string $program): array
    {
        return [(object) ['id' => $program]];
    }
}
