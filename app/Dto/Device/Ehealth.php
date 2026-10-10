<?php

declare(strict_types=1);

namespace App\Dto\Device;

use App\Dto\Concerns\MapsCollectionRows;
use App\Dto\EhealthMapping;
use App\Dto\FormCollection;
use App\Enums\Device\Status;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

/** Validated encounter row; context and generated IDs belong to the caller. */
#[Map(source: FormCollection::class)]
final class Ehealth
{
    use EhealthMapping;
    use MapsCollectionRows;

    #[Map(if: false)]
    public readonly string $id;

    #[Map(source: '[status?]', transform: [self::class, 'statusValue'])]
    public mixed $status;

    #[Map(source: '[primarySource]')]
    public mixed $primarySource;

    #[Map(source: '[typeCode]', transform: new FhirCodeableConcept('device_definition_classification_type', includeText: true))]
    public array $type;

    /** @var list<Name> */
    #[Map(source: '[names?]', transform: [[self::class, 'collectionRows'], new MapCollection(targetClass: Name::class)])]
    public array $name;

    #[Map(if: false)]
    public readonly array $context;

    #[Map(if: false)]
    public readonly array $recorder;

    /** @var list<EhealthIdentifier> */
    #[Map(source: '[identifiers?]', transform: [[self::class, 'identifierRows'], new MapCollection(targetClass: EhealthIdentifier::class)])]
    public array $identifier;

    /** @var list<EhealthProperty> */
    #[Map(source: '[properties?]', transform: [[self::class, 'collectionRows'], new MapCollection(targetClass: EhealthProperty::class)])]
    public array $property;

    #[Map(source: '[reportOriginCode?]', if: [self::class, 'nonPrimary'], transform: [self::class, 'reportOrigin'])]
    public array $reportOrigin;

    #[Map(source: '[modelNumber?]', if: [self::class, 'filled'])]
    public mixed $modelNumber;

    #[Map(source: '[lotNumber?]', if: [self::class, 'filled'])]
    public mixed $lotNumber;

    #[Map(source: '[manufacturer?]', if: [self::class, 'filled'])]
    public mixed $manufacturer;

    #[Map(source: '[serialNumber?]', if: [self::class, 'filled'])]
    public mixed $serialNumber;

    #[Map(source: '[manufactureDate?]', if: [self::class, 'filled'], transform: 'convertToEHealthISO8601')]
    public string $manufactureDate;

    #[Map(source: '[expirationDate?]', if: [self::class, 'filled'], transform: 'convertToEHealthISO8601')]
    public string $expirationDate;

    #[Map(source: '[note?]', if: [self::class, 'filled'])]
    public mixed $note;

    #[Map(source: '[definitionId?]', if: [self::class, 'filled'], transform: new FhirReference('device_definition', includeText: true))]
    public array $definition;

    #[Map(source: '[parentId?]', if: [self::class, 'filled'], transform: new FhirReference('device', includeText: true))]
    public array $parent;

    public function __construct(string $id, string $encounter, string $recorder)
    {
        $this->id = $id;
        $this->context = new FhirReference('encounter', includeText: true)($encounter, $this, null);
        $this->recorder = new FhirReference('employee', includeText: true)($recorder, $this, null);
    }

    public static function statusValue(mixed $value): mixed
    {
        return $value ?? Status::ACTIVE->value;
    }

    public static function identifierRows(?array $rows): array
    {
        return self::collectionRows(array_filter($rows ?? [], static fn (array $row): bool => !empty($row['value'])));
    }

    public static function filled(mixed $value): bool
    {
        return !empty($value);
    }

    public static function nonPrimary(mixed $value, FormCollection $source): bool
    {
        return !$source['primarySource'];
    }

    public static function reportOrigin(string $value, FormCollection $source): array
    {
        return new FhirCodeableConcept('eHealth/report_origins')($value, $source, null) + ['text' => $source['reportOriginText'] ?? ''];
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        $data['primary_source'] = $this->primarySource;
        // Keep explicit null name fields and apply each nested clinical DTO's wire rules.
        $data['name'] = array_map(get_object_vars(...), $this->name);
        $data['identifier'] = array_map(static fn (EhealthIdentifier $row): array => $row->toArray(), $this->identifier);
        $data['property'] = array_map(static fn (EhealthProperty $row): array => $row->toArray(), $this->property);
        foreach (['identifier', 'property'] as $field) {
            if ($data[$field] === []) {
                unset($data[$field]);
            }
        }
        $order = array_flip([
            'id', 'status', 'primary_source', 'type', 'name', 'context', 'recorder', 'identifier', 'property',
            'report_origin', 'model_number', 'lot_number', 'manufacturer', 'serial_number', 'manufacture_date',
            'expiration_date', 'note', 'definition', 'parent',
        ]);

        return array_replace(array_intersect_key($order, $data), $data);
    }
}
