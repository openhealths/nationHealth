<?php

declare(strict_types=1);

namespace App\Dto\Specimen;

use App\Enums\Specimen\Status;
use App\Livewire\Specimen\Forms\SpecimenCancellationForm;
use App\Mapping\Transforms\FhirCodeableConcept;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

/** Sign the exact raw remote snapshot, changing only status and status_reason. */
#[Map(source: Collection::class, if: new SourceClass(Collection::class))]
#[Map(source: SpecimenCancellationForm::class, if: new SourceClass(SpecimenCancellationForm::class))]
final class EhealthCancellation
{
    #[Map(source: '[cancellationReason]', if: new SourceClass(Collection::class), transform: new FhirCodeableConcept('specimen_cancel_reasons', includeText: true))]
    #[Map(source: 'cancellationReason', if: new SourceClass(SpecimenCancellationForm::class), transform: new FhirCodeableConcept('specimen_cancel_reasons', includeText: true))]
    public array $statusReason;

    public function __construct(#[Map(if: false)] private readonly array $snapshot)
    {
    }

    public function toArray(): array
    {
        return [...$this->snapshot, 'status' => Status::ENTERED_IN_ERROR->value, 'status_reason' => $this->statusReason];
    }
}
