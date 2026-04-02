<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Enums\Person\ObservationStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Observation extends PatientApiBase
{
    /**
     * Return an observation context record by IDs.
     *
     * @param  string  $patientUuid
     * @param  string  $episodeUuid
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getInEpisodeContext(
        string $patientUuid,
        string $episodeUuid,
        array $data = []
    ): PromiseInterface|EHealthResponse {
        return $this->get(self::URL . "/$patientUuid/episodes/$episodeUuid/observations", $data);
    }

    /**
     * Get observation by ID.
     *
     * @param  string  $patientId
     * @param  string  $observationId
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/observation/get-observation-by-id
     */
    public function getById(string $patientId, string $observationId): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/$patientId/episodes/$observationId");
    }

    /**
     * Get a list of summary info about observations.
     *
     * @param  string  $patientId
     * @param  array{code?: string, issued_from?: string, issued_to?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-observations
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/observations", $mergedQuery);
    }

    /**
     * Get a list of observations.
     *
     * @param  string  $patientId
     * @param  array{
     *     code?: string,
     *     encounter_id?: string,
     *     diagnostic_report_id?: string,
     *     episode_id?: string,
     *     issued_from?: string,
     *     issued_to?: string,
     *     device_id?: string,
     *     managing_organization_id?: string,
     *     specimen_id?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/observation/get-observations-by-searh-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateObservations(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/observations", $mergedQuery);
    }

    /**
     * Get a detail info about observation summary.
     *
     * @param  string  $patientId
     * @param  string  $id  Observation ID
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-observation-by-id
     */
    public function getSummaryById(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/$patientId/summary/observations/$id");
    }

    /**
     * Validate observations response from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateObservations(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        $rules = collect($this->observationValidationRules())
            ->mapWithKeys(static fn ($rule, $key) => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replaced, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Observation validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * Validation rules for observation data.
     *
     * @return array
     */
    protected function observationValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            // Basic fields
            [
                'uuid' => ['required', 'string'],
                'status' => ['required', Rule::in(ObservationStatus::values())],
                'effective_date_time' => ['nullable', 'date'],
                'issued' => ['required', 'date'],
                'ehealth_inserted_at' => ['required', 'date'],
                'ehealth_updated_at' => ['required', 'date'],
                'primary_source' => ['required', 'boolean'],
                'comment' => ['nullable', 'string'],
                'reference_ranges' => ['nullable', 'array'], // TODO: add validation
                'explanatory_letter' => ['nullable', 'string', 'max:255'],
            ],

            // Identifier relationships
            ValidationRuleBuilder::identifierRules('diagnostic_report'),
            ValidationRuleBuilder::identifierRules('context'),
            ValidationRuleBuilder::identifierRules('performer'),
            ValidationRuleBuilder::identifierRules('specimen'),
            ValidationRuleBuilder::identifierRules('device'),
            ValidationRuleBuilder::identifierRules('based_on'),

            // Codeable concept relationships
            ValidationRuleBuilder::codeableConceptRules('code', true),
            ValidationRuleBuilder::codeableConceptRules('report_origin'),
            ValidationRuleBuilder::codeableConceptRules('interpretation'),
            ValidationRuleBuilder::codeableConceptRules('body_site'),
            ValidationRuleBuilder::codeableConceptRules('method'),

            // Codeable concept collections
            ValidationRuleBuilder::codeableConceptCollectionRules('categories', true),

            // Effective period
            ValidationRuleBuilder::periodRules(),

            // Components
            [
                'components' => ['nullable', 'array'],
                'components.*.reference_ranges' => ['nullable', 'array'],

                'components.*.value_codeable_concept.coding.*.extension' => ['nullable', 'array']
            ],
            ValidationRuleBuilder::codeableConceptRules('components.*.code'),
            ValidationRuleBuilder::codeableConceptRules('components.*.interpretation'),
            ValidationRuleBuilder::codeableConceptRules('components.*.value_codeable_concept')
        );
    }

}
