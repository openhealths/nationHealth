<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Service extends Request
{
    protected const string URL = '/api/services';

    /**
     * This web service returns a catalog of services that could be submitted in eHealth. The catalog has a tree data structure.
     * Each node represents group of services (or subgroup), except end-node, that represents services themselves.
     * Maximum nesting level is 4.
     *
     * @param  array{code?: string, name?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-programs/medical-program/get-services-dictionary
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        $this->setValidator(self::validateCatalog(...));

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL, $mergedQuery);
    }
    /** General MIS service dictionary contract, including every nested group and service. */
    public static function validateCatalog(EHealthResponse $response): array
    {
        Validator::make($response->json(), [
            'data' => ['present', 'array'],
            'paging' => ['sometimes', 'array'],
            'paging.page_number' => ['required_with:paging', 'integer', 'min:1'],
            'paging.page_size' => ['required_with:paging', 'integer', 'min:1'],
            'paging.total_entries' => ['required_with:paging', 'integer', 'min:0'],
            'paging.total_pages' => ['required_with:paging', 'integer', 'min:0'],
        ])->validate();
        $data = $response->getData();
        $nodes = array_is_list($data) ? $data : [$data];
        foreach ($nodes as $node) {
            self::validateNode($node, 1);
        }

        return $nodes;
    }

    private static function validateNode(mixed $node, int $depth, bool $service = false): void
    {
        if (!is_array($node) || $depth > 4) {
            throw ValidationException::withMessages(['data' => 'Invalid service catalog tree.']);
        }
        Validator::make($node, [
            'id' => ['required', 'uuid'],
            'name' => ['required', 'string'],
            'code' => ['required', 'string'],
            'is_active' => ['required', 'boolean'],
            'request_allowed' => ['required', 'boolean'],
            'inserted_at' => ['required', 'date'],
            'updated_at' => ['required', 'date'],
            'inserted_by' => ['required', 'uuid'],
            'updated_by' => ['required', 'uuid'],
            'category' => [$service ? 'required' : 'sometimes', 'string'],
            'is_composition' => [$service ? 'required' : 'required_with:category', 'boolean'],
            'groups' => ['sometimes', 'array', 'list'],
            'services' => ['sometimes', 'array', 'list'],
        ])->validate();
        foreach (['groups', 'services'] as $collection) {
            foreach ($node[$collection] ?? [] as $child) {
                self::validateNode($child, $depth + 1, $collection === 'services');
            }
        }
    }

}
