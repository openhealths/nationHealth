<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use App\Core\Arr;
use App\Enums\MedicalEvents\EncounterRecordType;
use App\Enums\Person\EncounterStatus;
use App\Mapping\Transforms\FhirCodeableConcept;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class EhealthCancellation
{
    #[Map(source: '[cancellationReason]', transform: [self::class, 'reasonValue'])]
    public array $cancellationReason;

    #[Map(source: '[explanatoryLetter]')]
    public string $explanatoryLetter;

    #[Map(source: '[explanatoryLetter]', transform: [[self::class, 'sectionRows'], new MapCollection(targetClass: EhealthCancelledSection::class)])]
    public array $sections;

    public function __construct(
        #[Map(if: false)]
        private readonly array $snapshot,
        #[Map(if: false)]
        private readonly ?string $encounterStatus = null,
        #[Map(if: false)]
        private readonly ?array $recordIds = null,
    ) {
    }

    public static function reasonValue(string $value, Collection $source): array
    {
        $concept = new FhirCodeableConcept('eHealth/cancellation_reasons', includeText: true)($value, $source, null);
        $concept['text'] = $source['cancellationReasonText'] ?? '';

        return $concept;
    }

    public static function sectionRows(string $value, Collection $source, self $target): array
    {
        $selected = $target->recordIds;
        $types = $selected === null ? EncounterRecordType::cases() : array_map(EncounterRecordType::from(...), array_keys($selected));
        $rows = [];
        foreach ($types as $type) {
            if ($selected !== null && !$type->canCancelSeparately()) {
                throw new InvalidArgumentException('Conditions are cancelled with the encounter.');
            }
            $records = array_map(static fn (array $record): array => [
                'document' => $record,
                'cancel' => $selected === null || in_array($record['id'], $selected[$type->value], true),
                'statusField' => $type->statusField(),
                'status' => $type->cancelledStatus(),
                'explanatoryLetter' => $value,
            ], $target->snapshot[$type->value] ?? []);
            $rows[] = new Collection(['section' => $type->value, 'records' => $records]);
        }

        return $rows;
    }

    public function toArray(): array
    {
        $package = $this->snapshot;
        $package['encounter'] = [
            ...$package['encounter'],
            'status' => $this->encounterStatus ?? EncounterStatus::ENTERED_IN_ERROR->value,
            'cancellationReason' => $this->cancellationReason,
            'explanatoryLetter' => $this->explanatoryLetter,
        ];
        foreach ($this->sections as $section) {
            $package[$section->section] = $section->toArray();
        }

        return Arr::toSnakeCase(array_filter($package));
    }
}
