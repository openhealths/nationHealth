<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Rules\InDictionary;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Validator;

class Patient extends Request
{
    protected const string URL = '/api/patients';

    /**
     * Get the patient emergency contact, using a medical event as the evidence for the request.
     *
     * @param  string  $patientId
     * @param  string  $medicalEventType  Either `conditions` or `diagnostic_reports`.
     * @param  string  $medicalEventId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/get-person-emergency-contact
     */
    public function getPersonEmergencyContact(string $patientId, string $medicalEventType, string $medicalEventId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateEmergencyContact(...));

        return $this->get(self::URL . "/$patientId/$medicalEventType/$medicalEventId/emergency_contact");
    }

    /**
     * Validate the emergency contact returned by eHealth.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateEmergencyContact(EHealthResponse $response): array
    {
        return Validator::make($response->getData(), [
            'first_name' => ['required', 'string'],
            'phones' => ['required', 'array'],
            'phones.*.type' => ['required', new InDictionary('PHONE_TYPE')],
            'phones.*.number' => ['required', 'string']
        ])->validate();
    }

    /**
     * Get the current diagnoses related only to active episodes.
     *
     * @param  string  $patientId
     * @param  array{code?:string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-active-diagnoses
     */
    public function getActiveDiagnoses(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDiagnoses(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/diagnoses", $mergedQuery);
    }

    /**
     * Get a list of summary info about allergy intolerances.
     *
     * @param  string  $patientId
     * @param  array{code?: string, onset_date_time_from?: string, onset_date_time_to?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-allergy-intolerances
     */
    public function getAllergyIntolerances(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/allergy_intolerances", $mergedQuery);
    }

    /**
     * Get a list of summary info about risk assessments.
     *
     * @param  string  $patientId
     * @param  array{code?: string, asserted_date_from?: string, asserted_date_to?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-risk-assessments-by-search-params
     */
    public function getRiskAssessments(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/risk_assessments", $mergedQuery);
    }

    /**
     * Get a list of summary info about devices.
     *
     * @param  string  $patientId
     * @param  array{type?: string, asserted_date_from?: string, asserted_date_to?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-devices-by-search-params
     */
    public function getDevices(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/devices", $mergedQuery);
    }

    /**
     * Get a list of summary info about medication statements.
     *
     * @param  string  $patientId
     * @param  array{medication_code?: string, asserted_date_from?: string, asserted_date_to?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-medication-statement-by-search-params
     */
    public function getMedicationStatements(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/medication_statements", $mergedQuery);
    }

    /**
     * Validate a list of active diagnoses from eHealth API response.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateDiagnoses(EHealthResponse $response): array
    {
        $rules = collect($this->diagnosisValidationRules())
            ->mapWithKeys(static fn (array $rule, string $field): array => ["*.$field" => $rule])
            ->toArray();

        return Validator::make($response->getData(), $rules)->validate();
    }

    /**
     * Validation rules for a single active diagnosis.
     *
     * @return array
     */
    protected function diagnosisValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            ['rank' => ['nullable', 'integer']],
            ValidationRuleBuilder::identifierRules('condition', true),
            ValidationRuleBuilder::codeableConceptRules('code', true),
            ValidationRuleBuilder::codeableConceptRules('role', true)
        );
    }

    /**
     * Replace eHealth property names with the ones used in the application.
     * E.g., id => uuid, inserted_at => ehealth_inserted_at.
     */
    protected static function replaceEHealthPropNames(array $properties): array
    {
        $replaced = [];

        foreach ($properties as $name => $value) {
            $newName = match ($name) {
                'id' => 'uuid',
                'inserted_at' => 'ehealth_inserted_at',
                'updated_at' => 'ehealth_updated_at',
                default => $name
            };

            $replaced[$newName] = $value;
        }

        return $replaced;
    }
}
