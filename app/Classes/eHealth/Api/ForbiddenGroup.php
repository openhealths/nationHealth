<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;

class ForbiddenGroup extends EHealthRequest
{
    protected const string URL = '/api/forbidden_groups';

    /**
     * Receives a catalog of all active forbidden groups.
     *
     * @param  array{name?: string, page?: int, page_size?: int}  $filters
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-forbidden-groups/get-forbidden-group-list/get-forbidden-group-list
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

    /**
     * Returns forbidden group details filtered by ID with active codes and active services.
     *
     * @param  string  $uuid
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-forbidden-groups/get-forbidden-group-details/get-forbidden-group-details
     */
    public function getDetails(string $uuid): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . '/' . $uuid);
    }
}
