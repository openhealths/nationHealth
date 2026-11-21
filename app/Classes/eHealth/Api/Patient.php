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
use Illuminate\Validation\ValidationException;
use stdClass;

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
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateSummaryRecords($response, 'allergies'));
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
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateSummaryRecords($response, 'risk-assessments'));
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
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateSummaryRecords($response, 'medication-statements'));
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

    /**
     * Validate the wire representation before decoding objects as associative arrays:
     * otherwise both an empty object and an empty list become [].
     */
    private function validateSummaryRecords(EHealthResponse $response, string $section): array
    {
        $records = $response->object()->data ?? null;
        self::validateSummaryValue($records, [self::summaryRecordSchema($section)], 'data');

        return $response->getData();
    }

    /**
     * The three GET /summary response item schemas from medical-events/OpenAPI.
     * A leading ? marks optional properties, [shape] describes a JSON list, and oneOf
     * lists sets of mutually exclusive properties. Undocumented extensions are retained.
     */
    private static function summaryRecordSchema(string $section): array
    {
        $coding = ['system' => 'string', 'code' => 'string'];
        $concept = ['coding' => [$coding], '?text' => 'string'];
        $referenceType = ['?coding' => [$coding], '?text' => 'string'];
        $sourceConcept = ['?coding' => [['system' => 'string', '?code' => 'string']], '?text' => 'string'];
        $reference = ['?identifier' => ['type' => $referenceType, 'value' => 'string']];
        $actor = ['?identifier' => ['?type' => $sourceConcept, 'value' => 'string']];
        $source = [
            'oneOf' => [['asserter', 'report_origin']],
            '?asserter' => $actor, '?report_origin' => $sourceConcept,
        ];
        $quantity = ['value' => 'number', 'system' => 'string', 'code' => 'string', '?unit' => 'string', '?comparator' => 'string'];
        $range = ['low' => $quantity, 'high' => $quantity];

        return match ($section) {
            'allergies' => [
                'id' => 'string', 'clinical_status' => 'string', 'verification_status' => 'string',
                'type' => 'string', 'category' => 'string', 'criticality' => 'string',
                'context' => $reference + ['display_value' => 'string'], 'code' => $concept,
                'onset_date_time' => 'string', 'asserted_date' => 'string', 'inserted_at' => 'string',
                'updated_at' => 'string', 'primary_source' => 'boolean', '?last_occurrence' => 'string',
            ] + $source,
            'medication-statements' => [
                'id' => 'string', 'asserted_date' => 'string', 'status' => 'string', 'context' => $reference,
                'primary_source' => 'boolean', 'medication_code' => $concept, '?based_on' => $reference,
                '?effective_period' => 'string', '?note' => 'string', '?dosage' => 'string',
                '?inserted_at' => 'string', '?updated_at' => 'string',
            ] + $source,
            'risk-assessments' => [
                'id' => 'string', 'status' => 'string', 'code' => $concept, 'context' => $reference,
                'asserted_date' => 'string', 'performer' => $actor, '?method' => $concept,
                'oneOf' => [['reason_references', 'reason_codes']],
                '?reason_references' => [['?identifier' => ['type' => $sourceConcept, 'value' => 'string']]],
                '?reason_codes' => $sourceConcept,
                '?basis' => [
                    '?reference' => ['?identifier' => ['type' => $concept, 'value' => 'string']],
                    '?text' => 'string', '?references' => [$reference],
                ],
                '?predictions' => [[
                    'oneOf' => [['probability_range', 'probability_decimal'], ['when_range', 'when_period']],
                    '?outcome' => $concept, '?probability_range' => $range, '?probability_decimal' => 'number',
                    '?qualitative_risk' => $concept, '?relative_risk' => 'number', '?when_range' => $range,
                    '?when_period' => ['start' => 'string', '?end' => 'string'], '?rationale' => 'string',
                ]],
                '?mitigation' => 'string', '?comment' => 'string', '?inserted_at' => 'string', '?updated_at' => 'string',
            ],
        };
    }

    private static function validateSummaryValue(mixed $value, array|string $schema, string $path): void
    {
        $type = is_string($schema) ? $schema : (array_is_list($schema) ? 'array' : 'object');
        $valid = match ($type) {
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'number' => is_int($value) || is_float($value),
            'array' => is_array($value),
            'object' => $value instanceof stdClass,
        };
        if (!$valid) {
            throw ValidationException::withMessages([$path => "Expected a JSON $type."]);
        }
        if ($type === 'array') {
            foreach ($value as $index => $item) {
                self::validateSummaryValue($item, $schema[0], "$path.$index");
            }

            return;
        }
        if ($type !== 'object') {
            return;
        }

        foreach ($schema['oneOf'] ?? [] as $choices) {
            $present = array_filter($choices, static fn (string $key): bool => property_exists($value, $key));
            if (count($present) !== 1) {
                throw ValidationException::withMessages([$path => 'Expected exactly one of: '.implode(', ', $choices).'.']);
            }
        }
        foreach ($schema as $field => $rule) {
            if ($field === 'oneOf') {
                continue;
            }
            $key = ltrim($field, '?');
            if (property_exists($value, $key)) {
                self::validateSummaryValue($value->{$key}, $rule, "$path.$key");
            } elseif ($key === $field) {
                throw ValidationException::withMessages(["$path.$key" => 'Required property is missing.']);
            }
        }
    }
}
