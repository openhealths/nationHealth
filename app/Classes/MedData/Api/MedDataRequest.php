<?php

declare(strict_types=1);

namespace App\Classes\MedData\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;

/**
 * https://api.medzakupivli.com/api/api.html
 */
class MedDataRequest extends PendingRequest
{
    /**
     * The HTTP request timeout in seconds.
     */
    public const int TIMEOUT = 30;

    /**
     * Configure base URL, timeout and access token for MedData API.
     *
     * @param  Factory|null  $factory
     */
    public function __construct(?Factory $factory = null)
    {
        parent::__construct($factory);

        $this->baseUrl(config('meddata.api.domain'))
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->withQueryParameters(['access_token' => config('meddata.api.token')]);
    }

    /**
     * Get vaccine dictionaries.
     *
     * @return array
     * @throws ConnectionException|RequestException
     */
    public function getSeries(): array
    {
        return $this->get('/vaccination/2.1/series')->throw()->json();
    }

    /**
     * Get unique COVID-19 vaccine numbers of the medical institution.
     *
     * @param  string  $edrpou
     * @param  string|null  $accountingDate
     * @return array
     * @throws ConnectionException|RequestException
     */
    public function getDivision(string $edrpou, ?string $accountingDate = null): array
    {
        return $this->get('/vaccination/2.1/division', removeEmptyKeys([
            'division' => $edrpou,
            'accounting_date' => $accountingDate
        ]))->throw()->json();
    }

    /**
     * Get dictionary by name and version.
     *
     * @param  string  $name
     * @param  int  $version
     * @return array
     * @throws ConnectionException|RequestException
     */
    public function getDictionary(string $name, int $version): array
    {
        return $this->get('/vaccination/2.1/dictionary', [
            'name' => $name,
            'version' => $version
        ])->throw()->json();
    }
}
