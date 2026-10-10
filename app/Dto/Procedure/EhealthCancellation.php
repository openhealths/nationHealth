<?php

declare(strict_types=1);

namespace App\Dto\Procedure;

use App\Enums\Person\ProcedureStatus;
use App\Mapping\Transforms\FhirCodeableConcept;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Preserve the raw snapshot under the existing cancellation contract. */
#[Map(source: Collection::class)]
final class EhealthCancellation
{
    #[Map(source: '[statusReason]', transform: [self::class, 'reasonValue'])]
    public array $statusReason;
    public function __construct(#[Map(if: false)] private readonly array $snapshot)
    {
    }
    public static function reasonValue(string $value, Collection $source): array
    {
        $concept = new FhirCodeableConcept('eHealth/procedure_status_reasons', includeText: true)($value, $source, null);
        $concept['text'] = $source['statusReasonText'] ?? '';

        return $concept;
    }
    #[Map(source: '[explanatoryLetter?]')]
    public ?string $explanatoryLetter;
    public function toArray(): array
    {
        $document = \App\Core\Arr::toSnakeCase($this->snapshot);
        unset($document['inserted_at'], $document['updated_at'], $document['created_at'], $document['updated_by'], $document['inserted_by']);

        return [...$document, 'status' => ProcedureStatus::ENTERED_IN_ERROR->value, 'status_reason' => $this->statusReason, 'explanatory_letter' => $this->explanatoryLetter];
    }
}
