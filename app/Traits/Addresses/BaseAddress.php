<?php

declare(strict_types=1);

namespace App\Traits\Addresses;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;

trait BaseAddress
{
    public const string ADDRESS_CONTEXT_RESIDENCE = 'residence';

    public const string ADDRESS_CONTEXT_RECEPTION = 'reception';

    /**
     * Keyed state storage for suggestion lists per address context.
     * Example keys: 'address', 'receptionAddress'
     */
    protected array $addressesState = [
        self::ADDRESS_CONTEXT_RESIDENCE => [
            'address' => [],
            'districts' => [],
            'settlements' => [],
            'streets' => [],
        ],
        self::ADDRESS_CONTEXT_RECEPTION => [
            'address' => [],
            'districts' => [],
            'settlements' => [],
            'streets' => [],
        ],
    ];

    /**
     * Explicit getter to retrieve internal address state data
     * All non -address keys are delegated to parent
     *
     * @param  string  $property  The property name to set
     * @return mixed
     */
    public function &__get($property): mixed
    {
        if (!$this->isAddressKey($property)) {
            // Here is IMPORTANT to return by local variable!!
            $value = parent::__get($property);

            return $value;
        }

        [$context, $field] = $this->resolveAddressKey($property);

        /**
         * Use reference to expose the actual internal bucket.
         * The leading & makes $ref an alias of addressesState[...] so
         * external mutations affect the original array (required by &__get).
         */
        $ref = &$this->addressesState[$context][$field];

        return $ref;
    }

    /**
     * Explicit setter to update internal address state data
     * All non -address keys are delegated to parent
     *
     * @param  string  $property  The property name to set
     * @param  mixed  $value  The value to assign to the property
     * @return void
     */
    public function __set(string $property, mixed $value): void
    {
        if (!$this->isAddressKey($property)) {
            parent::__set($property, $value);

            return;
        }

        [$context, $field] = $this->resolveAddressKey($property);

        $this->addressesState[$context][$field] = (array)$value;
    }

    /**
     * Check if a given property is an address-related key.
     *
     * This method determines whether the provided property name corresponds
     * to an address field or attribute.
     *
     * @param  string  $property  The property name to check
     * @return bool
     */
    protected function isAddressKey(string $property): bool
    {
        return in_array($property, [
            'address',
            'receptionAddress',
            'districts',
            'settlements',
            'streets',
            'receptionDistricts',
            'receptionSettlements',
            'receptionStreets',
        ], true);
    }

    /**
     * Resolves the address key for a given property.
     *
     * @param  string  $property  The property name to check
     * @return array
     */
    protected function resolveAddressKey(string $property): array
    {
        return match ($property) {
            'address' => [self::ADDRESS_CONTEXT_RESIDENCE, 'address'],
            'districts' => [self::ADDRESS_CONTEXT_RESIDENCE, 'districts'],
            'settlements' => [self::ADDRESS_CONTEXT_RESIDENCE, 'settlements'],
            'streets' => [self::ADDRESS_CONTEXT_RESIDENCE, 'streets'],

            'receptionAddress' => [self::ADDRESS_CONTEXT_RECEPTION, 'address'],
            'receptionDistricts' => [self::ADDRESS_CONTEXT_RECEPTION, 'districts'],
            'receptionSettlements' => [self::ADDRESS_CONTEXT_RECEPTION, 'settlements'],
            'receptionStreets' => [self::ADDRESS_CONTEXT_RECEPTION, 'streets']
        };
    }

    /**
     * Update the address region for the current model (via API call)
     *
     * @param  string  $property  // The property name to update
     * @param  string  $districts
     * @param  string  $value
     * @return void
     */
    public function updateRegion(string $property, string $districts, string $value): void
    {
        $this->{$districts} = [];

        if (mb_strlen($value) >= 2) {
            $this->getDistricts($property, $districts);
        }
    }

    /**
     * Update the address street value (via API call)
     *
     * @param  string  $property  // The property name to update
     * @param  string  $streets
     * @param  string  $value
     * @return void
     */
    public function updateStreet(string $property, string $streets, string $value): void
    {
        $this->{$streets} = [];

        if (mb_strlen($value) >= 2) {
            $this->getStreets($property, $streets);
        }
    }

    /**
     * Update the address settlement value (via API call)
     *
     * @param  string  $property  // The property name to update
     * @param  string  $settlements
     * @param  string  $value
     * @return void
     */
    public function updateSettlement(string $property, string $settlements, string $value): void
    {
        $this->{$settlements} = [];

        if (mb_strlen($value) >= 2) {
            $this->getSettlements($property, $settlements);
        }
    }

    public function getDistricts(string $property, string $districts): void
    {
        $area = $this->{$property}['area'];

        if (empty($area)) {
            return;
        }

        $region = $this->{$property}['region'];

        try {
            $this->{$districts} = EHealth::address()->getDistricts(['region' => $area, 'name' => $region])->getData();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when searching for districts');

            return;
        }
    }

    public function getSettlements(string $property, string $settlements): void
    {
        $region = $this->{$property}['region'];

        if (empty($region)) {
            return;
        }

        $area = $this->{$property}['area'];
        $settlement = $this->{$property}['settlement'];

        try {
            $this->{$settlements} = EHealth::address()->getSettlements(
                ['region' => $area, 'district' => $region, 'name' => $settlement]
            )->getData();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when searching for settlements');

            return;
        }
    }

    public function getStreets(string $property, string $streets): void
    {
        $settlementId = $this->{$property}['settlementId'];

        if (empty($settlementId)) {
            return;
        }

        $streetType = $this->{$property}['streetType'];
        $street = $this->{$property}['street'];

        try {
            $this->{$streets} = EHealth::address()->getStreets(
                ['settlement_id' => $settlementId, 'type' => $streetType, 'name' => $street]
            )->getData();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when searching for streets');

            return;
        }
    }
}
