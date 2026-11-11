<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\SchemaResponseContract;
use App\Core\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PatientApiBase extends Request
{
    protected const string URL = '/api/patients';

    /** Validate the complete native contract before retaining all fields and adapting keys. */
    protected function setReadContract(string $operation, bool $mapKeys = true): void
    {
        $this->setValidator(function (EHealthResponse $response) use ($operation, $mapKeys): array {
            $native = SchemaResponseContract::validate($response, $operation);
            if (!str_starts_with($operation, 'GET ') && !str_ends_with($operation, '/prequalify')) {
                $this->validateSubmittedJob($response);
            }

            return $mapKeys ? $this->replaceEHealthPropNames(Arr::toSnakeCase($native)) : $native;
        });
    }

    /**
     * Validate the response to a submitted package, which links to the job processing it.
     *
     * @param  EHealthResponse  $response
     * @return array
     * @throws ValidationException
     */
    protected function validateSubmittedJob(EHealthResponse $response): array
    {
        $data = $response->getData();
        $validator = Validator::make($data, [
            'status' => ['required', 'string', 'in:pending,processing,accepted,queued,processed,completed,success,failed,rejected,errored,invalid'],
            'eta' => ['nullable', 'string'],
            'links' => ['required', 'array', 'min:1'],
            'links.*.entity' => ['required', 'string', 'in:job'],
            'links.*.href' => ['required', 'string', 'regex:#^/?(?:api/)?[Jj][Oo][Bb][Ss]/[A-Za-z0-9_-]+/?$#']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Submitted job validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        $validator->validate();

        return $data;
    }

    /**
     * Replace recursively eHealth property names with the ones used in the application.
     * E.g., id => uuid, inserted_at => ehealth_inserted_at.
     */
    protected function replaceEHealthPropNames(array $properties): array
    {
        $mapping = $this->propertyMapping();
        $replaced = [];

        foreach ($properties as $name => $value) {
            $newName = $mapping[$name] ?? $name;
            $replaced[$newName] = is_array($value) ? $this->replaceEHealthPropNames($value) : $value;
        }

        return $replaced;
    }

    protected function propertyMapping(): array
    {
        return [
            'id' => 'uuid',
            'inserted_at' => 'ehealth_inserted_at',
            'inserted_by' => 'ehealth_inserted_by',
            'updated_at' => 'ehealth_updated_at',
            'updated_by' => 'ehealth_updated_by'
        ];
    }
}
