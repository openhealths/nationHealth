<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\Employee\Employee as EmployeeModel;
use App\Models\HealthcareService as HealthcareServiceModel;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EmployeeRole extends Request
{
    protected const string URL = '/api/employee_roles';

    /**
     * Get list of employee roles.
     *
     * @param  string  $url
     * @param  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(string $url = self::URL, $query = null): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));
        $this->setMapper($this->mapMany(...));

        $query = array_merge([
            self::QUERY_PARAM_PAGE_SIZE => config('ehealth.api.page_size')
        ], $query ?? []);

        return $this->get($url, $query);
    }

    /**
     * Add employee role.
     *
     * @param  array  $data
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));
        $this->setMapper($this->mapCreate(...));

        return $this->post(self::URL, $data);
    }

    /**
     * Deactivate a previously added employee role.
     *
     * @param  string  $uuid
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function deactivate(string $uuid): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->patch(self::URL . '/' . $uuid . '/actions/deactivate');
    }

    /**
     * Validate employee role response
     * see: https://esoz.docs.apiary.io/#reference/general/employee-requests/add-employee-role
     */
    protected function validateResponse(EHealthResponse $response): array
    {
        $data = $response->getData();

        $replaced = self::replaceEHealthPropNames($data);

        $validator = Validator::make($replaced, $this->validationRules());

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * Validate list of employee roles.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateMany(EHealthResponse $response): array
    {
        $replaced = [];
        foreach ($response->getData() as $data) {
            $replaced[] = self::replaceEHealthPropNames($data);
        }

        // Add *. to every rule
        $rules = collect($this->validationRules())
            ->mapWithKeys(static fn (string|array $rule, string $key) => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($replaced, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * Map UUID values to ID.
     *
     * @param  array  $validated
     * @return array
     */
    protected function mapCreate(array $validated): array
    {
        $validated['employee_id'] = EmployeeModel::where('uuid', $validated['employee_id'])->value('id');
        $validated['healthcare_service_id'] = HealthcareServiceModel::where('uuid', $validated['healthcare_service_id'])
            ->value('id');

        return $validated;
    }

    /**
     * Map UUID values to ID for multiple records.
     *
     * @param  array  $validated
     * @return array
     */
    protected function mapMany(array $validated): array
    {
        // Get unique uuids
        $employeeUuids = collect($validated)->pluck('employee_id')->unique()->filter()->values();
        $healthcareServiceUuids = collect($validated)->pluck('healthcare_service_id')->unique()->filter()->values();

        $employeeMap = EmployeeModel::whereIn('uuid', $employeeUuids)->pluck('id', 'uuid')->toArray();
        $healthcareServiceMap = HealthcareServiceModel::whereIn('uuid', $healthcareServiceUuids)
            ->pluck('id', 'uuid')
            ->toArray();

        // Map uuid to id
        return collect($validated)->map(static function (array $item) use ($employeeMap, $healthcareServiceMap) {
            $item['employee_id'] = $employeeMap[$item['employee_id']];
            $item['healthcare_service_id'] = $healthcareServiceMap[$item['healthcare_service_id']];

            return $item;
        })->toArray();
    }

    /**
     * List of validation rules for employee roles.
     *
     * @return array
     */
    protected function validationRules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'healthcare_service_id' => ['required', 'uuid'],
            'employee_id' => ['required', 'uuid'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in([Status::ACTIVE, Status::INACTIVE])],
            'is_active' => ['required', 'boolean:strict'],
            'ehealth_inserted_at' => ['required', 'date'],
            'ehealth_inserted_by' => ['required', 'uuid'],
            'ehealth_updated_at' => ['required', 'date'],
            'ehealth_updated_by' => ['required', 'uuid']
        ];
    }

    /**
     * Replace eHealth property names with the ones used in the application.
     * E.g., id => uuid.
     */
    protected static function replaceEHealthPropNames(array $properties): array
    {
        $replaced = [];

        foreach ($properties as $name => $value) {
            $newName = match ($name) {
                'id' => 'uuid',
                'inserted_at' => 'ehealth_inserted_at',
                'inserted_by' => 'ehealth_inserted_by',
                'updated_at' => 'ehealth_updated_at',
                'updated_by' => 'ehealth_updated_by',
                default => $name
            };

            $replaced[$newName] = $value;
        }

        return $replaced;
    }
}
