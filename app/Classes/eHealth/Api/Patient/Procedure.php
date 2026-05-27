<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Enums\Person\ProcedureStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Procedure extends PatientApiBase
{
    /**
     * Create the procedure for patient.
     *
     * @param  string  $uuid  Person UUID
     * @param  array  $data
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/procedures/create-procedure
     */
    public function create(string $uuid, array $data = []): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/$uuid/procedures", $data);
    }

    /**
     * Return a procedure record by ID.
     *
     * @param  string  $patientId
     * @param  string  $procedureId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/procedures/get-procedures-by-id
     */
    public function getById(string $patientId, string $procedureId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateProcedure(...));

        return $this->get(self::URL . "/$patientId/procedures/$procedureId");
    }

    /**
     * Get a list of procedures by search params.
     *
     * @param  string  $patientId
     * @param  array{
     *     episode_id?: string,
     *     status?: string,
     *     used_reference_id?: string,
     *     based_on?: string,
     *     code?: string,
     *     managing_organization_id?: string,
     *     encounter_id?: string,
     *     origin_episode_id?: string,
     *     device_id?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/procedures/get-procedures-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateProcedures(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/procedures", $mergedQuery);
    }

    /**
     * Validate a single procedure from eHealth API response.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateProcedure(EHealthResponse $response): array
    {
        $replaced = [$this->replaceEHealthPropNames($response->getData())];

        return $this->runProcedureValidation($replaced)[0];
    }

    /**
     * Validate a list of procedures from eHealth API response.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateProcedures(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        return $this->runProcedureValidation($replaced);
    }

    /**
     * Apply procedure validation rules to a pre-processed list of procedure data.
     *
     * @param  array  $replacedItems
     * @return array
     */
    private function runProcedureValidation(array $replacedItems): array
    {
        $rules = collect($this->procedureValidationRules())
            ->mapWithKeys(static fn ($rule, $key) => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replacedItems, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Procedure validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * Validation rules for procedure data.
     */
    protected function procedureValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            [
                'uuid' => ['required', 'uuid'],
                'status' => ['required', Rule::in(ProcedureStatus::values())],
                'performed_date_time' => ['nullable', 'date'],
                'primary_source' => ['required', 'boolean'],
                'note' => ['nullable', 'string'],
                'explanatory_letter' => ['nullable', 'string'],
                'ehealth_inserted_at' => ['required', 'date'],
                'ehealth_updated_at' => ['required', 'date']
            ],
            ValidationRuleBuilder::identifierRules('based_on'),
            ValidationRuleBuilder::paperReferralRules(),
            ValidationRuleBuilder::codeableConceptRules('status_reason'),
            ValidationRuleBuilder::identifierRules('code', true),
            ValidationRuleBuilder::periodRules('performed_period'),
            ValidationRuleBuilder::identifierRules('recorded_by', true),
            ValidationRuleBuilder::identifierRules('performer'),
            ValidationRuleBuilder::codeableConceptRules('report_origin'),
            ValidationRuleBuilder::identifierRules('division'),
            ValidationRuleBuilder::identifierRules('managing_organization', true),
            ValidationRuleBuilder::identifierCollectionRules('reason_references'),
            ValidationRuleBuilder::identifierCollectionRules('used_references'),
            ValidationRuleBuilder::codeableConceptRules('outcome'),
            ValidationRuleBuilder::codeableConceptRules('category', true),
            ValidationRuleBuilder::identifierRules('encounter'),
            ValidationRuleBuilder::identifierRules('origin_episode'),
            ValidationRuleBuilder::identifierCollectionRules('complication_details'),
            ValidationRuleBuilder::codeableConceptCollectionRules('used_codes')
        );
    }
}
