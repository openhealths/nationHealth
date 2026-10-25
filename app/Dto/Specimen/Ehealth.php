<?php

declare(strict_types=1);

namespace App\Dto\Specimen;

use App\Dto\Concerns\MapsCollectionRows;
use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use App\Enums\Specimen\Status;
use App\Livewire\Specimen\Forms\SpecimenForm;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use App\Mapping\Transforms\MapObject;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

/** Mapping sources are validated rows or the validated standalone form; context belongs to callers. */
#[Map(source: FormCollection::class, if: new SourceClass(FormCollection::class))]
#[Map(source: SpecimenForm::class, if: new SourceClass(SpecimenForm::class))]
final class Ehealth
{
    use MapsCollectionRows;
    use PreservesEhealthDocumentValues;

    #[Map(if: false)]
    public readonly string $id;

    #[Map(source: '[isReferenced?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'statusValue'])]
    #[Map(source: 'specimen[isReferenced?]', if: new SourceClass(SpecimenForm::class), transform: [self::class, 'statusValue'])]
    public string $status;

    #[Map(source: '[typeCode]', if: new SourceClass(FormCollection::class), transform: new FhirCodeableConcept('specimen_types', includeText: true))]
    #[Map(source: 'specimen[typeCode]', if: new SourceClass(SpecimenForm::class), transform: new FhirCodeableConcept('specimen_types', includeText: true))]
    public array $type;

    #[Map(if: false)]
    public readonly array $managingOrganization;

    #[Map(if: false)]
    public readonly array $registeredBy;

    #[Map(source: '[typeCode]', if: new SourceClass(FormCollection::class), transform: [[self::class, 'collectionSource'], new MapObject(EhealthCollection::class)])]
    #[Map(source: 'specimen[typeCode]', if: new SourceClass(SpecimenForm::class), transform: [[self::class, 'collectionSource'], new MapObject(EhealthCollection::class)])]
    public EhealthCollection $collection;

    /** @var list<EhealthContainer> */
    #[Map(source: '[containers]', if: new SourceClass(FormCollection::class), transform: [[self::class, 'collectionRows'], new MapCollection(targetClass: EhealthContainer::class)])]
    #[Map(source: 'specimen[containers]', if: new SourceClass(SpecimenForm::class), transform: [[self::class, 'collectionRows'], new MapCollection(targetClass: EhealthContainer::class)])]
    public array $container;

    #[Map(if: false)]
    public ?array $context = null;

    #[Map(source: '[isReferenced?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'statusReasonValue'])]
    #[Map(source: 'specimen[isReferenced?]', if: new SourceClass(SpecimenForm::class), transform: [self::class, 'statusReasonValue'])]
    public ?array $statusReason;

    #[Map(source: '[receivedDate?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'receivedTimeValue'])]
    #[Map(source: 'specimen[receivedDate?]', if: new SourceClass(SpecimenForm::class), transform: [self::class, 'receivedTimeValue'])]
    public ?string $receivedTime;

    #[Map(source: '[conditionCode?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'conditionValue'])]
    #[Map(source: 'specimen[conditionCode?]', if: new SourceClass(SpecimenForm::class), transform: [self::class, 'conditionValue'])]
    public ?array $condition;

    /** @var list<EhealthParent> */
    #[Map(source: '[parentIds?]', if: new SourceClass(FormCollection::class), transform: [[self::class, 'parentRows'], new MapCollection(targetClass: EhealthParent::class)])]
    #[Map(source: 'specimen[parentIds?]', if: new SourceClass(SpecimenForm::class), transform: [[self::class, 'parentRows'], new MapCollection(targetClass: EhealthParent::class)])]
    public array $parent;

    #[Map(source: '[note?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'optionalValue'])]
    #[Map(source: 'specimen[note?]', if: new SourceClass(SpecimenForm::class), transform: [self::class, 'optionalValue'])]
    public mixed $note;

    public function __construct(string $id, string $legalEntity, string $employee, ?string $encounter = null)
    {
        $this->id = $id;
        $this->managingOrganization = new FhirReference('legal_entity', includeText: true)($legalEntity, $this, null);
        $this->registeredBy = new FhirReference('employee', includeText: true)($employee, $this, null);
        if (!empty($encounter)) {
            $this->context = new FhirReference('encounter', includeText: true)($encounter, $this, null);
        }
    }

    public static function statusValue(mixed $value): string
    {
        return $value ? Status::UNAVAILABLE->value : Status::AVAILABLE->value;
    }

    public static function row(FormCollection|SpecimenForm $source): array
    {
        return $source instanceof SpecimenForm ? $source->specimen : $source->all();
    }

    public static function collectionSource(mixed $value, FormCollection|SpecimenForm $source): Collection
    {
        return new Collection(self::row($source));
    }

    public static function statusReasonValue(mixed $value, object $source): ?array
    {
        return $value ? new FhirCodeableConcept('specimen_invalidate_reasons', includeText: true)('used', $source, null) : null;
    }

    public static function receivedTimeValue(mixed $value, FormCollection|SpecimenForm $source): ?string
    {
        $row = self::row($source);

        return !empty($row['isReferenced']) && !empty($value) && !empty($row['receivedTime']) ? convertToEHealthISO8601($value.' '.$row['receivedTime']) : null;
    }

    public static function conditionValue(mixed $value, object $source): ?array
    {
        return !empty($value) ? new FhirCodeableConcept('specimen_conditions', includeText: true)($value, $source, null) : null;
    }

    public static function parentRows(?array $values): array
    {
        return array_map(static fn (string $uuid): Collection => new Collection(['uuid' => $uuid]), array_values(array_filter($values ?? [])));
    }

    public static function optionalValue(mixed $value): mixed
    {
        return !empty($value) ? $value : null;
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        $data['container'] = array_map(static fn (EhealthContainer $row): array => $row->toArray(), $this->container);
        if ($this->parent === []) {
            unset($data['parent']);
        }

        return $data;
    }
}
