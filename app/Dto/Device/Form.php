<?php

declare(strict_types=1);

namespace App\Dto\Device;

use App\Dto\Concerns\MapsCollectionRows;
use App\Enums\Device\Status;
use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class Form
{
    use MapsCollectionRows;

    #[Map(source: '[uuid?]')]
    public mixed $uuid;

    #[Map(source: '[status?]', if: new SourceHasPath('status'))]
    public mixed $status = Status::ACTIVE->value;

    #[Map(source: '[type?][coding?][0?][code?]', if: new SourceHasPath('type.coding.0.code'))]
    public mixed $typeCode = '';

    #[Map(source: '[modelNumber?]', if: new SourceHasPath('modelNumber'))]
    public mixed $modelNumber = '';

    #[Map(source: '[lotNumber?]', if: new SourceHasPath('lotNumber'))]
    public mixed $lotNumber = '';

    #[Map(source: '[manufacturer?]', if: new SourceHasPath('manufacturer'))]
    public mixed $manufacturer = '';

    #[Map(source: '[serialNumber?]', if: new SourceHasPath('serialNumber'))]
    public mixed $serialNumber = '';

    #[Map(source: '[manufactureDate?]', transform: 'convertToAppDateFormat')]
    public string $manufactureDate;

    #[Map(source: '[expirationDate?]', transform: 'convertToAppDateFormat')]
    public string $expirationDate;

    #[Map(source: '[note?]', if: new SourceHasPath('note'))]
    public mixed $note = '';

    #[Map(source: '[primarySource?]')]
    public mixed $primarySource;

    #[Map(source: '[reportOrigin?][coding?][0?][code?]', if: new SourceHasPath('reportOrigin.coding.0.code'))]
    public mixed $reportOriginCode = '';

    #[Map(source: '[reportOrigin?][text?]', if: new SourceHasPath('reportOrigin.text'))]
    public mixed $reportOriginText = '';

    /** @var list<FormProperty> */
    #[Map(source: '[properties?]', transform: [[self::class, 'collectionRows'], new MapCollection(targetClass: FormProperty::class)])]
    public array $properties;

    /** @var list<Name> */
    #[Map(source: '[names?]', transform: [[self::class, 'collectionRows'], new MapCollection(targetClass: Name::class)])]
    public array $names;

    #[Map(source: '[definition?][identifier?][value?]', if: new SourceHasPath('definition.identifier.value'))]
    public mixed $definitionId = '';

    #[Map(source: '[parent?][identifier?][value?]', if: new SourceHasPath('parent.identifier.value'))]
    public mixed $parentId = '';

    /** @var list<FormIdentifier> */
    #[Map(source: '[identifiers?]', transform: [[self::class, 'collectionRows'], new MapCollection(targetClass: FormIdentifier::class)])]
    public array $identifiers;

    public function toArray(): array
    {
        $data = get_object_vars($this);
        foreach (['properties', 'names', 'identifiers'] as $field) {
            $data[$field] = array_map(get_object_vars(...), $data[$field]);
        }

        return $data;
    }
}
