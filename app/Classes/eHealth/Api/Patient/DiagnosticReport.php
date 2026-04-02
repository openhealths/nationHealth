<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Enums\Person\DiagnosticReportStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DiagnosticReport extends PatientApiBase
{
    /**
     * Create the diagnostic report for patient.
     *
     * @param  string  $uuid  Person UUID
     * @param  array  $data
     * @return EHealthResponse|PromiseInterface
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/diagnostic-report-data-package/submit-diagnostic-report-package
     */
    public function create(string $uuid, array $data = []): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/$uuid/diagnostic_report_package", $data);
    }

    /**
     * Get a diagnostic report by ID.
     *
     * @param  string  $patientId
     * @param  string  $diagnosticReportId
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/diagnostic-report/get-diagnostic-report-by-id
     */
    public function getById(string $patientId, string $diagnosticReportId): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/$patientId/diagnostic_reports/$diagnosticReportId");
    }

    /**
     * Get a list of info filtered by search params.
     *
     * @param  string  $patientId
     * @param  array{
     *     code?: string,
     *     encounter_id?: string,
     *     context_episode_id?: string,
     *     origin_episode_id?: string,
     *     issued_from?: string,
     *     issued_to?: string,
     *     based_on?: string,
     *     managing_organization_id?: string,
     *     specimen_id?: string,
     *     page?: int,
     *     page_size?: int
     *     }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/diagnostic-report/get-diagnostic-report-by-search-params
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDiagnosticReports(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/diagnostic_reports", $mergedQuery);
    }

    /**
     * Get a list of summary info about diagnostic reports.
     *
     * @param  string  $patientId
     * @param  array{code?: string, issued_from?: string, issued_to?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-diagnostic-report-by-search-params
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query ?? []);

        return $this->get(self::URL . "/$patientId/summary/diagnostic_reports", $mergedQuery);
    }

    /**
     * Validate diagnostic reports response from eHealth API.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateDiagnosticReports(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = $this->replaceEHealthPropNames($data);
        }

        $rules = collect($this->diagnosticReportValidationRules())
            ->mapWithKeys(static fn ($rule, $key) => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replaced, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'Diagnostic report validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate();
    }

    /**
     * Validation rules for diagnostic report data.
     *
     * @return array
     */
    protected function diagnosticReportValidationRules(): array
    {
        return ValidationRuleBuilder::merge(
            // Basic fields
            [
                'uuid' => ['required', 'uuid'],
                'status' => ['required', Rule::in(DiagnosticReportStatus::values())],
                'effective_date_time' => ['nullable', 'date'],
                'issued' => ['required', 'date'],
                'primary_source' => ['required', 'boolean'],
                'conclusion' => ['nullable', 'string'],
                'explanatory_letter' => ['nullable', 'string', 'max:255'],
                'ehealth_inserted_at' => ['required', 'date'],
                'ehealth_updated_at' => ['required', 'date']
            ],

            // Identifier relationships
            ValidationRuleBuilder::identifierRules('based_on'),
            ValidationRuleBuilder::identifierRules('division'),
            ValidationRuleBuilder::identifierRules('code', true),
            ValidationRuleBuilder::identifierRules('encounter'),
            ValidationRuleBuilder::identifierRules('origin_episode'),
            ValidationRuleBuilder::identifierRules('recorded_by', true),
            ValidationRuleBuilder::identifierRules('managing_organization'),

            // Codeable concept relationships
            ValidationRuleBuilder::codeableConceptRules('report_origin'),
            ValidationRuleBuilder::codeableConceptRules('conclusion_code'),
            ValidationRuleBuilder::codeableConceptRules('cancellation_reason'),

            // Codeable concept collections
            ValidationRuleBuilder::codeableConceptCollectionRules('category', true),

            // Performer relationships
            ValidationRuleBuilder::referenceRules('performer'),
            ValidationRuleBuilder::referenceRules('results_interpreter'),

            // Special relationships
            ValidationRuleBuilder::paperReferralRules(),
            ValidationRuleBuilder::periodRules(),

            // Collections of identifiers
            ValidationRuleBuilder::identifierCollectionRules('specimens'),
            ValidationRuleBuilder::identifierCollectionRules('used_references')
        );
    }
}
