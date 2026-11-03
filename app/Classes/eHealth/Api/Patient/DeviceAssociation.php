<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\MedicalEventsErrorTranslator;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Contracts\ApiErrorTranslator;
use App\Enums\DeviceAssociation\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeviceAssociation extends PatientApiBase
{
    /**
     * Get device associations by search params.
     *
     * @param  string  $patientId
     * @param array{
     *     device_id?: string,
     *     body_site?: string,
     *     encounter_id?: string,
     *     episode_id?: string,
     *     status?: string,
     *     recorder?: string,
     *     recorder_legal_entity_id?: string,
     *     association_date_from?: string,
     *     association_date_to?: string,
     *     recorded_from?: string,
     *     recorded_to?: string,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/device-association/get-device-associations-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDeviceAssociations(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, [
                'association_date_from',
                'association_date_to',
                'recorded_from',
                'recorded_to',
                'inserted_at_from',
                'inserted_at_to'
            ])
        );

        return $this->get(self::URL . "/$patientId/device_associations", $mergedQuery);
    }

    /**
     * Return detail data by ID.
     *
     * @param  string  $patientId
     * @param  string  $deviceAssociationId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/device-association/get-device-association-by-id
     */
    public function getById(string $patientId, string $deviceAssociationId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDeviceAssociation(...));

        return $this->get(self::URL . "/$patientId/device_associations/$deviceAssociationId");
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
     * Validate a single device association from eHealth API response.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateDeviceAssociation(EHealthResponse $response): array
    {
        return $this->runDeviceAssociationValidation([$this->replaceEHealthPropNames($response->getData())])[0];
    }

    /**
     * Validate device associations collection from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateDeviceAssociations(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        return $this->runDeviceAssociationValidation($replaced);
    }

    /**
     * Apply device association validation rules to a pre-processed list of device association data.
     *
     * @param  array  $replacedItems
     * @return array
     */
    private function runDeviceAssociationValidation(array $replacedItems): array
    {
        $rules = collect($this->deviceAssociationValidationRules())
            ->mapWithKeys(static fn (array $rule, string $key): array => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replacedItems, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Device association validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * List of validation rules for device associations from eHealth.
     *
     * @return array
     */
    protected function deviceAssociationValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            [
                'uuid' => ['required', 'uuid'],
                'status' => ['required', Rule::in(Status::values())],
                'association_date' => ['nullable', 'date'],
                'recorded' => ['required', 'date'],
                'primary_source' => ['required', 'boolean'],
                'explanatory_letter' => ['nullable', 'string', 'max:255'],
                'ehealth_inserted_at' => ['nullable', 'date'],
                'ehealth_updated_at' => ['nullable', 'date']
            ],
            ValidationRuleBuilder::identifierRules('device', true),
            ValidationRuleBuilder::identifierRules('context', true),
            ValidationRuleBuilder::identifierRules('recorder', true),
            ValidationRuleBuilder::codeableConceptRules('body_site'),
            ValidationRuleBuilder::codeableConceptRules('report_origin'),
            ValidationRuleBuilder::codeableConceptRules('status_reason')
        );
    }
}
