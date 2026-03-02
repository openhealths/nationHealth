<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;

class MedicalProgram extends EHealthRequest
{
    public const string URL = '/api/medical_programs';

    /**
     * Receives a list of medical programs.
     * You need this method to select the ID for the contract.
     *
     * @param  array  $filters
     * @param  int  $page
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException
     */
    public function getMany(array $filters = [], int $page = 1): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            $filters,
            ['page' => $page]
        );

        return $this->get(self::URL, $mergedQuery);
    }
}
