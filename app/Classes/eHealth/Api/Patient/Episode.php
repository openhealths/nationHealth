<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Enums\Person\EpisodeStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Episode extends PatientApiBase
{
    /**
     * Create episode.
     *
     * @param  string  $id
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/encounter-data-package/create-episode
     */
    public function create(string $id, array $data): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/$id/episodes", $data);
    }

    /**
     * Get episode by ID.
     *
     * @param  string  $patientId
     * @param  string  $episodeId
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/encounter-data-package/get-episode-by-id
     */
    public function getById(string $patientId, string $episodeId): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/$patientId/episodes/$episodeId");
    }

    /**
     * Get episodes by search params.
     * Use period_from period_to to find episodes that were active in a certain period of time
     *
     * @param  string  $patientId  Person ID
     * @param  array{
     *     period_from?: string,
     *     period_to?: string,
     *     code?: string,
     *     status?: string,
     *     managing_organization_id?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/episode-of-care/get-episodes-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateEpisodes(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/episodes", $mergedQuery);
    }

    /**
     * Get brief information about episodes, in order not to disclose confidential and sensitive data.
     *
     * @param  string  $patientId
     * @param  array{
     *     period_start_from?: string,
     *     period_start_to?: string,
     *     period_end_from?: string,
     *     period_end_to?: string,
     *     page?: int,
     *     page_size?: int
     *     }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-short-episodes-by-search-params
     */
    public function getShortEpisodes(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateEpisodes(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/episodes", $mergedQuery);
    }

    /**
     * Validate episodes data from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateEpisodes(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        $rules = collect($this->episodeValidationRules())
            ->mapWithKeys(static fn ($rule, $key) => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replaced, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Episode validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * List of validation rules for episodes from eHealth.
     *
     * @return array
     */
    protected function episodeValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            [
                'uuid' => ['required', 'uuid'],
                'name' => ['required', 'string', 'max:255'],
                'status' => ['required', 'string', Rule::in(EpisodeStatus::values())],
                'ehealth_inserted_at' => ['required', 'date'],
                'ehealth_updated_at' => ['required', 'date']
            ],

            ValidationRuleBuilder::periodRules('period')
        );
    }
}
