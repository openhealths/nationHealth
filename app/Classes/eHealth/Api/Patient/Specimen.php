<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\MedicalEventsErrorTranslator;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Contracts\ApiErrorTranslator;
use App\Enums\Specimen\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Specimen extends PatientApiBase
{
    /**
     * Get specimens of the patient by search params.
     *
     * @param  string  $patientId
     * @param array{
     *     status?: string,
     *     type?: string,
     *     registered_by?: string,
     *     collected_from?: string,
     *     collected_to?: string,
     *     container_identifier?: string,
     *     container_type?: string,
     *     parent?: string,
     *     request?: string,
     *     encounter?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/get-specimen-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSpecimens(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, ['collected_from', 'collected_to'])
        );

        return $this->get(self::URL . "/$patientId/specimens", $mergedQuery);
    }

    /**
     * Get details of the patient specimen by its ID.
     *
     * @param  string  $patientId
     * @param  string  $specimenId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/get-specimen-details
     */
    public function getDetails(string $patientId, string $specimenId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSpecimen(...));

        return $this->get(self::URL . "/$patientId/specimens/$specimenId");
    }

    /**
     * Get a specimen by its human-readable accession identifier.
     *
     * @param  string  $accessionIdentifier
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/get-specimen-by-accession-identifier
     */
    public function getByAccessionIdentifier(string $accessionIdentifier): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSpecimenWithIdentity(...));

        return $this->get("/api/specimens/$accessionIdentifier");
    }

    /**
     * Create a specimen of the patient outside the encounter package with signed content. Processed asynchronously.
     *
     * @param  string  $patientId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/create-specimen
     */
    public function create(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/$patientId/specimens", $payload);
    }

    /**
     * Set the time the patient specimen was received for processing. Processed asynchronously.
     *
     * @param  string  $patientId
     * @param  string  $specimenId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/process-specimen
     */
    public function process(string $patientId, string $specimenId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/$patientId/specimens/$specimenId/actions/process", $payload);
    }

    /**
     * Mark the patient specimen as rejected. Processed asynchronously.
     *
     * @param  string  $patientId
     * @param  string  $specimenId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/reject-specimen
     */
    public function reject(string $patientId, string $specimenId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/$patientId/specimens/$specimenId/actions/reject", $payload);
    }

    /**
     * Mark the patient specimen as unavailable. Processed asynchronously.
     *
     * @param  string  $patientId
     * @param  string  $specimenId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/invalidate-specimen
     */
    public function invalidate(string $patientId, string $specimenId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/$patientId/specimens/$specimenId/actions/invalidate", $payload);
    }

    /**
     * Mark the patient specimen as entered in error with a signed package. Processed asynchronously.
     *
     * @param  string  $patientId
     * @param  string  $specimenId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/specimen/cancel-specimen
     */
    public function cancel(string $patientId, string $specimenId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/$patientId/specimens/$specimenId/actions/cancel", $payload);
    }

    /**
     * Get the translator of the errors the medical events resources return.
     *
     * @return ApiErrorTranslator
     */
    protected function errorTranslator(): ApiErrorTranslator
    {
        return new MedicalEventsErrorTranslator();
    }

    /**
     * Validate a single specimen.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateSpecimen(EHealthResponse $response): array
    {
        return $this->runSpecimenValidation($response, $this->specimenValidationRules());
    }

    /**
     * Validate a single specimen together with the identity of its subject.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateSpecimenWithIdentity(EHealthResponse $response): array
    {
        return $this->runSpecimenValidation($response, [
            ...$this->specimenValidationRules(),
            'identity' => ['required', 'array'],
            'identity.gender' => ['required', 'string'],
            'identity.age' => ['required', 'integer']
        ]);
    }

    /**
     * Apply the given rules to a single specimen from the eHealth API response.
     *
     * @param  EHealthResponse  $response
     * @param  array  $rules
     * @return array
     */
    private function runSpecimenValidation(EHealthResponse $response, array $rules): array
    {
        $validator = Validator::make($this->replaceEHealthPropNames($response->getData()), $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Specimen validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * Validate specimens collection from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateSpecimens(EHealthResponse $response): array
    {
        $data = $response->getData();

        if (empty($data)) {
            return [];
        }

        $replaced = [];

        foreach ($data as $item) {
            $replaced[] = $this->replaceEHealthPropNames($item);
        }

        $rules = collect($this->specimenValidationRules())
            ->mapWithKeys(static fn (array $rule, string $key): array => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replaced, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Specimen validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * List of validation rules for specimens from eHealth.
     *
     * @return array
     */
    protected function specimenValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            // Basic fields
            [
                'uuid' => ['required', 'uuid'],
                'accession_identifier' => ['nullable', 'string', 'max:255'],
                'status' => ['required', Rule::in(Status::values())],
                'note' => ['nullable', 'string'],
                'received_time' => ['nullable', 'date'],
                'explanatory_letter' => ['nullable', 'string', 'max:255'],
                'ehealth_inserted_at' => ['required', 'date'],
                'ehealth_inserted_by' => ['required', 'uuid'],
                'ehealth_updated_at' => ['required', 'date'],
                'ehealth_updated_by' => ['required', 'uuid']
            ],

            // Identifier relationships
            ValidationRuleBuilder::identifierRules('subject', true),
            ValidationRuleBuilder::identifierRules('managing_organization', true),
            ValidationRuleBuilder::identifierRules('registered_by', true),
            ValidationRuleBuilder::identifierRules('context'),
            ValidationRuleBuilder::identifierCollectionRules('parent'),
            ValidationRuleBuilder::identifierCollectionRules('request'),

            // Codeable concept relationships
            ValidationRuleBuilder::codeableConceptRules('type', true),
            ValidationRuleBuilder::codeableConceptRules('condition'),
            ValidationRuleBuilder::codeableConceptRules('status_reason'),

            // Containers the specimen is kept in
            [
                'container' => ['required', 'array'],
                'container.*.identifier' => ['required', 'string', 'max:255'],
                'container.*.description' => ['nullable', 'string', 'max:255']
            ],
            ValidationRuleBuilder::codeableConceptRules('container.*.type'),
            ValidationRuleBuilder::codeableConceptRules('container.*.additive_codeable_concept'),
            $this->quantityRules('container.*.capacity'),
            $this->quantityRules('container.*.specimen_quantity'),

            // Collection details, carrying either a moment or a period
            [
                'collection' => ['required', 'array'],
                'collection.collected_date_time' => ['nullable', 'date']
            ],
            ValidationRuleBuilder::periodRules('collection.collected_period'),
            ValidationRuleBuilder::identifierRules('collection.collector', true),
            ValidationRuleBuilder::identifierRules('collection.procedure'),
            ValidationRuleBuilder::codeableConceptRules('collection.method'),
            ValidationRuleBuilder::codeableConceptRules('collection.body_site'),
            ValidationRuleBuilder::codeableConceptRules('collection.fasting_status_codeable_concept'),
            $this->quantityRules('collection.duration'),
            $this->quantityRules('collection.quantity')
        );
    }

    /**
     * Generate validation rules for a quantity held by a specimen.
     *
     * @param  string  $field
     * @return array
     */
    private function quantityRules(string $field): array
    {
        return [
            $field => ['nullable', 'array'],
            "$field.value" => ["required_with:$field", 'numeric'],
            "$field.comparator" => ['nullable', 'string', 'max:255'],
            "$field.unit" => ["required_with:$field", 'string', 'max:255'],
            "$field.system" => ['nullable', 'string', 'max:255'],
            "$field.code" => ['nullable', 'string', 'max:255']
        ];
    }
}
