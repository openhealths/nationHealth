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
     * @param  string  $patientId
     * @param array{
     *     vaccine_code?: string,
     *     date_from?: string,
     *     date_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-immunizations
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
     * @param  string  $patientId
     * @param array{
     *     vaccine_code?: string,
     *     encounter_id?: string,
     *     episode_id: string,
     *     date_from?: string,
     *     date_to?: string,
     *     page?: int,
     *     page_size?: int
     * } $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/immunization/get-immunizations-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateImmunizations(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'],
            $this->format($query, ['date_from', 'date_to'])
        );

        return $this->get(self::URL . "/$patientId/immunizations", $mergedQuery);
    }

    /**
     * Validate immunizations collection from eHealth API.
     *
     * @param  EHealthResponse  $response
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
     * List of validation rules for immunizations from eHealth.
     *
     * @return array
     */
    protected function immunizationValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            // Basic fields
            [
                'uuid' => ['required', 'uuid'],
                'status' => ['required', Rule::in(ImmunizationStatus::values())],
                'not_given' => ['required', 'boolean'],
                'primary_source' => ['required', 'boolean'],
                'date' => ['required', 'date'],
                'ehealth_inserted_at' => ['required', 'date'],
                'ehealth_updated_at' => ['required', 'date'],
                'manufacturer' => ['nullable', 'string', 'max:255'],
                'lot_number' => ['nullable', 'string', 'max:255'],
                'expiration_date' => ['nullable', 'date'],
                'explanatory_letter' => ['nullable', 'string', 'max:255'],
            ],

            // Identifier relationships
            ValidationRuleBuilder::identifierRules('context', true),
            ValidationRuleBuilder::identifierRules('performer'),

            // Codeable concept relationships
            ValidationRuleBuilder::codeableConceptRules('vaccine_code', true),
            ValidationRuleBuilder::codeableConceptRules('report_origin'),
            ValidationRuleBuilder::codeableConceptRules('site'),
            ValidationRuleBuilder::codeableConceptRules('route'),

            // Dose quantity
            [
                'dose_quantity' => ['nullable', 'array'],
                'dose_quantity.value' => ['required_with:dose_quantity', 'numeric'],
                'dose_quantity.comparator' => ['nullable', 'string', 'max:255'],
                'dose_quantity.unit' => ['nullable', 'string', 'max:255'],
                'dose_quantity.system' => ['required_with:dose_quantity', 'string', 'max:255'],
                'dose_quantity.code' => ['required_with:dose_quantity', 'string', 'max:255'],
            ],

            // Explanation
            ['explanation' => ['nullable', 'array']],
            ValidationRuleBuilder::codeableConceptCollectionRules('explanation.reasons'),
            ValidationRuleBuilder::codeableConceptCollectionRules('explanation.reasons_not_given'),
            ['reactions' => ['nullable', 'array']],
            ValidationRuleBuilder::identifierCollectionRules('reactions.*.detail'),

            // Vaccination protocols
            [
                'vaccination_protocols' => ['nullable', 'array'],
                'vaccination_protocols.*.dose_sequence' => ['nullable', 'integer'],
                'vaccination_protocols.*.description' => ['nullable', 'string', 'max:255'],
                'vaccination_protocols.*.series' => ['nullable', 'string', 'max:255'],
                'vaccination_protocols.*.series_doses' => ['nullable', 'integer']
            ],
            ValidationRuleBuilder::codeableConceptRules('vaccination_protocols.*.authority'),
            ValidationRuleBuilder::codeableConceptCollectionRules('vaccination_protocols.*.target_diseases', true)
        );
    }
}
