<?php

declare(strict_types=1);

namespace App\View\Components\Forms;

use App\Traits\FormTrait;
use Illuminate\View\Component;
use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;

abstract class Addresses extends Component
{
    use FormTrait;

    /**
     * The regions of the address registry are the same for every address on the page, so they are fetched
     * once per request instead of once per rendered address.
     *
     * @var array|null
     */
    private static ?array $fetchedRegions = null;

    public bool $readonly;

    public bool $divisionView = false;

    public array $address = [];

    public ?array $regions = [];

    public array $districts = [];

    public ?array $settlements = [];

    public ?array $streets = [];

    public string $class = '';

    /**
     * Name of the host component property the address is bound to. A dotted path addresses one address of a list, for example 'addresses.1'.
     *
     * @var string
     */
    public string $property = 'address';

    /**
     * Suffix that keeps the element ids unique when a page renders more than one address.
     *
     * @var string
     */
    public string $uid = '';

    /**
     * Suffix that points the suggestion lists at the slot of this address, so that a page holding several
     * of them searches the registry for each one on its own.
     *
     * @var string
     */
    public string $suggestionsSuffix = '';

    /**
     * Create a new component instance.
     */
    public function __construct(
        $address,
        $districts,
        $settlements,
        $streets,
        $class,
        $readonly = false,
        $divisionView = false,
        string $property = 'address'
    ) {
        $this->readonly = $readonly;
        $this->divisionView = $divisionView;

        $this->address = $address;

        $this->property = $property;

        // The default property keeps the ids and the suggestion lists the way they were before an address
        // could be bound elsewhere
        $this->uid = $property === 'address' ? '' : '-' . str_replace('.', '-', $property);

        $this->suggestionsSuffix = str_contains($property, '.') ? strstr($property, '.') : '';

        try {
            $this->regions = self::regions();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when searching for regions');
        }

        $this->districts = $districts;

        $this->settlements = $settlements;

        $this->streets = $streets;

        $this->class = $class;

        $this->dictionaries = dictionary()->basics()->getMultipleFormatted(['SETTLEMENT_TYPE', 'STREET_TYPE'])->toArray();
    }

    /**
     * @return array
     * @throws EHealthException|EHealthConnectionException
     */
    protected static function regions(): array
    {
        return self::$fetchedRegions ??= EHealth::address()->getRegions()->getData();
    }

    abstract public static function getAddressRules(array $address): array;

    abstract public static function getAddressMessages(): array;
}
