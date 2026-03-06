<?php

declare(strict_types=1);

namespace App\View\Components\Forms;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Traits\FormTrait;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Session;
use Illuminate\View\Component;

abstract class Addresses extends Component
{
    use FormTrait;

    public bool $readonly;

    public array $address = [];

    public ?array $regions = [];

    public array $districts = [];

    public ?array $settlements = [];

    public ?array $streets = [];

    public string $class = '';

    /**
     * Create a new component instance.
     */
    public function __construct($address, $districts, $settlements, $streets, $class, $readonly = false)
    {
        $this->readonly = $readonly;

        $this->address = $address;

        try {
            $this->regions = EHealth::address()->getRegions()->getData();
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error when searching for regions');
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, 'Error when searching for regions');

            if ($exception instanceof EHealthValidationException) {
                Session::flash('error', $exception->getFormattedMessage());
            } else {
                Session::flash('error', 'Помилка від ЕСОЗ: ' . $exception->getMessage());
            }

            return;
        }

        $this->districts = $districts;

        $this->settlements = $settlements;

        $this->streets = $streets;

        $this->class = $class;

        $this->dictionaries = dictionary()->basics()->getMultipleFormatted(['SETTLEMENT_TYPE', 'STREET_TYPE'])->toArray();
    }

    abstract public static function getAddressRules(array $address): array;

    abstract public static function getAddressMessages(): array;
}
