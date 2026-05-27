<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Core\Arr;
use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
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
     * If true, groups the response by entities associated with the declaration, e.g., Declaration itself, DeclarationRequest, Persons, etc.
     */
    public bool $groupByEntities = false;

    /**
     * Get shortened details about declarations.
     *
     * @param  $query
     * @param  bool  $groupByEntities
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany($query = null, bool $groupByEntities = false): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        $this->groupByEntities = $groupByEntities;

        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            $query ?? []
        );

        return $this->get(self::URL, $mergedQuery);
    }

    /**
     * Receive detailed information about person Declaration by declaration ID.
     *
     * @param  string  $uuid  Request identifier
     * @param  $query  Optional query parameters
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getDeclarationById(string $uuid, $query = null): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateOne(...));

        return parent::get(self::URL . "/$uuid", $query);
    }

    /**
     * Validates the response for a list of declarations
     *
     * @param  EHealthResponse  $response  The response from the eHealth API
     * @return array The validated and transformed data.
     */
    protected function validateMany(EHealthResponse $response): array
    {
        $transformedData = [];
        foreach ($response->getData() as $item) {
            $transformedData[] = self::replaceEHealthPropNames($item);
        }

        $validator = Validator::make($transformedData, [
            '*' => 'required|array',
            '*.declaration_number' => 'required|string',
            '*.declaration_request_uuid' => 'required|uuid',

            '*.division' => 'required|array',
            '*.division.uuid' => 'required|uuid',
            '*.division.name' => 'required|string',

            '*.employee' => 'required|array',
            '*.employee.employee_type' => 'required|string',
            '*.employee.uuid' => 'required|uuid',
            '*.employee.position' => 'required|string',

            "*.end_date" => 'required|date',
            '*.uuid' => 'required|uuid',
            "*.inserted_at" => 'required|date',

            '*.legal_entity' => 'required|array',
            '*.legal_entity.edrpou' => 'required|string',
            '*.legal_entity.uuid' => 'required|uuid',
            '*.legal_entity.name' => 'required|string',
            '*.legal_entity.short_name' => 'nullable|string',

            '*.person' => 'sometimes|array',
            '*.person.birth_date' => 'nullable|date_format:Y-m-d',
            '*.person.first_name' => 'required|string',
            '*.person.uuid' => 'required|uuid',
            '*.person.last_name' => 'required|string',
            '*.person.second_name' => 'nullable|string',
            '*.person.verification_status' => 'required|string',

            "*.reason" => 'nullable|string',
            "*.reason_description" => 'nullable|string',
            '*.start_date' => 'required|date',
            '*.status' => 'required|string',
            '*.updated_at' => 'required|date'
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'EHealth Employee validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        $validated = $validator->validated();

        if (!$this->groupByEntities) {
            return $validated;
        }

        $declarationRequestsUuids = Arr::pluck($validated, 'declaration_request_uuid');
        $personUuids = array_unique(Arr::pluck($validated, 'person.uuid'));

        return [
            'declarations' => $validated,
            'declarationRequestUuids' => $declarationRequestsUuids,
            'personUuids' => $personUuids
        ];
    }

    /**
     * Validates the response for a single declaration
     *
     * @param  EHealthResponse  $response  The response from the eHealth API
     * @return array The validated and transformed data.
     */
    protected function validateOne(EHealthResponse $response): array
    {
        $transformedData = self::replaceEHealthPropNames($response->getData());

        $validator = Validator::make($transformedData, [
            'declaration_number' => 'required|string',
            'declaration_request_uuid' => 'required|uuid',

            'division' => 'required|array',
            'division.uuid' => 'required|uuid',
            'division.name' => 'required|string',

            'employee' => 'required|array',
            'employee.employee_type' => 'required|string',
            'employee.uuid' => 'required|uuid',
            'employee.position' => 'required|string',

            "end_date" => 'required|date',
            'uuid' => 'required|uuid',
            "inserted_at" => 'required|date',

            'legal_entity' => 'required|array',
            'legal_entity.edrpou' => 'required|string',
            'legal_entity.uuid' => 'required|uuid',
            'legal_entity.name' => 'required|string',
            'legal_entity.short_name' => 'nullable|string',

            'person' => 'required|array',
            'person.birth_country' => 'required|string',
            'person.birth_date' => 'nullable|date_format:Y-m-d',
            'person.birth_settlement' => 'required|string',

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
            'person.emergency_contact.last_name' => 'required_with:person.emergency_contact|string',
            'person.emergency_contact.second_name' => 'nullable|string',
            'person.emergency_contact.phones' => 'required_with:person.emergency_contact|array',
            'person.emergency_contact.phones.*.type' => 'required_with:person.emergency_contact.phones|string',
            'person.emergency_contact.phones.*.number' => 'required_with:person.emergency_contact.phones|string',

            'person.first_name' => 'required|string',
            'person.gender' => 'required|string',
            'person.uuid' => 'required|uuid',
            'person.last_name' => 'required|string',
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
                'EHealth Employee validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validated();
    }

    /**
     * Replaces eHealth property names with the ones used in the application (e.g., id -> uuid).
     *
     * @param  array  $properties  Raw properties from a single API item.
     * @return array Properties with application-friendly names.
     */
    protected static function replaceEHealthPropNames(array $properties): array
    {
        $replaced = [];

        foreach ($properties as $name => $value) {
            switch ($name) {
                case 'id':
                    $replaced['uuid'] = $value;
                    break;
                case 'declaration_request_id':
                    $replaced['declaration_request_uuid'] = $value;
                    break;
                case 'division':
                    if (is_array($value)) {
                        $value['uuid'] = $value['id'];
                        unset($value['id']);
                    }
                    $replaced['division'] = $value;
                    break;
                case 'employee':
                    if (is_array($value)) {
                        $value['uuid'] = $value['id'];
                        unset($value['id']);
                    }
                    $replaced['employee'] = $value;
                    break;
                case 'legal_entity':
                    if (is_array($value)) {
                        $value['uuid'] = $value['id'];
                        unset($value['id']);
                    }
                    $replaced['legal_entity'] = $value;
                    break;
                case 'person':
                    $value['uuid'] = $value['id'];
                    unset($value['id']);
                    $replaced['person'] = $value;
                    break;
                default:
                    $replaced[$name] = $value;
                    break;
            }
        }

        return $replaced;
    }
}
