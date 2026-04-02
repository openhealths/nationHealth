<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Encounter extends PatientApiBase
{
    /**
     * @param  string  $id  Person ID
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/encounter-data-package/submit-encounter-package
     */
    public function submit(string $id, array $data): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/$id/encounter_package", $data);
    }

    /**
     * Get a list of short Encounter info filtered by search params.
     *
     * @param  string  $patientId
     * @param  array{
     *     period_start_from?: string,
     *     period_start_to?: string,
     *     period_end_from?: string,
     *     period_end_to?: string,
     *     episode_id?: string,
     *     status?: string,
     *     type?: string,
     *     class?: string,
     *     performer_speciality?: string,
     *     page?: int,
     *     page_size?: int
     *     }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-short-encounters-by-search-params
     */
    public function getShortBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateEncounters(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/encounters", $mergedQuery);
    }

    /**
     * Get data about Encounter by ID.
     *
     * @param  string  $patientId
     * @param  string  $encounterId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/immunization/get-encounter-by-id
     */
    public function getById(string $patientId, string $encounterId, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/$patientId/encounters/$encounterId", $query);
    }

    /**
     * Get a list of encounters.
     *
     * @param  string  $patientId
     * @param  array{
     *     period_start_from?: string,
     *     period_start_to?: string,
     *     period_end_from?: string,
     *     period_end_to?: string,
     *     episode_id?: string,
     *     incoming_referral_id?: string,
     *     origin_episode_id?: string,
     *     managing_organization_id?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/encounter/get-encounters-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/encounters", $mergedQuery);
    }

    /**
     * Validate encounters data from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateEncounters(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        $rules = collect($this->encounterValidationRules())
            ->mapWithKeys(static fn ($rule, $key) => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replaced, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Encounter validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * List of validation rules for encounters from eHealth.
     *
     * @return array
     */
    protected function encounterValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            // Basic fields
            [
                'uuid' => ['required', 'uuid'],
                'status' => ['required', Rule::in(EncounterStatus::values())],
            ],

            // Coding relationships
            ValidationRuleBuilder::codingRules('class', true),
            ValidationRuleBuilder::periodRules('period', true),

            // Identifier relationships
            ValidationRuleBuilder::identifierRules('episode', true),

            // Codeable concept relationships
            ValidationRuleBuilder::codeableConceptRules('type', true),
            ValidationRuleBuilder::codeableConceptRules('performer_speciality', true)
        );
    }

}
