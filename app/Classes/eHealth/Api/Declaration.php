<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\DeclarationErrorTranslator;
use App\Classes\eHealth\Api\Responses\Collections\DeclarationGet;
use App\Classes\eHealth\Api\Responses\Collections\DeclarationGetMany;
use App\Contracts\ApiErrorTranslator;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use App\Exceptions\EHealth\EHealthConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class Declaration extends Request
{
    protected const string URL = '/api/declarations';

    /**
     * Get shortened details about declarations.
     *
     * @param  array{
     *     employee_id?: string,
     *     legal_entity_id?: string,
     *     declaration_number?: string,
     *     status: string,
     *     reason?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse {
        $this->setValidator($this->validateMany(...));

        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            $query
        );

        return parent::get(self::URL, $mergedQuery);
    }

    /**
     * Receive detailed information about person Declaration by declaration ID.
     *
     * @param  string  $uuid  Request identifier
     * @param  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function get(string $uuid, $query = null): PromiseInterface|EHealthResponse {
        $this->setValidator($this->validateOne(...));

        return parent::get(self::URL . "/$uuid", $query);
    }

    /**
     * Get the translator of the errors the declaration resource returns.
     *
     * @return ApiErrorTranslator
     */
    protected function errorTranslator(): ApiErrorTranslator
    {
        return new DeclarationErrorTranslator();
    }

    /**
     * Validates the response for a list of declarations
     *
     * @param  EHealthResponse  $response  The response from the eHealth API
     * @return DeclarationGetMany The validated and transformed data.
     */
    protected function validateMany(EHealthResponse $response): DeclarationGetMany
    {
        $validator = Validator::make($response->getData(), [
            '*' => 'required|array',
            '*.declaration_number' => 'required|string',
            '*.declaration_request_id' => 'required|uuid',

            '*.division' => 'nullable|array',
            '*.division.id' => 'nullable|uuid',
            '*.division.name' => 'nullable|string',

            '*.employee' => 'required|array',
            '*.employee.employee_type' => 'required|string',
            '*.employee.id' => 'required|uuid',
            '*.employee.position' => 'required|string',

            '*.end_date' => 'required|date',
            '*.id' => 'required|uuid',
            '*.inserted_at' => 'required|date',

            '*.legal_entity' => 'required|array',
            '*.legal_entity.edrpou' => 'required|string',
            '*.legal_entity.id' => 'required|uuid',
            '*.legal_entity.name' => 'required|string',
            '*.legal_entity.short_name' => 'nullable|string',

            '*.person' => 'sometimes|array',
            '*.person.birth_date' => 'nullable|date_format:Y-m-d',
            '*.person.first_name' => 'required|string',
            '*.person.id' => 'required|uuid',
            '*.person.last_name' => 'nullable|string',
            '*.person.second_name' => 'nullable|string',
            '*.person.verification_status' => 'required|string',

            '*.reason' => 'nullable|string',
            '*.reason_description' => 'nullable|string',
            '*.start_date' => 'required|date',
            '*.status' => 'required|string',
            '*.updated_at' => 'required|date'
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'EHealth Declaration validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return new DeclarationGetMany($validator->validated());
    }

    /**
     * Validates the response for a single declaration
     *
     * @param  EHealthResponse  $response  The response from the eHealth API
     * @return DeclarationGet The validated data.
     */
    protected function validateOne(EHealthResponse $response): DeclarationGet
    {
        $validator = Validator::make($response->getData(), [
            'declaration_number' => 'required|string',
            'declaration_request_id' => 'required|uuid',

            'division' => 'nullable|array',
            'division.id' => 'nullable|uuid',
            'division.name' => 'nullable|string',

            'employee' => 'required|array',
            'employee.employee_type' => 'required|string',
            'employee.id' => 'required|uuid',
            'employee.position' => 'required|string',

            'end_date' => 'required|date',
            'id' => 'required|uuid',
            'inserted_at' => 'required|date',

            'legal_entity' => 'required|array',
            'legal_entity.edrpou' => 'required|string',
            'legal_entity.id' => 'required|uuid',
            'legal_entity.name' => 'required|string',
            'legal_entity.short_name' => 'nullable|string',

            'person' => 'required|array',
            'person.birth_country' => 'required|string',
            'person.birth_date' => 'nullable|date_format:Y-m-d',
            'person.birth_settlement' => 'required|string',
            'person.first_name' => 'required|string',
            'person.gender' => 'required|string',
            'person.id' => 'required|uuid',
            'person.last_name' => 'nullable|string',

            'person.confidant_person' => 'nullable|array',
            'person.confidant_person.*.relation_type' => 'nullable|string',
            'person.confidant_person.*.first_name' => 'nullable|string',
            'person.confidant_person.*.last_name' => 'nullable|string',
            'person.confidant_person.*.second_name' => 'nullable|string',
            'person.confidant_person.*.birth_country' => 'nullable|string',
            'person.confidant_person.*.birth_date' => 'nullable|date_format:Y-m-d',
            'person.confidant_person.*.birth_settlement' => 'nullable|string',
            'person.confidant_person.*.gender' => 'nullable|string',
            'person.confidant_person.*.email' => 'nullable|string',
            'person.confidant_person.*.tax_id' => 'nullable|string',
            'person.confidant_person.*.secret' => 'nullable|string',
            'person.confidant_person.*.unzr' => 'nullable|string',
            'person.confidant_person.*.preferred_way_communication' => 'nullable|string',
            'person.confidant_person.*.documents_person' => 'nullable|array',
            'person.confidant_person.*.documents_person.*.type' => 'nullable|string',
            'person.confidant_person.*.documents_person.*.number' => 'nullable|string',
            'person.confidant_person.*.documents_person.*.expiration_date' => 'nullable|date_format:Y-m-d',
            'person.confidant_person.*.documents_person.*.issued_by' => 'nullable|string',
            'person.confidant_person.*.documents_person.*.issued_at' => 'nullable|date_format:Y-m-d',
            'person.confidant_person.*.documents_relationship' => 'nullable|array',
            'person.confidant_person.*.documents_relationship.*.type' => 'nullable|string',
            'person.confidant_person.*.documents_relationship.*.number' => 'nullable|string',
            'person.confidant_person.*.documents_relationship.*.issued_by' => 'nullable|string',
            'person.confidant_person.*.documents_relationship.*.issued_at' => 'nullable|date_format:Y-m-d',
            'person.confidant_person.*.documents_relationship.*.active_to' => 'nullable|date_format:Y-m-d',
            'person.confidant_person.*.phones' => 'nullable|array',
            'person.confidant_person.*.phones.*.type' => 'nullable|string',
            'person.confidant_person.*.phones.*.number' => 'nullable|string',

            'person.emergency_contact' => 'nullable|array',
            'person.emergency_contact.first_name' => 'required_with:person.emergency_contact|string',
            'person.emergency_contact.last_name' => 'nullable|string',
            'person.emergency_contact.second_name' => 'nullable|string',
            'person.emergency_contact.phones' => 'required_with:person.emergency_contact|array',
            'person.emergency_contact.phones.*.type' => 'required_with:person.emergency_contact.phones|string',
            'person.emergency_contact.phones.*.number' => 'required_with:person.emergency_contact.phones|string',

            'person.tax_id' => 'nullable|string',
            'person.phones' => 'nullable|array',
            'person.phones.*.type' => 'required_with:person.phones|string',
            'person.phones.*.number' => 'required_with:person.phones|string',
            'person.second_name' => 'nullable|string',
            'person.verification_status' => 'required|string',

            "reason" => 'nullable|string',
            "reason_description" => 'nullable|string',
            "scope" => 'nullable|string',
            'signed_at' => 'required|date',
            'start_date' => 'required|date',
            'status' => 'required|string',
            'updated_at' => 'required|date'
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'EHealth Declaration validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return new DeclarationGet([
            ...$validator->validated(),
            'authentication_method_current' => $response->getUrgent()['authentication_method_current'] ?? null,
        ]);
    }
}
