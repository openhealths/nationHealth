<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Enums\Person\ConditionClinicalStatus;
use App\Enums\Person\ConditionVerificationStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Condition extends PatientApiBase
{
    /**
     * Get a list of summary info.
     *
     * @param  string  $patientId
     * @param  array{code?: string, onset_date_from?: string, onset_date_to?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-conditions
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/conditions", $mergedQuery);
    }

    /**
     * Return a condition context record by IDs.
     *
     * @param  string  $patientId
     * @param  string  $episodeId
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/conditions/get-condition-context
     */
    public function getInEpisodeContext(
        string $patientId,
        string $episodeId,
        array $data = []
    ): PromiseInterface|EHealthResponse {
        return $this->get(self::URL . "/$patientId/episodes/$episodeId/conditions", $data);
    }

    /**
     * Return detail data by ID.
     *
     * @param  string  $patientId
     * @param  string  $conditionId
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/conditions/get-condition-by-id
     */
    public function getById(string $patientId, string $conditionId): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/$patientId/conditions/$conditionId");
    }

    /**
     * Get a list of observations.
     *
     * @param  string  $patientId
     * @param  array{
     *     code?: string,
     *     encounter_id?: string,
     *     episode_id?: string,
     *     onset_date_from?: string,
     *     onset_date_to?: string,
     *     managing_organization_id?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/conditions/get-conditions-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateConditions(...));
        $this->setDefaultPageSize();

        return $this->get(self::URL . "/$patientId/conditions", $query);
    }

    /**
     * Validate conditions data from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateConditions(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        $rules = collect($this->conditionValidationRules())
            ->mapWithKeys(static fn ($rule, $key) => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replaced, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Condition validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * List of validation rules for conditions from eHealth.
     *
     * @return array
     */
    protected function conditionValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            // Basic fields
            [
                'uuid' => ['required', 'uuid'],
                'primary_source' => ['required', 'boolean'],
                'clinical_status' => ['required', Rule::in(ConditionClinicalStatus::values())],
                'verification_status' => ['required', Rule::in(ConditionVerificationStatus::values())],
                'onset_date' => ['required', 'date'],
                'asserted_date' => ['nullable', 'date'],
                'explanatory_letter' => ['nullable', 'string', 'max:255'],
                'ehealth_inserted_at' => ['required', 'date'],
                'ehealth_updated_at' => ['required', 'date']
            ],

            // Identifier relationships
            ValidationRuleBuilder::identifierRules('asserter'),
            ValidationRuleBuilder::identifierRules('context', true),

            // Codeable concept relationships
            ValidationRuleBuilder::codeableConceptRules('report_origin'),
            ValidationRuleBuilder::codeableConceptRules('code', true),
            ValidationRuleBuilder::codeableConceptRules('severity'),

            // Array of codeable concept
            ValidationRuleBuilder::codeableConceptCollectionRules('body_sites'),

            // Stage
            ['stage' => ['nullable', 'array']],
            ValidationRuleBuilder::codeableConceptRules('stage.summary'),

            // Evidences
            ['evidences' => ['nullable', 'array']],
            ValidationRuleBuilder::codeableConceptCollectionRules('evidences.*.codes'),
            ValidationRuleBuilder::identifierCollectionRules('evidences.*.details')
        );
    }
}
