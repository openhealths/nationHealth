<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\MedicalEventsErrorTranslator;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Contracts\ApiErrorTranslator;
use App\Enums\DetectedIssue\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DetectedIssue extends PatientApiBase
{
    /**
     * Get detected issues by search params.
     *
     * @param  string  $patientId
     * @param array{
     *     encounter_id?: string,
     *     episode_id?: string,
     *     status?: string,
     *     recorder?: string,
     *     recorder_legal_entity_id?: string,
     *     device_id?: string,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     identified_date_time_from?: string,
     *     identified_date_time_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/detected-issue/get-detected-issues-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDetectedIssues(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, ['inserted_at_from', 'inserted_at_to'])
        );

        return $this->get(self::URL . "/$patientId/detected_issues", $mergedQuery);
    }

    /**
     * Return detail data by ID.
     *
     * @param  string  $patientId
     * @param  string  $detectedIssueId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/detected-issue/get-detected-issue-by-id
     */
    public function getById(string $patientId, string $detectedIssueId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDetectedIssue(...));

        return $this->get(self::URL . "/$patientId/detected_issues/$detectedIssueId");
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
     * Validate a single detected issue from eHealth API response.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateDetectedIssue(EHealthResponse $response): array
    {
        return $this->runDetectedIssueValidation([$this->replaceEHealthPropNames($response->getData())])[0];
    }

    /**
     * Validate detected issues collection from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateDetectedIssues(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        return $this->runDetectedIssueValidation($replaced);
    }

    /**
     * Apply detected issue validation rules to a pre-processed list of detected issue data.
     *
     * @param  array  $replacedItems
     * @return array
     */
    private function runDetectedIssueValidation(array $replacedItems): array
    {
        $rules = collect($this->detectedIssueValidationRules())
            ->mapWithKeys(static fn (array $rule, string $key): array => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replacedItems, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Detected issue validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * List of validation rules for detected issues from eHealth.
     *
     * @return array
     */
    protected function detectedIssueValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            [
                'uuid' => ['required', 'uuid'],
                'status' => ['required', Rule::in(Status::values())],
                'primary_source' => ['required', 'boolean'],
                'detail' => ['nullable', 'string'],
                'identified_date_time' => ['nullable', 'date'],
                'explanatory_letter' => ['nullable', 'string', 'max:255'],
                'ehealth_inserted_at' => ['required', 'date'],
                'ehealth_updated_at' => ['required', 'date']
            ],
            ValidationRuleBuilder::identifierRules('subject', true),
            ValidationRuleBuilder::identifierRules('encounter', true),
            ValidationRuleBuilder::identifierRules('recorder', true),
            ValidationRuleBuilder::identifierRules('author'),
            ValidationRuleBuilder::identifierRules('implicated'),
            ValidationRuleBuilder::identifierRules('based_on'),
            ValidationRuleBuilder::codeableConceptRules('code'),
            ValidationRuleBuilder::codeableConceptRules('report_origin'),
            ValidationRuleBuilder::codeableConceptRules('status_reason')
        );
    }
}
