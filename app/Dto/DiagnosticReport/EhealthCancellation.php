<?php

declare(strict_types=1);

namespace App\Dto\DiagnosticReport;

use App\Core\Arr;
use App\Enums\Person\DiagnosticReportStatus;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class EhealthCancellation
{
    #[Map(source: '[cancellationReason]', transform: [self::class, 'reasonValue'])]
    public array $cancellationReason;

    #[Map(source: '[explanatoryLetter?]')]
    public mixed $explanatoryLetter;

    #[Map(source: '[observations?]', transform: [[self::class, 'observationRows'], new MapCollection(targetClass: \App\Dto\Observation\EhealthCancellation::class)])]
    public array $observations;

    public function __construct(#[Map(if: false)] private readonly array $snapshot)
    {
    }

    public static function reasonValue(string $value, Collection $source): array
    {
        $concept = new FhirCodeableConcept('eHealth/cancellation_reasons', includeText: true)($value, $source, null);
        $concept['text'] = $source['cancellationReasonText'] ?? '';

        return $concept;
    }

    public static function observationRows(?array $value, Collection $source): array
    {
        return array_map(static fn (array $row): Collection => new Collection(['document' => $row, 'explanatoryLetter' => $source['explanatoryLetter'] ?? null]), array_values($value ?? []));
    }

    public function toArray(): array
    {
        $document = Arr::toSnakeCase($this->snapshot);
        unset($document['inserted_at'],$document['updated_at'],$document['created_at'],$document['updated_by'],$document['inserted_by']);
        $equipment = collect($document['used_references'] ?? [])
            ->map(static fn (array $row): mixed => data_get($row, 'identifier.value') ?? data_get($row, 'value'))
            ->filter()
            ->unique()
            ->map(static fn (string $uuid): array => new FhirReference('equipment', includeText: true)($uuid, new Collection(), null))
            ->values()
            ->all();
        if ($equipment !== []) {
            $document['used_references'] = $equipment;
        } else {
            unset($document['used_references']);
        }
        $document['status'] = DiagnosticReportStatus::ENTERED_IN_ERROR->value;
        $document['cancellation_reason'] = $this->cancellationReason;
        $document['explanatory_letter'] = $this->explanatoryLetter;

        return ['diagnostic_report' => $document, 'observations' => array_map(static fn (object $row): array => $row->toArray(), $this->observations)];
    }
}
