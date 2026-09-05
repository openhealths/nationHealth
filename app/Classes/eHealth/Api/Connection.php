<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use Exception;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Support\Facades\Validator;
use App\Classes\eHealth\EHealthRequest as Request;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

class Connection extends Request
{
    public const string URL = '/api/clients';

    /**
     * Get the list of clients (legal entities) associated with the configured eHealth connection.
     *
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getClients(): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateClients(...));

        $this->setDefaultPageSize();

        return parent::get(self::URL);
    }

    /**
     * Get client (legal entities) details by UUID.
     *
     * @param  string  $uuid  The unique identifier of the client.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getClientDetails(string $uuid): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateClientDetails(...));

        return parent::get(self::URL . '/' . $uuid);
    }

    /**
     * Get the list of client connections associated with the configured eHealth connection.
     *
     * @return PromiseInterface|EHealthResponse
     *
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getClientConnections(string $uuid): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateClientConnections(...));

        $this->setDefaultPageSize();

        return parent::get(self::URL . '/' . $uuid . '/connections');
    }

    /**
     * validate get Clients input,
     * see: https://ehealthmisapi1.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/manage-client-configuration/get-clients
     */
    protected function validateClients(EHealthResponse $response): array
    {
        if (!$response->successful()) {
            throw new Exception('validateMany: ' . $response->body());
        }

        $replaced = [];

        $clientsList = $response->getData();

        $validationRules = ['*' => 'required|array'];

        foreach ($this->getValidationRules() as $key => $rule) {
            $validationRules["*.{$key}"] = $rule;
        }

        foreach ($clientsList as $data) {
            $replaced[] = self::replaceEHealthPropNames($data);
        }

        $validator = Validator::make($replaced, $validationRules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * Validate single Client response data
     * see; https://ehealthmisapi1.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/manage-client-configuration/get-client-details
     */
    protected function validateClientDetails(EHealthResponse $response): array
    {
        if (!$response->successful()) {
            throw new Exception('validateOne: ' . $response->body());
        }

        $data = $response->getData();

        $replaced = self::replaceEHealthPropNames($data);

        $validator = Validator::make($replaced, $this->getValidationRules());

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * Returns the validation rules array used for validating Client's data.
     *
     * @return array An associative array containing validation rules for Client fields
     */
    protected function getValidationRules(): array
    {
        return [
            'uuid' => 'required|uuid',
            'client_type_uuid' => 'required|uuid',
            'client_type_name' => 'nullable|string',
            'is_blocked' => 'nullable|boolean',
            'block_reason' => 'nullable|string',
            'name' => 'required|string',
            'settings' => 'nullable|array',
            'user_uuid' => 'required|uuid',
            'ehealth_inserted_at' => 'nullable|date',
            'ehealth_updated_at' => 'nullable|date'
        ];
    }



     protected function validateClientConnections(EHealthResponse $response): array
    {
        $data = $response->getData();

        $replaced = self::replaceEHealthPropNames($data);


        $validator = Validator::make($replaced, [
            '*.uuid' => ['required', 'uuid'],
            '*.client_uuid' => ['required', 'uuid'],
            '*.consumer_uuid' => ['required', 'uuid'],
            '*.redirect_uri' => ['required', 'string'],
            '*.secret' => ['nullable', 'string'],
            '*.ehealth_inserted_at' => 'nullable|date',
            '*.ehealth_updated_at' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * Replace eHealth property names with the ones used in the application.
     * E.g., id => uuid.
     */
    protected static function replaceEHealthPropNames(array $properties): array
    {
        $replaced = [];

        foreach ($properties as $name => $value) {
            $newName = match ($name) {
                'id' => 'uuid',
                'user_id' => 'user_uuid',
                'consumer_id' => 'consumer_uuid',
                'client_id' => 'legal_entity_uuid',
                'legal_entity_id' => 'legal_entity_uuid',
                'client_type_id' => 'legal_entity_type_uuid',
                'inserted_at' => 'ehealth_inserted_at',
                'updated_at' => 'ehealth_updated_at',
                default => $name
            };

            $replaced[$newName] = $value;

            // TODO: remove it if future use shows that it is not needed.
            if (is_array($value)) {
                $replaced[$newName] = self::replaceEHealthPropNames($value);
            }
        }

        return $replaced;
    }
}
