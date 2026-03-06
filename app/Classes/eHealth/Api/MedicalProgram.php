<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;

class MedicalProgram extends EHealthRequest
{
    public const string URL = '/api/medical_programs';

    /**
     * Receives a list of medical programs.
     *
     * @param  array{id?: string, name?: string,is_active?: bool,mr_blank_type?: string,type?: string, page?: int, page_size?: int}  $filters
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(array $filters = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            $filters
        );

        return $this->get(self::URL, $mergedQuery);
    }
}
