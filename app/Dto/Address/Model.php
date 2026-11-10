<?php

declare(strict_types=1);

namespace App\Dto\Address;

use App\Classes\eHealth\Api\Responses\Collections\AddressCreate;
use App\Contracts\Dto\Model as ModelContract;
use App\Dto\Input\FormCollection;
use App\Models\Relations\Address as AddressModel;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

#[Map(source: AddressCreate::class)]
#[Map(source: FormCollection::class)]
class Model implements ModelContract
{
    #[Map(source: '[type]')]
    public ?string $type = null;

    #[Map(source: '[country]')]
    public ?string $country = null;

    #[Map(source: '[area]')]
    public ?string $area = null;

    #[Map(source: '[region]')]
    public ?string $region = null;

    #[Map(source: '[settlement]')]
    public ?string $settlement = null;

    #[Map(source: '[settlement_id]', if: new SourceClass(AddressCreate::class))]
    #[Map(source: '[settlementId]', if: new SourceClass(FormCollection::class))]
    public ?string $settlementId = null;

    #[Map(source: '[settlement_type]', if: new SourceClass(AddressCreate::class))]
    #[Map(source: '[settlementType]', if: new SourceClass(FormCollection::class))]
    public ?string $settlementType = null;

    #[Map(source: '[street_type]', if: new SourceClass(AddressCreate::class))]
    #[Map(source: '[streetType]', if: new SourceClass(FormCollection::class))]
    public ?string $streetType = null;

    #[Map(source: '[street]')]
    public ?string $street = null;

    #[Map(source: '[building]')]
    public ?string $building = null;

    #[Map(source: '[apartment]')]
    public ?string $apartment = null;

    #[Map(source: '[zip]')]
    public ?string $zip = null;

    public function toModel(): AddressModel
    {
        return new AddressModel($this->toArray());
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'country' => $this->country,
            'area' => $this->area,
            'region' => $this->region,
            'settlement' => $this->settlement,
            'settlement_id' => $this->settlementId,
            'settlement_type' => $this->settlementType,
            'street_type' => $this->streetType,
            'street' => $this->street,
            'building' => $this->building,
            'apartment' => $this->apartment,
            'zip' => $this->zip,
        ];
    }
}
