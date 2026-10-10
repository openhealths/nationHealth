<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\Api\DeviceRequest;
use App\Contracts\EHealthRequestLifecycleContract;
use App\Classes\eHealth\EHealth;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeviceRequestLifecycleService extends EHealthRequestLifecycleService implements EHealthRequestLifecycleContract
{
    public function authorizeAction(LegalEntity $legalEntity, string $scope): Employee
    {
        $user = Auth::user();
        abort_unless($user?->can($scope) && (int) getPermissionsTeamId() === (int) $legalEntity->id, 403);
        $roles = $scope === 'device_request:write'
            ? ['DOCTOR', 'SPECIALIST']
            : ['DOCTOR', 'SPECIALIST', 'ASSISTANT', 'MED_COORDINATOR', 'MED_ADMIN'];
        $employee = $user->employees()->whereLegalEntityId($legalEntity->id)
            ->active()->whereIn('employee_type', $roles)->first();
        abort_unless($employee !== null, 403);

        return $employee;
    }

    /** Read every page; incomplete configurations must not change prescribing rules. */
    public function collectPages(callable $fetch): array
    {
        $items = [];
        $page = 1;
        do {
            $response = $fetch($page++);
            $paging = $response->getPaging();
            if (!isset($paging['page_number'], $paging['total_pages'])
                || (int) $paging['page_number'] !== $page - 1) {
                $this->invalid('configuration', 'Не вдалося отримати повний актуальний перелік з ЕСОЗ.');
            }
            $items = array_merge($items, $response->getData());
        } while ($response->isNotLast());

        return $items;
    }

    public function dictionary(string $name): array
    {
        $rows = EHealth::dictionary()->getMany(['name' => $name, 'is_active' => true])->getData();
        $values = [];
        foreach ($rows as $row) {
            foreach ($row['values'] ?? [] as $code => $value) {
                if (is_array($value)) {
                    if (($value['is_active'] ?? true) !== true) {
                        continue;
                    }
                    $values[(string) ($value['code'] ?? $code)] = (string) ($value['description'] ?? $value['display_value'] ?? $code);
                } else {
                    $values[(string) $code] = (string) $value;
                }
            }
        }

        return $values;
    }

    public function permittedTypes(bool $withProgram): array
    {
        $name = $withProgram ? 'prescribable_device_codes' : 'assistive_devices';
        $rows = $this->collectPages(fn (int $page) => EHealth::configuration()->getDictionaries([
            'name' => $name, 'is_active' => true, 'page' => $page,
        ]));
        $types = [];
        foreach ($rows as $row) {
            foreach ($row['content'] ?? $row['settings'] ?? [] as $entry) {
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
        return EHealth::person()->getAuthMethods($person->uuid)->getData();
    }

    public function program(string $id, bool $requireActive = true): array
    {
        $program = EHealth::medicalProgram()->getById($id)->getData();
        if (($program['id'] ?? '') !== $id || ($program['type'] ?? '') !== 'DEVICE'
            || ($requireActive && !($program['is_active'] ?? false))) {
            $this->invalid('program_id', 'Медична програма недоступна або неактивна.');
        }

        return $program;
    }

    public function encounter(Person $person, Employee $employee, string $uuid): Encounter
    {
        $encounter = $person->encounters()->whereUuid($uuid)->with(['performer', 'period'])->firstOrFail();
        $end = $encounter->period?->end;
        if ($encounter->performer?->value !== $employee->uuid || !$end
            || !CarbonImmutable::parse($end)->isToday()
            || $encounter->status?->value !== 'finished') {
            $this->invalid('encounter', 'Оберіть завершену сьогодні взаємодію, виконавцем якої є поточний лікар.');
        }

        return $encounter;
    }

    public function carePlanContext(Person $person, string $carePlanUuid, string $activityUuid): array
    {
        $plan = EHealth::carePlan()->getDetails($person->uuid, $carePlanUuid)->getData();
        $activity = EHealth::carePlanActivity()->getDetails($person->uuid, $carePlanUuid, $activityUuid)->getData();
        $detail = $activity['detail'] ?? $activity;
        if (($plan['status'] ?? '') !== 'active'
            || !in_array($detail['status'] ?? $activity['status'] ?? '', ['scheduled', 'in-progress', 'active'], true)
            || ($detail['kind'] ?? $activity['kind'] ?? '') !== 'device_request') {
            $this->invalid('activity', 'План лікування та призначення мають бути активними.');
        }
        foreach ([$plan['period'] ?? [], $detail['scheduled_period'] ?? []] as $period) {
            if (empty($period['start'])
                || CarbonImmutable::parse($period['start'])->isFuture()
                || (!empty($period['end']) && CarbonImmutable::parse($period['end'])->isPast())) {
                $this->invalid('activity', 'Період дії плану лікування або призначення не дозволяє виписування.');
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
            $this->invalid('program_id', 'Медична програма неактивна.');
        }
        $permitted = $this->permittedTypes($programId !== '');
        $model = [];
        if (($data['device_code_type'] ?? '') === 'DEVICE_DEFINITION') {
            $model = EHealth::deviceDefinition()->getById($data['device_id'])->getData();
            if (($model['is_active'] ?? false) !== true) {
                $this->invalid('device_id', 'Модель медичного виробу неактивна.');
            }
            $codings = $model['classification_types'] ?? [];
        } else {
            $codings = [['system' => $data['device_code_system'] ?? '', 'code' => $data['device_id'] ?? '']];
        }
        $codings = array_map(static fn (array $coding): array => $coding['coding'][0] ?? $coding, $codings);
        if (!collect($codings)->contains(fn (array $coding): bool => isset($permitted[($coding['system'] ?? '').'|'.($coding['code'] ?? '')]))) {
            $this->invalid('device_id', $programId !== '' ? 'Тип виробу не дозволено для виписування.' : 'Без програми дозволено лише допоміжні засоби реабілітації.');
        }
        $settings = $program['medical_program_settings'] ?? $program['settings'] ?? [];
        if ($programId !== '' && ($program['device_request_allowed'] ?? true) === false) {
            $this->invalid('program_id', 'Програма не дозволяє створення е-запитів на медичні вироби.');
        }
        if ($programId !== '') {
            $assistive = $this->permittedTypes(false);
            if (collect($codings)->contains(fn (array $coding): bool => isset($assistive[$coding['system'].'|'.$coding['code']]))) {
                $this->invalid('program_id', 'Допоміжні засоби реабілітації призначаються без медичної програми.');
            }
        }
        $allowedTypes = $settings['device_request_allowed_code_types'] ?? [];
        if ($programId !== '' && $allowedTypes !== [] && !in_array($data['device_code_type'], $allowedTypes, true)) {
            $this->invalid('device_code_type', 'Цей спосіб вибору виробу не дозволено медичною програмою.');
        }
        $packages = [];
        $parameters = [];
        foreach ($codings as $coding) {
            $devices = $this->collectPages(fn (int $page) => EHealth::deviceDefinition()->getMany([
                'classification_type_system' => $coding['system'], 'classification_type_code' => $coding['code'],
                'medical_program_id' => $programId ?: null, 'is_active' => true, 'page' => $page,
            ]));
            if ($programId !== '' && $model !== [] && !collect($devices)->contains('id', $model['id'])) {
                $this->invalid('device_id', 'Модель не входить до актуального каталогу програми.');
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
                        && ($row['device_request_allowed'] ?? $row['request_allowed'] ?? false)
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
            $configs = array_filter($parameterConfigs, fn (array $config): bool => ($config['code'] ?? $config['parameter_code'] ?? '') === $parameter['code'] && ($config['system'] ?? $config['parameter_system'] ?? '') === $parameter['system']);
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

    public function validateQuantity(?int $quantity, string $unit, bool $withProgram, array $packages, ?float $remaining, ?int $daily, CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($quantity === null) {
            if ($withProgram) {
                $this->invalid('quantity', 'Для медичної програми кількість обов’язкова.');
            }

            return;
        }
        if ($quantity < 1 || ($remaining !== null && $quantity > $remaining)
            || ($daily !== null && $quantity > $daily * $start->diffInDays($end))) {
            $this->invalid('quantity', 'Кількість перевищує залишок призначення або дозволену кількість на період.');
        }
        if ($withProgram && !collect($packages)->contains(fn (array $package): bool => $unit === $package['unit']
            && $quantity % $package['count'] === 0
            && (!isset($package['maxDailyCount']) || $quantity <= $package['maxDailyCount'] * $start->diffInDays($end)))) {
            $this->invalid('quantity', 'Кількість має бути кратною доступній упаковці, одиниці виміру мають збігатися.');
        }
    }

    public function occurrenceDates(array $data): array
    {
        $start = CarbonImmutable::parse($data['started_at']);
        if ($start->isPast()) {
            $start = CarbonImmutable::now();
        }
        $end = CarbonImmutable::parse($data['ended_at']);
        if ($end->lessThanOrEqualTo($start)) {
            $this->invalid('ended_at', 'Кінець періоду має бути пізніше поточного початку лікування.');
        }

        return ['start' => $start, 'end' => $end];
    }

    public function prepare(Person $person, LegalEntity $legalEntity, array $data, ?string $carePlanUuid = null, ?string $activityUuid = null): array
    {
        $employee = $this->authorizeAction($legalEntity, 'device_request:write');
        $data = Validator::make($data, [
            'uuid' => ['required', 'uuid'], 'encounter_uuid' => ['required', 'uuid'],
            'device_id' => ['required', 'string'], 'device_code_system' => ['nullable', 'string'],
            'device_code_type' => ['required', 'in:CLASSIFICATION_TYPE,DEVICE_DEFINITION'],
            'program_id' => ['nullable', 'uuid'], 'quantity' => ['nullable', 'integer', 'min:1'],
            'quantity_code' => ['nullable', 'string'], 'started_at' => ['required', 'date', 'after_or_equal:today'],
            'ended_at' => ['required', 'date', 'after:started_at'], 'inform_with' => ['nullable', 'uuid'],
            'phone_confirmed' => ['required', 'boolean'], 'reason_reference' => ['nullable', 'array'],
            'confirmed_phone' => ['nullable', 'string'],
            'reason_reference.*.type' => ['required', 'in:condition,observation,diagnostic_report'],
            'reason_reference.*.uuid' => ['required', 'uuid'], 'parameter_values' => ['nullable', 'array'],
        ])->validate();
        $this->encounter($person, $employee, $data['encounter_uuid']);
        $options = $this->prescribingOptions($data);
        $period = $this->occurrenceDates($data);
        $start = $period['start'];
        $end = $period['end'];
        $settings = $options['program']['medical_program_settings'] ?? $options['program']['settings'] ?? [];
        if (($settings['care_plan_required'] ?? false) && ($carePlanUuid === null || $activityUuid === null)) {
            $this->invalid('activity', 'Для обраної програми е-запит потрібно створити з призначення плану лікування.');
        }
        if (isset($settings['request_max_period_day']) && $start->diffInDays($end) > (int) $settings['request_max_period_day']) {
            $this->invalid('ended_at', 'Період перевищує обмеження медичної програми. Оновіть дані форми.');
        }
        $remaining = null;
        if ($carePlanUuid !== null && $activityUuid !== null) {
            $context = $this->carePlanContext($person, $carePlanUuid, $activityUuid);
            $detail = $context['detail'];
            $remaining = data_get($detail, 'remaining_quantity.value', $detail['remaining_quantity'] ?? null);
            $remaining = is_numeric($remaining) ? (float) $remaining : null;
            if (isset($detail['quantity']) && $remaining === null) {
                $this->invalid('quantity', 'ЕСОЗ не повернула актуальний залишок призначення.');
            }
            $activityProgram = data_get($detail, 'program.identifier.value', '');
            $activityDevice = data_get($detail, 'product_reference.identifier.value', data_get($detail, 'product_codeable_concept.coding.0.code'));
            if ($activityProgram !== ($data['program_id'] ?? '') || (string) $activityDevice !== $data['device_id']) {
                $this->invalid('activity', 'Програма або виріб не відповідає актуальному призначенню плану лікування.');
            }
            if ($start->lessThan(CarbonImmutable::parse($detail['scheduled_period']['start']))
                || (!empty($detail['scheduled_period']['end']) && $end->greaterThan(CarbonImmutable::parse($detail['scheduled_period']['end'])))
                || $start->lessThan(CarbonImmutable::parse($context['plan']['period']['start']))
                || (!empty($context['plan']['period']['end']) && $end->greaterThan(CarbonImmutable::parse($context['plan']['period']['end'])))) {
                $this->invalid('ended_at', 'Період запиту виходить за межі призначення.');
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
            $this->invalid('inform_with', 'Обраний метод автентифікації більше недоступний.');
        }
        $method ??= collect($methods)->firstWhere('type', 'OTP') ?? ($methods[0] ?? []);
        if (!empty($method['phone_number']) && (!$data['phone_confirmed'] || ($data['confirmed_phone'] ?? '') !== $method['phone_number'])) {
            $this->invalid('phone_confirmed', 'Створення е-Запиту на медичний виріб неможливе. Для виписування необхідно змінити номер телефону для автентифікації. Якщо попередній номер телефону доступний – створіть новий метод автентифікації в картці пацієнта через МІС. У разі відсутності старого номеру телефону – за потреби, зверніться до інформаційно-довідкової служби НСЗУ (номер телефону: 1677) для отримання роз’яснень щодо процедури скидання номеру телефону для автентифікації та після його скидання додайте новий метод автентифікації цьому пацієнту, після чого повторіть спробу виписування е-Запиту на медичний виріб.');
        }
        $data['inform_with'] = $method['id'] ?? null;
        $data['parameter'] = $this->parameters($data['parameter_values'] ?? [], $options['parameters']);
        $data['quantity'] = isset($data['quantity']) ? (int) $data['quantity'] : null;
        $data['started_at'] = $start->toIso8601String();
        $data['ended_at'] = $end->toIso8601String();
        $payload = app(Mappers\DeviceRequestMapper::class)->toCreateSignedContent($data, [
            'person_uuid' => $person->uuid, 'encounter_uuid' => $data['encounter_uuid'],
            'employee_uuid' => $employee->uuid, 'legal_entity_uuid' => $legalEntity->uuid,
        ], $carePlanUuid, $activityUuid);
        $prequalify = app(Mappers\DeviceRequestMapper::class)->toPrequalifyPayload($data, [
            'person_uuid' => $person->uuid, 'encounter_uuid' => $data['encounter_uuid'],
            'employee_uuid' => $employee->uuid, 'legal_entity_uuid' => $legalEntity->uuid,
        ], $carePlanUuid, $activityUuid);
        $resolver = app(EHealthJobResolver::class);
        $resolver->assertPrequalifyValid($resolver->resolve(EHealth::deviceRequest()->prequalify($person->uuid, $prequalify)->getData()));

        return ['payload' => $payload, 'data' => $data, 'employee' => $employee, 'authType' => $method['type'] ?? ''];
    }

    public function parameters(array $values, array $definitions): array
    {
        if (array_diff_key($values, $definitions) !== []) {
            $this->invalid('parameter_values', 'Характеристика більше не доступна. Оновіть конфігурацію форми.');
        }
        $result = [];
        foreach ($definitions as $key => $definition) {
            $value = $values[$key] ?? null;
            if ($value === null || $value === '') {
                if ($definition['required']) {
                    $this->invalid('parameter_values', 'Заповніть обов’язкову характеристику: '.($definition['label'] ?? $definition['code']));
                }
                continue;
            }
            $type = $definition['type'];
            if (!empty($definition['hasAllowedValues']) && $definition['allowed'] === []) {
                $this->invalid('parameter_values', 'Конфігурації виробу не містять спільного дозволеного значення характеристики.');
            }
            $parameter = ['code' => ['coding' => [['system' => $definition['system'], 'code' => $definition['code']]]]];
            if ($type === 'boolean') {
                if (!in_array($value, ['0', '1', 0, 1, false, true], true)) {
                    $this->invalid('parameter_values', 'Оберіть «так» або «ні».');
                }
                $parameter['value_boolean'] = (bool) $value;
            } elseif ($type === 'codeable_concept') {
                if (!array_key_exists((string) $value, $definition['values'])) {
                    $this->invalid('parameter_values', 'Значення характеристики не дозволено довідником або конфігурацією.');
                }
                $parameter['value_codeable_concept'] = ['coding' => [['system' => $definition['dictionary'], 'code' => (string) $value]]];
            } elseif ($type === 'quantity') {
                Validator::make(['value' => $value], ['value.value' => 'required|numeric', 'value.code' => 'required|string', 'value.system' => 'required|string'])->validate();
                $parameter['value_quantity'] = $value;
            } elseif ($type === 'range') {
                Validator::make(['value' => $value], ['value.low.value' => 'required|numeric', 'value.high.value' => 'required|numeric|gte:value.low.value', 'value.low.code' => 'required|string', 'value.high.code' => 'required|string', 'value.low.system' => 'required|string', 'value.high.system' => 'required|string'])->validate();
                $parameter['value_range'] = $value;
            } elseif ($type === 'string' && is_string($value)) {
                $parameter['value_string'] = $value;
            } else {
                $this->invalid('parameter_values', 'Тип характеристики не підтримується конфігурацією.');
            }
            if ($definition['allowed'] !== []) {
                $allowed = $type === 'boolean'
                    ? in_array($parameter['value_boolean'], array_map(fn ($item) => filter_var($item, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE), $definition['allowed']), true)
                    : (is_scalar($value)
                        ? in_array((string) $value, array_map('strval', $definition['allowed']), true)
                        : in_array($value, $definition['allowed'], true));
                if (!$allowed) {
                    $this->invalid('parameter_values', 'Значення характеристики не дозволено для цього виробу.');
                }
            }
            $result[] = $parameter;
        }

        return $result;
    }

    public function details(Person $person, string $id): array
    {
        Validator::make(['id' => $id], ['id' => 'required|uuid'])->validate();

        return EHealth::deviceRequest()->getById($person->uuid, $id)->getData();
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
            $this->invalid('reason', 'Оберіть причину з довідника та заповніть пояснення.');
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
            $this->invalid('status', 'Дія доступна лише для активного е-запиту.');
        }
        if ($action === 'complete' && isset($remote['quantity'])) {
            $this->invalid('quantity', 'Завершення дозволене лише для запиту без кількості.');
        }
        if ($action === 'mark_in_error' && in_array($status, ['entered-in-error', 'entered_in_error'], true)) {
            $this->invalid('status', 'Запит уже позначено внесеним помилково.');
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
        app(EHealthJobResolver::class)->resolve($response->getData());
        $remote = $this->details($person, $id);
        $expected = ['complete' => 'completed', 'revoke' => 'revoked', 'mark_in_error' => 'entered-in-error'][$action];
        if (str_replace('_', '-', $remote['status']) !== $expected) {
            $this->invalid('status', 'ЕСОЗ ще не підтвердила зміну статусу. Оновіть список.');
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
        $remote = $response->getData();
        $this->assertTransition($remote, 'resend');
        $method = $response->getUrgent()['authentication_method_current'] ?? [];
        if ($method === []) {
            $methods = $this->authMethods($person);
            $method = collect($methods)->firstWhere('id', $remote['inform_with'] ?? null)
                ?? collect($methods)->firstWhere('type', 'OTP') ?? [];
        }
        if (!$phoneConfirmed || empty($method['phone_number']) || $confirmedPhone !== $method['phone_number']) {
            $this->invalid('phoneConfirmed', 'Перед одноразовим повторним СМС підтвердьте актуальність номера телефону пацієнта.');
        }
        $claimed = DB::table('device_request_sms_resends')->insertOrIgnore([
            'device_request_uuid' => $id, 'person_uuid' => $person->uuid, 'attempted_at' => now(),
        ]);
        if ($claimed !== 1) {
            $this->invalid('sms', 'Повторне СМС вже надіслано або його результат ще уточнюється. Повторна спроба недоступна.');
        }
        try {
            $result = EHealth::deviceRequest()->resendSms($person->uuid, $id)->getData();
            app(EHealthJobResolver::class)->resolve($result);
            DB::table('device_request_sms_resends')->where('device_request_uuid', $id)->update(['confirmed_at' => now()]);
            DeviceRequestRequest::whereUuid($id)->wherePersonId($person->id)->update(['sms_resent_at' => now()]);
        } catch (\App\Exceptions\EHealth\EHealthValidationException $exception) {
            // An explicit rejection is safe to retry. Timeouts/transport failures retain the claim.
            DB::table('device_request_sms_resends')->where('device_request_uuid', $id)->delete();
            throw $exception;
        } catch (\App\Exceptions\EHealth\EHealthResponseException $exception) {
            if ($exception->response->status() < 500) {
                DB::table('device_request_sms_resends')->where('device_request_uuid', $id)->delete();
            }
            throw $exception;
        }
    }

    public function sync(Person $person, array $remote): DeviceRequestRequest
    {
        $existing = DeviceRequestRequest::whereUuid($remote['id'])->wherePersonId($person->id)->first();
        $employeeId = $existing?->employeeId ?? Employee::whereUuid(data_get($remote, 'requester.identifier.value'))->value('id');
        if (!$employeeId) {
            $this->invalid('requester', 'Спочатку синхронізуйте лікаря, який створив запит, для локального обліку.');
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
        $remote = $response->getData();
        abort_if(empty($remote['requisition']), 409);
        $programId = data_get($remote, 'program.identifier.value');
        $program = $programId ? $this->program($programId, false) : [];
        $urgent = $response->getUrgent();
        if ($programId && !in_array(data_get($urgent, 'authentication_method_current.type'), ['OTP', 'THIRD_PERSON'], true) && empty($urgent['verification_code'])) {
            $this->invalid('printout', 'ЕСОЗ не повернула код погашення для обов’язкової друкованої пам’ятки.');
        }
        $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();
        $barcode = base64_encode($generator->getBarcode($remote['requisition'], $generator::TYPE_CODE_128_A, 2, 50));

        return view('livewire.device-request.printout', [
            'record' => $remote, 'person' => $person, 'barcode' => $barcode,
            'urgent' => $urgent, 'program' => $program,
        ])->render();
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function configurationSettings(array $configuration): array
    {
        $settings = $configuration['settings'] ?? $configuration['config'] ?? [];
        if (is_string($settings)) {
            $settings = json_decode($settings, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!is_array($settings)) {
            $this->invalid('configuration', 'ЕСОЗ повернула некоректну конфігурацію.');
        }

        return $settings;
    }

    public function preQualify(array $payload): array
    {
        return $this->callEHealth('Prequalify', static fn (): array => app(DeviceRequest::class)->preQualify($payload)->getData());
    }

    public function createDraft(array $payload): array
    {
        return $this->callEHealth('Create Draft', static fn (): array => app(DeviceRequest::class)->createDeviceRequest($payload)->getData());
    }

    public function sign(string $id, array $payload): array
    {
        $payload = $this->normalizeSignedPayload($payload);

        return $this->callEHealth('Sign', static fn (): array => app(DeviceRequest::class)->signDeviceRequest($id, $payload)->getData());
    }

    public function reject(string $id, array $payload): array
    {
        return $this->callEHealth('Reject', static fn (): array => app(DeviceRequest::class)->rejectDeviceRequest($id, $payload)->getData());
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
