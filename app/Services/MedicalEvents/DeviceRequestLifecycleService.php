<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\Api\DeviceRequest;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\SchemaResponseContract;
use App\Contracts\EHealthRequestLifecycleContract;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Policies\DeviceRequestRequestPolicy;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\MedicalEvents\Mappers\DeviceRequestMapper;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Picqer\Barcode\BarcodeGeneratorPNG;

class DeviceRequestLifecycleService extends EHealthRequestLifecycleService implements EHealthRequestLifecycleContract
{
    public function authorizeAction(LegalEntity $legalEntity, string $scope, ?string $employeeUuid = null): Employee
    {
        $user = Auth::user();
        abort_unless($legalEntity->is(legalEntity()), 404);
        Gate::authorize('perform', [DeviceRequestRequest::class, $scope]);

        $employee = app(DeviceRequestRequestPolicy::class)->employee($user, $scope, $employeeUuid);
        abort_unless($employee !== null, 404);

        return $employee;
    }

    /** Read every page; incomplete configurations must not change prescribing rules. */
    public function collectPages(callable $fetch): array
    {
        $items = [];
        $page = 1;
        $totalPages = null;
        do {
            $response = $fetch($page++);
            $rows = $response->validate();
            $paging = $response->getPaging();
            if (!isset($paging['page_number'], $paging['total_pages'])
                || $paging['page_number'] !== $page - 1
                || ($totalPages !== null && $paging['total_pages'] !== $totalPages)) {
                $this->invalid('configuration', __('device-requests.validation.configuration_incomplete'));
            }
            $totalPages = $paging['total_pages'];
            foreach ($rows as $item) {
                $items[] = $item;
            }
        } while ($response->isNotLast());

        return $items;
    }

    public function dictionary(string $name): array
    {
        $rows = EHealth::dictionary()->getMany(['name' => $name, 'is_active' => true])->validate();
        if ($rows === []) {
            return [];
        }

        return BasicDictionaryCollection::make($rows)->byName($name)->flattenedChildValues()->all();
    }

    public function permittedTypes(bool $withProgram): array
    {
        $name = $withProgram ? 'prescribable_device_codes' : 'assistive_devices';
        $rows = $this->collectPages(fn (int $page) => EHealth::configuration()->getDictionaries([
            'name' => $name, 'is_active' => true, 'page' => $page,
        ]));
        $types = [];
        foreach ($rows as $row) {
            foreach ($this->configurationSettings($row) as $entry) {
                $system = $entry['system'] ?? '';
                if ($system === '') {
                    continue;
                }
                foreach ($this->dictionary($system) as $code => $label) {
                    if (!isset($entry['codes']) || in_array((string) $code, $entry['codes'], true)) {
                        $types[$system.'|'.$code] = ['system' => $system, 'code' => (string) $code, 'label' => $label];
                    }
                }
            }
        }

        return $types;
    }

    public function authMethods(Person $person): array
    {
        return EHealth::person()->getAuthMethods($person->uuid)->validate();
    }

    public function program(string $id, bool $requireActive = true): array
    {
        $program = EHealth::medicalProgram()->asMis()->getById($id)->validate();
        if (($program['id'] ?? '') !== $id || ($program['type'] ?? '') !== 'DEVICE'
            || ($requireActive && !($program['is_active'] ?? false))) {
            $this->invalid('program_id', __('device-requests.validation.program_unavailable'));
        }

        return $program;
    }

    public function encounter(Person $person, Employee $employee, string $uuid): Encounter
    {
        $encounter = MedicalEventsRepository::deviceRequest()->encounterForPerson($person, $uuid);
        $end = $encounter->period?->end;
        if ($encounter->performer?->value !== $employee->uuid || !$end
            || !CarbonImmutable::parse($end)->isToday()
            || $encounter->status?->value !== 'finished') {
            $this->invalid('encounter', __('device-requests.validation.encounter_unavailable'));
        }

        return $encounter;
    }

    public function carePlanContext(Person $person, string $carePlanUuid, string $activityUuid, ?string $actingEmployeeUuid = null): array
    {
        $localPlan = Repository::carePlan()->findByUuid($carePlanUuid, legalEntity()?->id, $person->id);
        if ($localPlan === null) {
            $this->invalid('activity', __('care-plan.full_sync_required'));
        }
        Gate::authorize('manageActivities', [$localPlan, $actingEmployeeUuid]);
        $plan = Arr::toSnakeCase(EHealth::carePlan()->getDetails($person->uuid, $carePlanUuid)->validate());
        $activity = Arr::toSnakeCase(EHealth::carePlanActivity()->getDetails($person->uuid, $carePlanUuid, $activityUuid)->validate());
        $detail = $activity['detail'] ?? $activity;
        if (($plan['status'] ?? '') !== 'active'
            || !in_array($detail['status'] ?? ($activity['status'] ?? ''), ['scheduled', 'in-progress', 'active'], true)
            || ($detail['kind'] ?? ($activity['kind'] ?? '')) !== 'device_request') {
            $this->invalid('activity', __('device-requests.validation.inactive_plan'));
        }
        $activityPeriod = $detail['scheduled_period'] ?? null;
        // A non-program activity may omit its period; the plan period always applies.
        if ($activityPeriod === null && !empty(data_get($detail, 'program.identifier.value'))) {
            $this->invalid('activity', __('device-requests.validation.plan_period_unavailable'));
        }
        $periods = [$plan['period'] ?? []];
        if ($activityPeriod !== null) {
            $periods[] = $activityPeriod;
        }
        foreach ($periods as $period) {
            if (empty($period['start'])
                || CarbonImmutable::parse($period['start'])->isFuture()
                || (!empty($period['end']) && CarbonImmutable::parse($period['end'])->isPast())) {
                $this->invalid('activity', __('device-requests.validation.plan_period_unavailable'));
            }
        }

        return ['plan' => $plan, 'detail' => $detail];
    }

    /** Fresh metadata is used both while editing and immediately before KEP. */
    public function prescribingOptions(array $data): array
    {
        $programId = $data['program_id'] ?? '';
        $program = $programId !== '' ? $this->program($programId) : [];
        if ($programId !== '' && ($program['is_active'] ?? false) !== true) {
            $this->invalid('program_id', __('device-requests.validation.program_inactive'));
        }
        $permitted = $this->permittedTypes($programId !== '');
        $model = [];
        if (($data['device_code_type'] ?? '') === 'DEVICE_DEFINITION') {
            $model = EHealth::deviceDefinition()->getById($data['device_id'])->validate();
            if (($model['is_active'] ?? false) !== true) {
                $this->invalid('device_id', __('device-requests.validation.model_inactive'));
            }
            $codings = $model['classification_types'] ?? [];
        } else {
            $codings = [['system' => $data['device_code_system'] ?? '', 'code' => $data['device_id'] ?? '']];
        }
        $codings = array_map(static fn (array $coding): array => $coding['coding'][0] ?? $coding, $codings);
        if (!collect($codings)->contains(fn (array $coding): bool => isset($permitted[($coding['system'] ?? '').'|'.($coding['code'] ?? '')]))) {
            $this->invalid('device_id', $programId !== '' ? __('device-requests.validation.type_not_prescribable') : __('device-requests.validation.assistive_only_without_program'));
        }
        $settings = $program['medical_program_settings'] ?? ($program['settings'] ?? []);
        if ($programId !== '' && ($program['device_request_allowed'] ?? true) === false) {
            $this->invalid('program_id', __('device-requests.validation.program_prescribing_disallowed'));
        }
        if ($programId !== '') {
            $assistive = $this->permittedTypes(false);
            if (collect($codings)->contains(fn (array $coding): bool => isset($assistive[$coding['system'].'|'.$coding['code']]))) {
                $this->invalid('program_id', __('device-requests.validation.assistive_requires_no_program'));
            }
        }
        $allowedTypes = $settings['device_request_allowed_code_types'] ?? [];
        if ($programId !== '' && $allowedTypes !== [] && !in_array($data['device_code_type'], $allowedTypes, true)) {
            $this->invalid('device_code_type', __('device-requests.validation.selection_disallowed'));
        }
        $packages = [];
        $parameters = [];
        foreach ($codings as $coding) {
            $devices = $this->collectPages(fn (int $page) => EHealth::deviceDefinition()->getMany([
                'classification_type_system' => $coding['system'], 'classification_type_code' => $coding['code'],
                'medical_program_id' => $programId ?: null, 'is_active' => true, 'page' => $page,
            ]));
            if ($programId !== '' && $model !== [] && !collect($devices)->contains('id', $model['id'])) {
                $this->invalid('device_id', __('device-requests.validation.model_outside_catalog'));
            }
            if ($programId !== '' && $model !== []) {
                $model = array_replace($model, collect($devices)->firstWhere('id', $model['id']));
            }
            foreach ($model !== [] ? [$model] : $devices as $device) {
                $packaging = $device['packaging'] ?? [];
                $programDevice = null;
                foreach ($device['program_devices'] ?? [] as $row) {
                    if ((!isset($row['medical_program_id']) || $row['medical_program_id'] === $programId)
                        && ($row['is_active'] ?? true)
                        && ($row['device_request_allowed'] ?? ($row['request_allowed'] ?? false))
                        && (empty($row['start_date']) || CarbonImmutable::parse($row['start_date'])->startOfDay()->lessThanOrEqualTo(now()))
                        && (empty($row['end_date']) || CarbonImmutable::parse($row['end_date'])->endOfDay()->greaterThanOrEqualTo(now()))) {
                        $programDevice = $row;
                        break;
                    }
                }
                if ($programId !== '' && $programDevice === null) {
                    continue;
                }
                if ((int) ($packaging['packaging_count'] ?? 0) > 0 && !empty($packaging['packaging_unit'])) {
                    $packages[] = ['count' => (int) $packaging['packaging_count'], 'unit' => strtolower($packaging['packaging_unit']), 'maxDailyCount' => $programDevice['max_daily_count'] ?? null];
                }
            }
            $configs = $this->collectPages(fn (int $page) => EHealth::configuration()->getDevices([
                'code' => $coding['code'], 'system' => $coding['system'], 'is_active' => true, 'page' => $page,
            ]));
            foreach ($configs as $config) {
                $deviceSettings = $this->configurationSettings($config);
                foreach ($deviceSettings['DEVICE_REQUIRED_PARAMETERS'] ?? [] as $requirement) {
                    foreach ($requirement['check'] ?? [] as $parameter) {
                        $key = $parameter['system'].'|'.$parameter['code'];
                        $parameters[$key] ??= $parameter + ['required' => true, 'allowed' => []];
                        $parameters[$key]['required'] = true;
                    }
                }
                foreach ($deviceSettings['DEVICE_PARAMETER_ALLOWED_VALUES'] ?? [] as $rule) {
                    $condition = $rule['condition'];
                    $key = $condition['system'].'|'.$condition['code'];
                    $parameters[$key] ??= $condition + ['required' => false, 'allowed' => []];
                    $values = $rule['check'] ?? [];
                    $parameters[$key]['allowed'] = !empty($parameters[$key]['hasAllowedValues'])
                        ? array_values(array_intersect($parameters[$key]['allowed'], $values)) : $values;
                    $parameters[$key]['hasAllowedValues'] = true;
                }
            }
        }
        // Optional parameters remain available even when this device has no configuration.
        foreach ($this->dictionary('device_request_code_parameter') as $code => $label) {
            $key = 'device_request_code_parameter|'.$code;
            $parameters[$key] ??= ['system' => 'device_request_code_parameter', 'code' => (string) $code, 'required' => false, 'allowed' => []];
            $parameters[$key]['label'] = $label;
        }
        $parameterConfigs = $this->collectPages(fn (int $page) => EHealth::configuration()->getDeviceParameters(['is_active' => true, 'page' => $page]));
        $parameterDictionaries = [];
        foreach ($parameters as &$parameter) {
            $configs = array_filter($parameterConfigs, fn (array $config): bool => ($config['code'] ?? ($config['parameter_code'] ?? '')) === $parameter['code'] && ($config['system'] ?? ($config['parameter_system'] ?? '')) === $parameter['system']);
            $parameter['type'] = 'string';
            $parameter['values'] = [];
            foreach ($configs as $config) {
                $parameterSettings = $this->configurationSettings($config);
                $parameter['type'] = data_get($parameterSettings, 'DEVICE_PARAMETER_DATA_TYPE.check', $parameter['type']);
                $dictionary = data_get($parameterSettings, 'DEVICE_PARAMETER_DICTIONARY.check');
                if ($dictionary) {
                    $parameter['dictionary'] = $dictionary;
                    $parameterDictionaries[$dictionary] ??= $this->dictionary($dictionary);
                    $parameter['values'] = $parameterDictionaries[$dictionary];
                }
            }
            if ($parameter['allowed'] !== [] && $parameter['values'] !== []) {
                $parameter['values'] = array_intersect_key($parameter['values'], array_flip($parameter['allowed']));
            }
        }
        unset($parameter);

        return ['program' => $program, 'packages' => array_values(array_unique($packages, SORT_REGULAR)), 'parameters' => $parameters, 'model' => $model];
    }

    private function parametersForModel(array $parameters, array $properties): array
    {
        $groups = collect($properties)->groupBy(static fn (array $property): string =>
            $property['type']['system'].'|'.$property['type']['code']);
        foreach ($groups as $key => $group) {
            $index = collect($parameters)->search(static fn (array $parameter): bool =>
                data_get($parameter, 'code.coding.0.system').'|'.data_get($parameter, 'code.coding.0.code') === $key);
            $field = 'parameter_values.'.$key;
            if ($index === false) {
                if ($group->count() > 1) {
                    $this->invalid($field, __('device-requests.validation.model_property_required'));
                }
                continue;
            }
            $valueField = collect(array_keys($parameters[$index]))->first(static fn (string $field): bool => str_starts_with($field, 'value_'));
            $value = $this->propertyValueForComparison($parameters[$index][$valueField]);
            $property = $group->first(fn (array $property): bool => array_key_exists($valueField, $property)
                && $this->propertyValueForComparison($property[$valueField]) === $value);
            if ($property === null) {
                $this->invalid($field, __('errors.ehealth.messages.device_parameter_property_mismatch'));
            }
            // Preserve the exact fresh property, including optional text and explicit nulls.
            $parameters[$index][$valueField] = $property[$valueField];
        }

        return $parameters;
    }

    private function propertyValueForComparison(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (string) BigDecimal::of(is_float($value) ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : $value)->stripTrailingZeros();
        }
        if (!is_array($value)) {
            return $value;
        }
        unset($value['text'], $value['unit']);
        if (isset($value['coding'])) {
            $value['coding'] = array_map(static fn (array $coding): array =>
                array_intersect_key($coding, array_flip(['system', 'code'])), $value['coding']);
        }
        foreach ($value as $key => $part) {
            if ($part === null) {
                unset($value[$key]);
            } else {
                $value[$key] = $this->propertyValueForComparison($part);
            }
        }

        ksort($value);

        return $value;
    }

    public function validateQuantity(?int $quantity, string $unit, bool $withProgram, array $packages, ?float $remaining, ?int $daily, CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($quantity === null) {
            if ($withProgram) {
                $this->invalid('quantity', __('device-requests.validation.program_quantity_required'));
            }

            return;
        }
        if ($quantity < 1 || ($remaining !== null && $quantity > $remaining)
            || ($daily !== null && $quantity > $daily * $start->diffInDays($end))) {
            $this->invalid('quantity', __('device-requests.validation.quantity_limit'));
        }
        if ($withProgram && !collect($packages)->contains(fn (array $package): bool => $unit === $package['unit']
            && $quantity % $package['count'] === 0
            && (!isset($package['maxDailyCount']) || $quantity <= $package['maxDailyCount'] * $start->diffInDays($end)))) {
            $this->invalid('quantity', __('device-requests.validation.quantity_packaging'));
        }
    }

    public function occurrenceDates(array $data): array
    {
        $data = $this->normalizeOccurrenceDates($data);
        $start = CarbonImmutable::parse($data['started_at']);
        if ($start->isPast()) {
            $start = CarbonImmutable::now();
        }
        $end = CarbonImmutable::parse($data['ended_at']);
        if ($end->lessThanOrEqualTo($start)) {
            $this->invalid('ended_at', __('device-requests.validation.period_end_after_start'));
        }

        return ['start' => $start, 'end' => $end];
    }

    public function normalizeOccurrenceDates(array $data): array
    {
        foreach (['started_at', 'ended_at'] as $field) {
            $value = $data[$field] ?? '';
            if (is_string($value) && preg_match('/^\d{2}\.\d{2}\.\d{4}$/D', $value)) {
                $date = CarbonImmutable::createFromFormat('!d.m.Y', $value);
                if ($date->format('d.m.Y') !== $value) {
                    $this->invalid($field, __('device-requests.validation.invalid_date'));
                }
                $data[$field] = $date->toDateString();
            }
        }
        Validator::make($data, [
            'started_at' => ['required', 'date', 'after_or_equal:today'],
            'ended_at' => ['required', 'date', 'after:started_at'],
        ], [], __('device-requests.attributes'))->validate();

        return $data;
    }

    public function prepare(Person $person, LegalEntity $legalEntity, array $data, ?string $carePlanUuid = null, ?string $activityUuid = null): array
    {
        $employee = $this->authorizeAction($legalEntity, 'device_request:write');
        $data = $this->normalizeOccurrenceDates($data);
        $data = Validator::make($data, [
            'uuid' => ['required', 'uuid'], 'encounter_uuid' => ['required', 'uuid'],
            'device_id' => ['required', 'string'], 'device_code_system' => ['nullable', 'string'],
            'device_code_type' => ['required', 'in:CLASSIFICATION_TYPE,DEVICE_DEFINITION'],
            'program_id' => ['nullable', 'uuid'], 'quantity' => ['nullable', 'integer', 'min:1'],
            'quantity_code' => ['nullable', 'required_with:quantity', 'string'], 'started_at' => ['required', 'date', 'after_or_equal:today'],
            'ended_at' => ['required', 'date', 'after:started_at'], 'inform_with' => ['nullable', 'uuid'],
            'phone_confirmed' => ['required', 'boolean'], 'reason_reference' => ['nullable', 'array'],
            'confirmed_phone' => ['nullable', 'string'],
            'reason_reference.*.type' => ['required', 'in:condition,observation,diagnostic_report'],
            'reason_reference.*.uuid' => ['required', 'uuid'], 'parameter_values' => ['nullable', 'array'],
        ], [], __('device-requests.attributes'))->validate();
        $data['program_id'] ??= '';
        $data['quantity'] = isset($data['quantity']) && $data['quantity'] !== '' ? (int) $data['quantity'] : null;
        if (isset($data['quantity']) && !array_key_exists($data['quantity_code'], $this->dictionary('device_unit'))) {
            $this->invalid('quantity_code', __('device-requests.validation.quantity_unit_invalid'));
        }
        if (!empty($data['reason_reference'])) {
            $reasons = MedicalEventsRepository::deviceRequest()->reasonOptions($person);
            $seen = [];
            foreach ($data['reason_reference'] as $index => $reason) {
                $key = $reason['type'].'|'.$reason['uuid'];
                if (!isset($reasons[$reason['type']][$reason['uuid']])) {
                    $this->invalid('reason_reference.'.$index.'.uuid', __('device-requests.validation.reason_unavailable'));
                }
                if (isset($seen[$key])) {
                    $this->invalid('reason_reference.'.$index.'.uuid', __('device-requests.validation.reason_duplicate'));
                }
                $seen[$key] = true;
            }
        }
        $encounter = MedicalEventsRepository::deviceRequest()->encounterForPerson($person, $data['encounter_uuid']);
        $employee = $this->authorizeAction($legalEntity, 'device_request:write', $encounter->performer?->value);
        $this->encounter($person, $employee, $data['encounter_uuid']);
        $options = $this->prescribingOptions($data);
        $period = $this->occurrenceDates($data);
        $start = $period['start'];
        $end = $period['end'];
        $settings = $options['program']['medical_program_settings'] ?? ($options['program']['settings'] ?? []);
        if (($settings['care_plan_required'] ?? false) && ($carePlanUuid === null || $activityUuid === null)) {
            $this->invalid('activity', __('device-requests.validation.care_plan_required'));
        }
        if (isset($settings['request_max_period_day']) && $start->diffInDays($end) > (int) $settings['request_max_period_day']) {
            $this->invalid('ended_at', __('device-requests.validation.program_period_limit'));
        }
        $remaining = null;
        if ($carePlanUuid !== null && $activityUuid !== null) {
            $context = $this->carePlanContext($person, $carePlanUuid, $activityUuid, $employee->uuid);
            $detail = $context['detail'];
            $remaining = data_get($detail, 'remaining_quantity.value', $detail['remaining_quantity'] ?? null);
            $remaining = is_numeric($remaining) ? (float) $remaining : null;
            if (isset($detail['quantity']) && $remaining === null) {
                $this->invalid('quantity', __('device-requests.validation.remaining_quantity_unavailable'));
            }
            if (isset($detail['quantity'])) {
                if (!isset($data['quantity'])) {
                    $this->invalid('quantity', __('device-requests.validation.activity_quantity_required'));
                }
                if (($data['quantity_code'] ?? '') !== data_get($detail, 'quantity.code')) {
                    $this->invalid('quantity_code', __('errors.ehealth.messages.quantity_unit_activity_mismatch'));
                }
            }
            $activityProgram = data_get($detail, 'program.identifier.value', '');
            $activityDevice = data_get($detail, 'product_reference.identifier.value', data_get($detail, 'product_codeable_concept.coding.0.code'));
            if ($activityProgram !== ($data['program_id'] ?? '') || (string) $activityDevice !== $data['device_id']) {
                $this->invalid('activity', __('device-requests.validation.activity_mismatch'));
            }
            if ((!empty($detail['scheduled_period']['start']) && $start->lessThan(CarbonImmutable::parse($detail['scheduled_period']['start'])))
                || (!empty($detail['scheduled_period']['end']) && $end->greaterThan(CarbonImmutable::parse($detail['scheduled_period']['end'])))
                || $start->lessThan(CarbonImmutable::parse($context['plan']['period']['start']))
                || (!empty($context['plan']['period']['end']) && $end->greaterThan(CarbonImmutable::parse($context['plan']['period']['end'])))) {
                $this->invalid('ended_at', __('device-requests.validation.activity_period_limit'));
            }
        }
        $this->validateQuantity(
            isset($data['quantity']) ? (int) $data['quantity'] : null,
            $data['quantity_code'] ?? '',
            !empty($data['program_id']),
            $options['packages'],
            $remaining,
            isset($settings['max_daily_count']) ? (int) $settings['max_daily_count'] : null,
            $start,
            $end
        );
        $methods = $this->authMethods($person);
        $method = collect($methods)->firstWhere('id', $data['inform_with'] ?? null);
        if (!empty($data['inform_with']) && !$method) {
            $this->invalid('inform_with', __('device-requests.validation.auth_method_unavailable'));
        }
        $method ??= collect($methods)->firstWhere('type', 'OTP') ?? ($methods[0] ?? []);
        if (!empty($method['phone_number']) && (!$data['phone_confirmed'] || ($data['confirmed_phone'] ?? '') !== $method['phone_number'])) {
            $this->invalid('phone_confirmed', __('device-requests.validation.phone_confirmation_required'));
        }
        $data['inform_with'] = $method['id'] ?? null;
        $data['parameter'] = $this->parameters($data['parameter_values'] ?? [], $options['parameters']);
        $data['parameter'] = $this->parametersForModel($data['parameter'], $options['model']['properties'] ?? []);
        $data['quantity'] = isset($data['quantity']) ? (int) $data['quantity'] : null;
        $data['started_at'] = $start->toIso8601String();
        $data['ended_at'] = $end->toIso8601String();
        $payload = app(DeviceRequestMapper::class)->toCreateSignedContent($data, [
            'person_uuid' => $person->uuid, 'encounter_uuid' => $data['encounter_uuid'],
            'employee_uuid' => $employee->uuid, 'legal_entity_uuid' => $legalEntity->uuid,
        ], $carePlanUuid, $activityUuid);
        // PreQualify requires a program and quantity; Create also supports requests without them.
        if ($data['program_id'] !== '') {
            $prequalify = app(DeviceRequestMapper::class)->toPrequalifyPayload($data, [
                'person_uuid' => $person->uuid, 'encounter_uuid' => $data['encounter_uuid'],
                'employee_uuid' => $employee->uuid, 'legal_entity_uuid' => $legalEntity->uuid,
            ], $carePlanUuid, $activityUuid);
            $resolver = app(EHealthJobResolver::class);
            $resolver->assertPrequalifyValid($resolver->resolve(EHealth::deviceRequest()->prequalify($person->uuid, $prequalify)->validate()));
        }

        return ['payload' => $payload, 'data' => $data, 'employee' => $employee, 'authType' => $method['type'] ?? ''];
    }

    public function parameters(array $values, array $definitions): array
    {
        if (array_diff_key($values, $definitions) !== []) {
            $this->invalid('parameter_values', __('device-requests.validation.parameter_unavailable'));
        }
        $result = [];
        foreach ($definitions as $key => $definition) {
            $value = $values[$key] ?? null;
            $field = 'parameter_values.'.$key;
            if (is_array($value) && collect($value)->flatten()->every(static fn ($item): bool => $item === null || $item === '')) {
                $value = null;
            }
            if ($value === null || $value === '') {
                if ($definition['required']) {
                    $this->invalid($field, __('device-requests.validation.parameter_required', ['name' => $definition['label'] ?? $definition['code']]));
                }
                continue;
            }
            $type = $definition['type'];
            if (!empty($definition['hasAllowedValues']) && $definition['allowed'] === []) {
                $this->invalid($field, __('device-requests.validation.parameter_no_common_value'));
            }
            $parameter = ['code' => ['coding' => [['system' => $definition['system'], 'code' => $definition['code']]]]];
            if ($type === 'boolean') {
                if (!in_array($value, ['0', '1', 0, 1, false, true], true)) {
                    $this->invalid($field, __('device-requests.validation.parameter_boolean'));
                }
                $parameter['value_boolean'] = (bool) $value;
            } elseif ($type === 'codeable_concept') {
                if (!is_scalar($value) || !array_key_exists((string) $value, $definition['values'])) {
                    $this->invalid($field, __('device-requests.validation.parameter_dictionary'));
                }
                $parameter['value_codeable_concept'] = ['coding' => [['system' => $definition['dictionary'], 'code' => (string) $value]]];
            } elseif ($type === 'quantity') {
                if (is_array($value)) {
                    $value['system'] ??= 'eHealth/ucum/units';
                }
                Validator::make(['parameter_values' => [$key => $value]], [$field.'.value' => 'required|numeric', $field.'.code' => 'required|string', $field.'.system' => 'required|string'], [], [
                    $field.'.value' => __('device-requests.form.value'), $field.'.code' => __('device-requests.form.unit'), $field.'.system' => __('device-requests.form.unit_system'),
                ])->validate();
                $parameter['value_quantity'] = ['value' => $value['value'] + 0, 'code' => $value['code'], 'system' => $value['system']];
            } elseif ($type === 'range') {
                foreach (['low', 'high'] as $bound) {
                    if (is_array($value[$bound] ?? null)) {
                        $value[$bound]['system'] ??= 'eHealth/ucum/units';
                    }
                }
                Validator::make(['parameter_values' => [$key => $value]], [
                    $field.'.low.value' => 'required|numeric', $field.'.high.value' => 'required|numeric|gte:'.$field.'.low.value',
                    $field.'.low.code' => 'required|string', $field.'.high.code' => 'required|same:'.$field.'.low.code',
                    $field.'.low.system' => 'required|string', $field.'.high.system' => 'required|same:'.$field.'.low.system',
                ], [], [
                    $field.'.low.value' => __('device-requests.form.minimum'), $field.'.high.value' => __('device-requests.form.maximum'),
                    $field.'.low.code' => __('device-requests.form.unit'), $field.'.high.code' => __('device-requests.form.unit'),
                    $field.'.low.system' => __('device-requests.form.unit_system'), $field.'.high.system' => __('device-requests.form.unit_system'),
                ])->validate();
                $parameter['value_range'] = [];
                foreach (['low', 'high'] as $bound) {
                    $parameter['value_range'][$bound] = ['value' => $value[$bound]['value'] + 0, 'code' => $value[$bound]['code'], 'system' => $value[$bound]['system']];
                }
            } elseif ($type === 'string' && is_string($value)) {
                $parameter['value_string'] = $value;
            } else {
                $this->invalid($field, __('device-requests.validation.parameter_type'));
            }
            if ($definition['allowed'] !== []) {
                $allowed = $type === 'boolean'
                    ? in_array($parameter['value_boolean'], array_map(fn ($item) => filter_var($item, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE), $definition['allowed']), true)
                    : (is_scalar($value)
                        ? in_array((string) $value, array_map('strval', $definition['allowed']), true)
                        : in_array($value, $definition['allowed'], true));
                if (!$allowed) {
                    $this->invalid($field, __('device-requests.validation.parameter_value_disallowed'));
                }
            }
            $result[] = $parameter;
        }

        return $result;
    }

    public function details(Person $person, string $id): array
    {
        Validator::make(['id' => $id], ['id' => 'required|uuid'])->validate();

        $remote = EHealth::deviceRequest()->getById($person->uuid, $id)->validate();
        $this->assertPatientIdentity($person, $remote, $id);

        return $remote;
    }

    public function actionContent(Person $person, LegalEntity $legalEntity, string $id, string $action, string $reason, string $text): array
    {
        abort_unless(in_array($action, ['revoke', 'mark_in_error'], true), 404);
        $this->authorizeAction($legalEntity, 'device_request:'.$action);
        $remote = $this->details($person, $id);
        $this->assertTransition($remote, $action);
        if (data_get($remote, 'requester_legal_entity.identifier.value') !== $legalEntity->uuid) {
            abort(403);
        }
        $dictionary = $action === 'revoke' ? 'device_request_revoke_reasons' : 'device_request_mark_in_error_reasons';
        if (!array_key_exists($reason, $this->dictionary($dictionary)) || ($action === 'mark_in_error' && trim($text) === '')) {
            $this->invalid('reason', __('device-requests.validation.action_reason_required'));
        }
        $remote['status'] = $action === 'revoke' ? 'revoked' : 'entered_in_error';
        $remote['status_reason'] = ['coding' => [['system' => $dictionary, 'code' => $reason]]];
        if (trim($text) !== '') {
            $remote['status_reason']['text'] = trim($text);
        }

        return $remote;
    }

    public function assertTransition(array $remote, string $action): void
    {
        $status = $remote['status'] ?? '';
        if (($action === 'revoke' || $action === 'complete' || $action === 'resend') && $status !== 'active') {
            $this->invalid('status', __('device-requests.validation.active_request_required'));
        }
        if ($action === 'complete' && isset($remote['quantity'])) {
            $this->invalid('quantity', __('device-requests.validation.complete_without_quantity'));
        }
        if ($action === 'mark_in_error' && in_array($status, ['entered-in-error', 'entered_in_error'], true)) {
            $this->invalid('status', __('device-requests.validation.already_entered_in_error'));
        }
    }

    public function submitAction(Person $person, LegalEntity $legalEntity, string $id, string $action, ?string $signedData = null): array
    {
        abort_unless(in_array($action, ['complete', 'revoke', 'mark_in_error'], true), 404);
        $this->authorizeAction($legalEntity, 'device_request:'.$action);
        $remote = $this->details($person, $id);
        $this->assertTransition($remote, $action);
        abort_unless(data_get($remote, 'requester_legal_entity.identifier.value') === $legalEntity->uuid, 403);
        $api = EHealth::deviceRequest();
        $response = match ($action) {
            'complete' => $api->complete($person->uuid, $id),
            'revoke' => $api->revoke($person->uuid, $id, ['signed_data' => $signedData]),
            'mark_in_error' => $api->markInError($person->uuid, $id, ['signed_data' => $signedData]),
        };
        app(EHealthJobResolver::class)->resolve($response->validate());
        $remote = $this->details($person, $id);
        $expected = ['complete' => 'completed', 'revoke' => 'revoked', 'mark_in_error' => 'entered-in-error'][$action];
        if (str_replace('_', '-', $remote['status']) !== $expected) {
            $this->invalid('status', __('device-requests.validation.transition_unconfirmed'));
        }
        if (DeviceRequestRequest::whereUuid($id)->wherePersonId($person->id)->exists()
            || Employee::whereUuid(data_get($remote, 'requester.identifier.value'))->exists()) {
            $this->sync($person, $remote);
        }

        return $remote;
    }

    public function resendOnce(Person $person, LegalEntity $legalEntity, string $id, bool $phoneConfirmed, string $confirmedPhone): void
    {
        $this->authorizeAction($legalEntity, 'device_request:resend');
        Validator::make(['id' => $id], ['id' => 'required|uuid'])->validate();
        $response = EHealth::deviceRequest()->getById($person->uuid, $id);
        $remote = $response->validate();
        $this->assertPatientIdentity($person, $remote, $id);
        $this->assertTransition($remote, 'resend');
        $method = $response->getUrgent()['authentication_method_current'] ?? [];
        if ($method === []) {
            $methods = $this->authMethods($person);
            $method = collect($methods)->firstWhere('id', $remote['inform_with'] ?? null)
                ?? (collect($methods)->firstWhere('type', 'OTP') ?? []);
        }
        if (!$phoneConfirmed || empty($method['phone_number']) || $confirmedPhone !== $method['phone_number']) {
            $this->invalid('phoneConfirmed', __('device-requests.validation.sms_phone_required'));
        }
        $claimed = DB::table('device_request_sms_resends')->insertOrIgnore([
            'device_request_uuid' => $id, 'person_uuid' => $person->uuid, 'attempted_at' => now(),
        ]);
        if ($claimed !== 1) {
            $this->invalid('sms', __('device-requests.validation.sms_already_attempted'));
        }
        try {
            $result = EHealth::deviceRequest()->resendSms($person->uuid, $id)->validate();
            app(EHealthJobResolver::class)->resolve($result);
            DB::table('device_request_sms_resends')->where('device_request_uuid', $id)->update(['confirmed_at' => now()]);
            DeviceRequestRequest::whereUuid($id)->wherePersonId($person->id)->update(['sms_resent_at' => now()]);
        } catch (EHealthValidationException $exception) {
            // An explicit rejection is safe to retry. Timeouts/transport failures retain the claim.
            DB::table('device_request_sms_resends')->where('device_request_uuid', $id)->delete();
            throw $exception;
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() < 500) {
                DB::table('device_request_sms_resends')->where('device_request_uuid', $id)->delete();
            }
            throw $exception;
        }
    }

    public function sync(Person $person, array $remote): DeviceRequestRequest
    {
        SchemaResponseContract::validateOperationData($remote, 'GET /api/patients/{patient_id}/device_requests/{device_request_id}');
        $this->assertPatientIdentity($person, $remote);
        $existing = DeviceRequestRequest::whereUuid($remote['id'])->wherePersonId($person->id)->first();
        $employeeId = $existing?->employeeId ?? Employee::whereUuid(data_get($remote, 'requester.identifier.value'))->value('id');
        if (!$employeeId) {
            $this->invalid('requester', __('device-requests.validation.requester_not_synced'));
        }

        return DeviceRequestRequest::updateOrCreate(['uuid' => $remote['id'], 'person_id' => $person->id], [
            'employeeId' => $employeeId,
            'deviceId' => data_get($remote, 'code_reference.identifier.value', data_get($remote, 'code.coding.0.code', '')),
            'status' => $remote['status'], 'requestNumber' => $remote['requisition'] ?? null,
            'quantity' => data_get($remote, 'quantity.value'), 'remoteDetails' => $remote,
        ]);
    }

    public function createdMessage(string $requisition, bool $withProgram, string $authType): string
    {
        $prefix = __('device-requests.messages.created_prefix', ['requisition' => $requisition]);
        if (in_array($authType, ['OTP', 'THIRD_PERSON'], true)) {
            return $prefix.__($withProgram ? 'device-requests.messages.created_program_otp' : 'device-requests.messages.created_otp');
        }

        return $prefix.__($withProgram ? 'device-requests.messages.created_program_offline' : 'device-requests.messages.created_offline');
    }

    public function printoutHtml(Person $person, string $id): string
    {
        Validator::make(['id' => $id], ['id' => 'required|uuid'])->validate();
        $response = EHealth::deviceRequest()->getById($person->uuid, $id);
        $remote = $response->validate();
        $this->assertPatientIdentity($person, $remote, $id);
        abort_if(empty($remote['requisition']), 409);
        $programId = data_get($remote, 'program.identifier.value');
        $program = $programId ? $this->program($programId, false) : [];
        $urgent = $response->getUrgent();
        if ($programId && !in_array(data_get($urgent, 'authentication_method_current.type'), ['OTP', 'THIRD_PERSON'], true) && empty($urgent['verification_code'])) {
            $this->invalid('printout', __('device-requests.validation.print_code_unavailable'));
        }
        $generator = new BarcodeGeneratorPNG();
        $barcode = base64_encode($generator->getBarcode($remote['requisition'], $generator::TYPE_CODE_128_A, 2, 50));

        return view('livewire.device-request.printout', [
            'record' => $remote, 'person' => $person, 'barcode' => $barcode,
            'urgent' => $urgent, 'program' => $program,
        ])->render();
    }

    private function assertPatientIdentity(Person $person, array $remote, ?string $id = null): void
    {
        if (($id !== null && $remote['id'] !== $id)
            || data_get($remote, 'subject.identifier.value') !== $person->uuid) {
            $this->invalid('id', __('device-requests.validation.configuration_invalid'));
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function configurationSettings(array $configuration): array
    {
        $settings = $configuration['settings'] ?? ($configuration['config'] ?? []);
        if (is_string($settings)) {
            $settings = json_decode($settings, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!is_array($settings)) {
            $this->invalid('configuration', __('device-requests.validation.configuration_invalid'));
        }

        return $settings;
    }

    public function preQualify(array $payload): array
    {
        return $this->callEHealth('Prequalify', static fn (): array => app(DeviceRequest::class)->preQualify($payload)->validate());
    }

    public function createDraft(array $payload): array
    {
        return $this->callEHealth('Create Draft', static fn (): array => app(DeviceRequest::class)->createDeviceRequest($payload)->validate());
    }

    public function sign(string $id, array $payload): array
    {
        $payload = $this->normalizeSignedPayload($payload);

        return $this->callEHealth('Sign', static fn (): array => app(DeviceRequest::class)->signDeviceRequest($id, $payload)->validate());
    }

    public function reject(string $id, array $payload): array
    {
        return $this->callEHealth('Reject', static fn (): array => app(DeviceRequest::class)->rejectDeviceRequest($id, $payload)->validate());
    }

    protected function requestType(): string
    {
        return 'Device Request';
    }

    /**
     * Device Request expects the KEP blob under signed_device_request_request.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeSignedPayload(array $payload): array
    {
        if (isset($payload['signed_content']) && !isset($payload['signed_device_request_request'])) {
            $payload['signed_device_request_request'] = $payload['signed_content'];
            unset($payload['signed_content']);
        }

        $payload['signed_content_encoding'] ??= 'base64';

        return $payload;
    }
}
