<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Enums\Person\ClinicalImpressionStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ClinicalImpression extends PatientApiBase
{
    /**
     * Get a list of summary info about clinical impressions.
     *
     * @param  string  $patientId
     * @param  array{encounter_id?: string, episode_id?: string, code?: string, status?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-clinical-impressions
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateClinicalImpressions(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/clinical_impressions", $mergedQuery);
    }

    /**
     * Validate clinical impressions data from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateClinicalImpressions(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        $rules = collect($this->clinicalImpressionValidationRules())
            ->mapWithKeys(static fn ($rule, $key) => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replaced, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Clinical impression validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * List of validation rules for clinical impressions from eHealth.
     *
     * @return array
     */
    protected function clinicalImpressionValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            // Basic fields
            [
                'uuid' => ['required', 'uuid'],
                'status' => ['required', Rule::in(ClinicalImpressionStatus::values())],
                'description' => ['nullable', 'string'],
                'note' => ['nullable', 'string'],
                'summary' => ['nullable', 'string'],
                'explanatory_letter' => ['nullable', 'string', 'max:255'],
                'ehealth_inserted_at' => ['required', 'date'],
                'ehealth_updated_at' => ['required', 'date'],
                'effective_date_time' => ['nullable', 'date'],
            ],

            // Identifier relationships
            ValidationRuleBuilder::identifierRules('assessor', true),
            ValidationRuleBuilder::identifierRules('encounter', true),
            ValidationRuleBuilder::identifierRules('previous'),

            // Codeable concept relationships
            ValidationRuleBuilder::codeableConceptRules('code', true),

            // Effective period
            ValidationRuleBuilder::periodRules(),
            [
                'findings' => ['nullable', 'array'],
                'findings.*.item_reference' => ['required_with:findings', 'array'],
                'findings.*.item_reference.identifier' => ['required_with:findings.*.item_reference', 'array'],
                'findings.*.item_reference.identifier.type' => [
                    'required_with:findings.*.item_reference.identifier',
                    'array'
                ],
                'findings.*.item_reference.identifier.type.coding' => [
                    'required_with:findings.*.item_reference.identifier.type',
                    'array'
                ],
                'findings.*.item_reference.identifier.type.coding.*.code' => [
                    'required_with:findings.*.item_reference.identifier.type.coding',
                    'string',
                    'max:255'
                ],
                'findings.*.item_reference.identifier.type.coding.*.system' => [
                    'required_with:findings.*.item_reference.identifier.type.coding',
                    'string',
                    'max:255'
                ],
                'findings.*.item_reference.identifier.type.text' => ['nullable', 'string', 'max:255'],
                'findings.*.item_reference.identifier.value' => [
                    'required_with:findings.*.item_reference.identifier',
                    'uuid'
                ],
                'findings.*.basis' => ['nullable', 'string', 'max:255']
            ],

            // Collections of identifiers
            ValidationRuleBuilder::identifierCollectionRules('problems'),
            ValidationRuleBuilder::identifierCollectionRules('supporting_info')
        );
    }

}
