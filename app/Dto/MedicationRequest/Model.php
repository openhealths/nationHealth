<?php

declare(strict_types=1);

namespace App\Dto\MedicationRequest;

use App\Mapping\Transforms\FallbackValue;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use stdClass;
use App\Dto\FormCollection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

/** Local draft/model fields and partial remote metadata; raw documents and clinical links stay outside mapping. */
#[Map(source: FormCollection::class, if: new SourceClass(FormCollection::class))]
#[Map(source: stdClass::class, if: new SourceClass(stdClass::class))]
#[Map(source: MedicationRequestRequest::class, if: new SourceClass(MedicationRequestRequest::class))]
final class Model
{
    #[Map(source: 'status?', if: new SourceClass(stdClass::class))]
    public ?string $status = null;

    #[Map(source: 'request_number?', if: new SourceClass(stdClass::class), transform: new FallbackValue('requisition'))]
    public ?string $request_number = null;

    #[Map(source: 'started_at?', if: new SourceClass(stdClass::class))]
    #[Map(source: '[started_at?]', if: new SourceClass(MedicationRequestRequest::class), transform: [Ehealth::class, 'mapDate'])]
    #[Map(source: '[started_at?]', if: new SourceClass(FormCollection::class))]
    public ?string $started_at = null;

    #[Map(source: 'ended_at?', if: new SourceClass(stdClass::class))]
    #[Map(source: '[ended_at?]', if: new SourceClass(MedicationRequestRequest::class), transform: [Ehealth::class, 'mapDate'])]
    #[Map(source: '[ended_at?]', if: new SourceClass(FormCollection::class))]
    public ?string $ended_at = null;

    #[Map(source: 'medication_id?', if: new SourceClass(stdClass::class), transform: new FallbackValue('medication_info.id'))]
    #[Map(source: '[medication_id?]', if: new SourceClass(MedicationRequestRequest::class))]
    #[Map(source: '[medication_id?]', if: new SourceClass(FormCollection::class))]
    public ?string $medication_id = null;

    #[Map(source: 'medication_qty?', if: new SourceClass(stdClass::class))]
    #[Map(source: '[medication_qty?]', if: new SourceClass(MedicationRequestRequest::class), transform: 'floatval')]
    #[Map(source: '[medication_qty?]', if: new SourceClass(FormCollection::class), transform: 'floatval')]
    public int|float|string|null $medication_qty = null;

    #[Map(source: 'medical_program_id?', if: new SourceClass(stdClass::class), transform: new FallbackValue('medical_program.id'))]
    #[Map(source: '[medication_program_id?]', if: new SourceClass(MedicationRequestRequest::class))]
    #[Map(source: '[program_id?]', if: new SourceClass(FormCollection::class))]
    public ?string $medication_program_id = null;

    #[Map(source: '[created_at?]', if: new SourceClass(MedicationRequestRequest::class), transform: [Ehealth::class, 'mapDate'])]
    public ?string $created_at = null;

    #[Map(source: '[intent?][code?]', if: new SourceClass(MedicationRequestRequest::class), transform: [Ehealth::class, 'mapIntent'])]
    #[Map(source: '[intent?]', if: new SourceClass(FormCollection::class))]
    public ?string $intent = null;

    #[Map(source: '[category?][text?]', if: new SourceClass(MedicationRequestRequest::class), transform: [Ehealth::class, 'mapCategory'])]
    #[Map(source: '[category?]', if: new SourceClass(FormCollection::class))]
    public ?string $category = null;

    #[Map(source: '[container_dosage?]', if: new SourceClass(MedicationRequestRequest::class))]
    #[Map(source: '[container_dosage?]', if: new SourceClass(FormCollection::class))]
    public mixed $container_dosage = null;

    #[Map(source: '[note?]', if: new SourceClass(MedicationRequestRequest::class))]
    #[Map(source: '[note?]', if: new SourceClass(FormCollection::class))]
    public ?string $note = null;

    #[Map(source: '[inform_with?]', if: new SourceClass(MedicationRequestRequest::class))]
    #[Map(source: '[inform_with?]', if: new SourceClass(FormCollection::class))]
    public ?string $inform_with = null;

    #[Map(source: '[dosageInstructions]', if: new SourceClass(MedicationRequestRequest::class), transform: new MapCollection(targetClass: DosageModelData::class))]
    #[Map(source: '[dosage_instructions]', if: new SourceClass(FormCollection::class), transform: new MapCollection(targetClass: DosageModelData::class))]
    public ?array $dosage_instructions = null;

    public function toSigningFields(): array
    {
        $data = get_object_vars($this);
        unset($data['status'], $data['request_number']);
        $data['dosage_instructions'] = array_map(static fn (DosageModelData $row): array => get_object_vars($row), $this->dosage_instructions ?? []);

        return $data;
    }

    public function toSyncPatch(): array
    {
        // The existing medication cache contract retains zero and explicit empty strings.
        $fields = array_intersect_key(get_object_vars($this), array_flip([
            'status', 'request_number', 'started_at', 'ended_at', 'medication_id', 'medication_qty', 'medication_program_id',
        ]));

        return array_filter($fields, static fn (mixed $value): bool => $value !== null);
    }
}
