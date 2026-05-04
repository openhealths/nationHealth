<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Enums\Person\ImmunizationStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Immunization extends PatientApiBase
{
    /**
     * Get a list of summary info about immunizations.
     *
     * Allowed search params:
     * - vaccine_code
     * - date_from
     * - date_to
     * - page
     * - page_size
     *
     * @param string $patientId
     * @param array{
     *     vaccine_code?: string,
     *     date_from?: string,
     *     date_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateImmunizations(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/$patientId/summary/immunizations", $mergedQuery);
    }

    /**
     * Get immunizations by search params.
     *
     * This method intentionally uses the summary endpoint because the task is:
     * search + filtering + validation, without local DB synchronization.
     *
     * @param string $patientId
     * @param array{
     *     vaccine_code?: string,
     *     date_from?: string,
     *     date_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateImmunizations(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/$patientId/immunizations", $mergedQuery);
    }

    /**
     * Get immunization by ESOZ ID.
     *
     * @param string $patientId
     * @param string $immunizationId
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getById(string $patientId, string $immunizationId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateImmunization(...));

        return $this->get(self::URL . "/$patientId/summary/immunizations/$immunizationId");
    }

    /**
     * Validate immunizations collection from eHealth API.
     *
     * @param EHealthResponse $response
     * @return array
     */
    protected function validateImmunizations(EHealthResponse $response): array
    {
        $data = $response->getData();

        if (empty($data)) {
            return [];
        }

        $items = array_is_list($data) ? $data : [$data];

        $replaced = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $replaced[] = $this->replaceEHealthPropNames($item);
        }

        $rules = collect($this->immunizationValidationRules())
            ->mapWithKeys(static fn ($rule, $key) => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replaced, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Immunization validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * Validate single immunization from eHealth API.
     *
     * @param EHealthResponse $response
     * @return array
     */
    protected function validateImmunization(EHealthResponse $response): array
    {
        $data = $response->getData();

        if (!is_array($data) || empty($data)) {
            return [];
        }

        $replaced = $this->replaceEHealthPropNames($data);

        $validator = Validator::make(
            $replaced,
            $this->immunizationValidationRules()
        );

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Single immunization validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * Validation rules for immunization data from eHealth.
     *
     * These rules are intentionally tolerant because summary endpoints can return
     * a reduced structure. Required fields are kept only where they are critical
     * for rendering/searching.
     *
     * @return array
     */
    protected function immunizationValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            [
                'uuid' => ['required', 'uuid'],
                'status' => ['nullable', Rule::in(ImmunizationStatus::values())],
                'not_given' => ['nullable', 'boolean'],
                'primary_source' => ['nullable', 'boolean'],
                'date' => ['nullable', 'date'],
                'ehealth_inserted_at' => ['nullable', 'date'],
                'ehealth_updated_at' => ['nullable', 'date'],
                'manufacturer' => ['nullable', 'string', 'max:255'],
                'lot_number' => ['nullable', 'string', 'max:255'],
                'expiration_date' => ['nullable', 'date'],
                'explanatory_letter' => ['nullable', 'string', 'max:255'],
            ],

            ValidationRuleBuilder::identifierRules('context'),
            ValidationRuleBuilder::identifierRules('performer'),

            ValidationRuleBuilder::codeableConceptRules('vaccine_code'),
            ValidationRuleBuilder::codeableConceptRules('report_origin'),
            ValidationRuleBuilder::codeableConceptRules('site'),
            ValidationRuleBuilder::codeableConceptRules('route'),

            [
                'dose_quantity' => ['nullable', 'array'],
                'dose_quantity.value' => ['nullable', 'numeric'],
                'dose_quantity.comparator' => ['nullable', 'string', 'max:255'],
                'dose_quantity.unit' => ['nullable', 'string', 'max:255'],
                'dose_quantity.system' => ['nullable', 'string', 'max:255'],
                'dose_quantity.code' => ['nullable', 'string', 'max:255'],
            ],

            [
                'explanation' => ['nullable', 'array'],
                'reactions' => ['nullable', 'array'],
                'vaccination_protocols' => ['nullable', 'array'],
                'vaccination_protocols.*.dose_sequence' => ['nullable', 'integer'],
                'vaccination_protocols.*.description' => ['nullable', 'string', 'max:255'],
                'vaccination_protocols.*.series' => ['nullable', 'string', 'max:255'],
                'vaccination_protocols.*.series_doses' => ['nullable', 'integer'],
            ],

            ValidationRuleBuilder::codeableConceptCollectionRules('explanation.reasons'),
            ValidationRuleBuilder::codeableConceptCollectionRules('explanation.reasons_not_given'),
            ValidationRuleBuilder::identifierCollectionRules('reactions.*.detail'),
            ValidationRuleBuilder::codeableConceptRules('vaccination_protocols.*.authority'),
            ValidationRuleBuilder::codeableConceptCollectionRules('vaccination_protocols.*.target_diseases')
        );
    }
}