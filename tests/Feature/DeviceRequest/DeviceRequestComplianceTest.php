<?php

declare(strict_types=1);

namespace Tests\Feature\DeviceRequest;

use App\Classes\eHealth\Api\CarePlan;
use App\Classes\eHealth\Api\CarePlanActivity;
use App\Classes\eHealth\Api\Configuration;
use App\Classes\eHealth\Api\DeviceDefinition;
use App\Classes\eHealth\Api\Patient\DeviceRequest as DeviceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Repositories\MedicalEvents\PeriodRepository;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeviceRequestComplianceTest extends TestCase
{
    use DatabaseTransactions;

    private Person $person;
    private LegalEntity $legalEntity;
    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $this->travelTo(CarbonImmutable::parse('2026-10-29 23:30:00', (string) config('app.timezone')));
        $this->person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $this->legalEntity = new LegalEntity(['uuid' => (string) Str::uuid()]);
        $this->requestId = (string) Str::uuid();
    }

    private function response(array $data, array $urgent = [], array $paging = [], bool $validated = false): EHealthResponse
    {
        if ($validated) {
            $response = Mockery::mock(EHealthResponse::class);
            $response->shouldReceive('validate')->once()->andReturn($data);

            return $response;
        }

        return new EHealthResponse(new Response(200, [], json_encode(compact('data', 'urgent', 'paging'), JSON_THROW_ON_ERROR)));
    }

    private function service(): DeviceRequestLifecycleService
    {
        $service = Mockery::mock(DeviceRequestLifecycleService::class)->makePartial();
        $service->shouldReceive('authorizeAction')->andReturn(new Employee());

        return $service;
    }

    public function test_all_configuration_pages_are_read(): void
    {
        $pages = [];
        $items = app(DeviceRequestLifecycleService::class)->collectPages(function (int $page) use (&$pages): EHealthResponse {
            $pages[] = $page;

            return $this->response([['id' => $page]], paging: ['page_number' => $page, 'total_pages' => 3]);
        });
        $this->assertSame([1, 2, 3], $pages);
        $this->assertCount(3, $items);
    }

    public function test_nested_device_dictionary_codes_are_available_and_inactive_branches_are_excluded(): void
    {
        $config = Mockery::mock(Configuration::class);
        $config->shouldReceive('getDictionaries')->once()->with(['name' => 'assistive_devices', 'is_active' => true, 'page' => 1])
            ->andReturn($this->response([['settings' => [['system' => 'eHealth/assistive_devices', 'codes' => ['leaf', 'inactive-leaf', 'hidden-leaf']]]]], paging: ['page_number' => 1, 'total_pages' => 1]));
        $this->instance(Configuration::class, $config);
        $dictionary = Mockery::mock(\App\Classes\eHealth\Api\Dictionary::class);
        $dictionary->shouldReceive('getMany')->once()->with(['name' => 'eHealth/assistive_devices', 'is_active' => true])
            ->andReturn($this->response([['name' => 'eHealth/assistive_devices', 'values' => [
                ['code' => 'parent', 'description' => 'Категорія', 'is_active' => true, 'child_values' => [
                    ['code' => 'branch', 'description' => 'Група', 'is_active' => true, 'child_values' => [
                        ['code' => 'leaf', 'description' => 'Виріб', 'is_active' => true],
                        ['code' => 'inactive-leaf', 'description' => 'Неактивний виріб', 'is_active' => false],
                    ]],
                ]],
                ['code' => 'inactive-parent', 'description' => 'Неактивна категорія', 'is_active' => false, 'child_values' => [
                    ['code' => 'hidden-leaf', 'description' => 'Прихований виріб', 'is_active' => true],
                ]],
            ]]]));
        $this->instance(\App\Classes\eHealth\Api\Dictionary::class, $dictionary);
        $this->assertSame(
            ['eHealth/assistive_devices|leaf' => ['system' => 'eHealth/assistive_devices', 'code' => 'leaf', 'label' => 'Виріб']],
            app(DeviceRequestLifecycleService::class)->permittedTypes(false)
        );
    }

    public function test_plan_details_render_local_references_and_status_reason_without_a_remote_read(): void
    {
        $resource = [
            'requisition' => 'PLAN-1234', 'status' => 'cancelled',
            'category' => ['coding' => [['system' => 'eHealth/care_plan_categories', 'code' => 'devices']]],
            'title' => '<script>unsafe</script>', 'period' => ['start' => '2026-10-10'],
            'encounter' => ['identifier' => ['value' => (string) Str::uuid()]],
            'supportingInfo' => [['identifier' => ['value' => (string) Str::uuid()]]],
            'addresses' => [],
            'statusReason' => ['coding' => [['code' => 'other', 'system' => 'eHealth/care_plan_cancel_reasons']], 'text' => 'Confirmed reason'],
            'note' => 'Note',
        ];
        $plan = new \App\Models\CarePlan(['uuid' => (string) Str::uuid(), 'status' => 'cancelled', 'person_id' => $this->person->id]);
        $repository = Mockery::mock(\App\Repositories\CarePlanRepository::class)->makePartial();
        $repository->shouldReceive('getResource')->once()->with($plan)->andReturn($resource);
        $this->instance(\App\Repositories\CarePlanRepository::class, $repository);
        $user = Mockery::mock(\App\Models\User::class);
        $user->shouldReceive('can')->once()->with('view', $plan)->andReturnTrue();
        \Illuminate\Support\Facades\Auth::shouldReceive('user')->once()->andReturn($user);
        $this->mock(CarePlan::class)->shouldNotReceive('getDetails');

        $component = new \App\Livewire\CarePlan\CarePlanShow();
        $component->carePlan = $plan;
        $component->refreshDisplayedDetails();
        $record = $component->remotePlanDetails;
        $this->assertSame('PLAN-1234', $record['requisition']);
        $this->assertSame('Confirmed reason', $record['status_reason']);
        $this->assertSame([__('care-plan.reference_unavailable')], $record['supporting_info']);
        $html = view('livewire.care-plan.remote-details', compact('record'))->render();
        foreach (['PLAN-1234', 'Confirmed reason', __('care-plan.no_end_date'), '&lt;script&gt;'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_incomplete_paging_cannot_silently_change_rules(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->collectPages(fn () => $this->response([['id' => 1]]));
    }

    public function test_plan_details_are_cleared_without_read_permission(): void
    {
        $plan = new \App\Models\CarePlan(['uuid' => (string) Str::uuid(), 'status' => 'active']);
        $user = Mockery::mock(\App\Models\User::class);
        $user->shouldReceive('can')->once()->with('view', $plan)->andReturnFalse();
        \Illuminate\Support\Facades\Auth::shouldReceive('user')->once()->andReturn($user);
        $service = Mockery::mock(\App\Classes\eHealth\Api\CarePlan::class);
        $service->shouldNotReceive('getDetails');
        $this->instance(\App\Classes\eHealth\Api\CarePlan::class, $service);
        $component = new \App\Livewire\CarePlan\CarePlanShow();
        $component->carePlan = $plan;
        $component->remotePlanDetails = ['requisition' => 'stale'];

        $component->refreshDisplayedDetails();

        $this->assertSame([], $component->remotePlanDetails);
    }

    public static function blockedStates(): array
    {
        return [['terminated', 'scheduled'], ['cancelled', 'scheduled'], ['completed', 'scheduled'], ['active', 'cancelled'], ['active', 'completed']];
    }

    private function bindPlan(string $planStatus = 'active', string $activityStatus = 'scheduled', ?string $end = null): array
    {
        $planUuid = (string) Str::uuid();
        $activityUuid = (string) Str::uuid();
        $localPlan = new \App\Models\CarePlan(['uuid' => $planUuid]);
        $repository = Mockery::mock(\App\Repositories\CarePlanRepository::class)->makePartial();
        $repository->shouldReceive('findByUuid')->once()->with($planUuid, legalEntity()?->id, $this->person->id)->andReturn($localPlan);
        $this->instance(\App\Repositories\CarePlanRepository::class, $repository);
        \Illuminate\Support\Facades\Gate::shouldReceive('authorize')->once()->with('manageActivities', $localPlan)->andReturn(\Illuminate\Auth\Access\Response::allow());
        $plan = Mockery::mock(CarePlan::class);
        $plan->shouldReceive('getDetails')->andReturn($this->response(['status' => $planStatus, 'period' => ['start' => now()->subDay()->toIso8601String(), 'end' => $end]], validated: true));
        $activity = Mockery::mock(CarePlanActivity::class);
        $activity->shouldReceive('getDetails')->andReturn($this->response(['detail' => ['kind' => 'device_request', 'status' => $activityStatus, 'scheduled_period' => ['start' => now()->subDay()->toIso8601String(), 'end' => $end]]], validated: true));
        $this->instance(CarePlan::class, $plan);
        $this->instance(CarePlanActivity::class, $activity);

        return [$planUuid, $activityUuid];
    }

    #[DataProvider('blockedStates')]
    public function test_terminal_plan_or_activity_blocks_prescribing(string $planStatus, string $activityStatus): void
    {
        [$planUuid, $activityUuid] = $this->bindPlan($planStatus, $activityStatus);
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->carePlanContext($this->person, $planUuid, $activityUuid);
    }

    public function test_optional_end_is_supported(): void
    {
        [$planUuid, $activityUuid] = $this->bindPlan();
        $context = app(DeviceRequestLifecycleService::class)->carePlanContext($this->person, $planUuid, $activityUuid);
        $this->assertSame('device_request', $context['detail']['kind']);
    }

    public function test_expired_period_blocks_prescribing(): void
    {
        [$planUuid, $activityUuid] = $this->bindPlan(end: now()->subHour()->toIso8601String());
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->carePlanContext($this->person, $planUuid, $activityUuid);
    }

    public static function actions(): array
    {
        return [['revoke', 'revoked', 'device_request_revoke_reasons'], ['mark_in_error', 'entered_in_error', 'device_request_mark_in_error_reasons']];
    }

    #[DataProvider('actions')]
    public function test_status_change_signs_full_remote_resource(string $action, string $status, string $dictionary): void
    {
        $remote = ['id' => $this->requestId, 'status' => 'active', 'requester_legal_entity' => ['identifier' => ['value' => $this->legalEntity->uuid]], 'reason' => [['display_value' => 'Причина']], 'parameter' => [['value_boolean' => false]], 'requisition' => 'ABCD-1234-5678-9012'];
        $service = $this->service();
        $service->shouldReceive('details')->andReturn($remote);
        $service->shouldReceive('dictionary')->with($dictionary)->andReturn(['wrong' => 'Помилка']);
        $signed = $service->actionContent($this->person, $this->legalEntity, $this->requestId, $action, 'wrong', 'Пояснення');
        $this->assertSame($status, $signed['status']);
        $this->assertSame($remote['parameter'], $signed['parameter']);
        $this->assertSame($remote['reason'], $signed['reason']);
        $this->assertSame($remote['requisition'], $signed['requisition']);
        $this->assertSame('Пояснення', $signed['status_reason']['text']);
    }

    public function test_mark_in_error_requires_explanation(): void
    {
        $service = $this->service();
        $service->shouldReceive('details')->andReturn(['status' => 'completed', 'requester_legal_entity' => ['identifier' => ['value' => $this->legalEntity->uuid]]]);
        $service->shouldReceive('dictionary')->andReturn(['wrong' => 'Помилка']);
        $this->expectException(ValidationException::class);
        $service->actionContent($this->person, $this->legalEntity, $this->requestId, 'mark_in_error', 'wrong', '');
    }

    private function smsApi(string $phone): DeviceRequestApi
    {
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldReceive('getById')->andReturn($this->response(['status' => 'active'], ['authentication_method_current' => ['type' => 'OTP', 'phone_number' => $phone]]));
        $this->instance(DeviceRequestApi::class, $api);

        return $api;
    }

    public function test_sms_is_once_even_without_local_request(): void
    {
        $api = $this->smsApi('+380***1234');
        $api->shouldReceive('resendSms')->once()->andReturn($this->response([]));
        $service = $this->service();
        $service->resendOnce($this->person, $this->legalEntity, $this->requestId, true, '+380***1234');
        $this->assertDatabaseHas('device_request_sms_resends', ['device_request_uuid' => $this->requestId]);
        $this->expectException(ValidationException::class);
        $service->resendOnce($this->person, $this->legalEntity, $this->requestId, true, '+380***1234');
    }

    public function test_phone_change_blocks_sms_before_claim(): void
    {
        $this->smsApi('+380***9876')->shouldNotReceive('resendSms');
        try {
            $this->service()->resendOnce($this->person, $this->legalEntity, $this->requestId, true, '+380***1234');
            $this->fail('Changed phone must be confirmed again.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('device_request_sms_resends', ['device_request_uuid' => $this->requestId]);
        }
    }

    public function test_sms_timeout_keeps_claim_to_prevent_duplicate(): void
    {
        $this->smsApi('+380***1234')->shouldReceive('resendSms')->once()->andThrow(new \RuntimeException('Timeout'));
        try {
            $this->service()->resendOnce($this->person, $this->legalEntity, $this->requestId, true, '+380***1234');
            $this->fail('Timeout must propagate.');
        } catch (\RuntimeException) {
            $this->assertDatabaseHas('device_request_sms_resends', ['device_request_uuid' => $this->requestId, 'confirmed_at' => null]);
        }
    }

    public function test_a5_printout_includes_identity_funding_code_and_parameters(): void
    {
        $programId = (string) Str::uuid();
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldReceive('getById')->andReturn($this->response(['requisition' => 'ABCD-1234-5678-9012', 'identity' => ['first_name' => 'Тест', 'last_name' => 'Пацієнт'], 'program' => ['identifier' => ['value' => $programId]], 'parameter' => [['value_boolean' => false]], 'dispense_valid_to' => '2026-11-10', 'requester' => ['display_value' => 'Лікар'], 'requester_legal_entity' => ['display_value' => 'Заклад']], ['authentication_method_current' => ['type' => 'OFFLINE'], 'verification_code' => '4321']));
        $this->instance(DeviceRequestApi::class, $api);
        $service = $this->service();
        $service->shouldReceive('program')->with($programId, false)->andReturn(['funding_source' => 'NHS']);
        $html = $service->printoutHtml($this->person, $this->requestId);
        foreach (['size: A5', 'CODE128A', 'ABCD-1234-5678-9012', '4321', 'NHS', 'Пацієнт', 'Заклад', 'Лікар', '2026-11-10', 'Ні'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function testPrintoutPreservesTheTypeCodeAndSystemAlongsideItsDisplay(): void
    {
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldReceive('getById')->once()->andReturn($this->response([
            'requisition' => 'ABCD-1234-5678-9012',
            'code' => ['coding' => [['code' => 'RAW-30221', 'system' => 'device_definition_classification_type', 'display' => 'Тест-смужки']]],
        ]));
        $this->instance(DeviceRequestApi::class, $api);
        $html = $this->service()->printoutHtml($this->person, $this->requestId);
        foreach (['RAW-30221', 'device_definition_classification_type', 'Тест-смужки'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_program_printout_without_auth_method_requires_code(): void
    {
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldReceive('getById')->andReturn($this->response(['requisition' => 'ABCD-1234-5678-9012', 'program' => ['identifier' => ['value' => (string) Str::uuid()]]]));
        $this->instance(DeviceRequestApi::class, $api);
        $service = $this->service();
        $service->shouldReceive('program')->andReturn([]);
        $this->expectException(ValidationException::class);
        $service->printoutHtml($this->person, $this->requestId);
    }

    private function encounter(string $performer, string $end): Encounter
    {
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'status' => 'finished',
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'performer_id' => Identifier::create(['value' => $performer])->id,
            'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create()->id, 'ehealth_inserted_at' => now(),
        ]);
        app(PeriodRepository::class)->sync($encounter, ['start' => $end, 'end' => $end]);

        return $encounter;
    }

    #[DataProvider('encounterLocalTimes')]
    public function testOnlyOwnEncounterFinishedTodayCanBeSelected(string $localTime): void
    {
        $this->travelTo(CarbonImmutable::parse($localTime, config('app.timezone')));
        $employee = new Employee(['uuid' => (string) Str::uuid()]);
        $encounter = $this->encounter($employee->uuid, now()->utc()->toIso8601String());
        $this->assertSame($encounter->id, app(DeviceRequestLifecycleService::class)->encounter($this->person, $employee, $encounter->uuid)->id);
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->encounter($this->person, new Employee(['uuid' => (string) Str::uuid()]), $encounter->uuid);
    }

    #[DataProvider('encounterLocalTimes')]
    public function testYesterdaysEncounterCannotBeSelected(string $localTime): void
    {
        $this->travelTo(CarbonImmutable::parse($localTime, config('app.timezone')));
        $employee = new Employee(['uuid' => (string) Str::uuid()]);
        $encounter = $this->encounter($employee->uuid, now()->subDay()->utc()->toIso8601String());
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->encounter($this->person, $employee, $encounter->uuid);
    }

    public static function encounterLocalTimes(): array
    {
        return [
            'daytime' => ['2026-10-27 12:30:00'],
            'late evening' => ['2026-10-27 23:30:00'],
            'after midnight' => ['2026-10-28 00:30:00'],
            'summer offset' => ['2026-08-13 23:30:00'],
            'summer midnight' => ['2026-08-13T00:15:00+03:00'],
            'summer evening' => ['2026-08-13T23:30:00+03:00'],
            'winter midnight' => ['2026-01-15T00:15:00+02:00'],
            'winter evening' => ['2026-01-15T23:30:00+02:00'],
            'spring transition' => ['2026-03-29T23:30:00+03:00'],
            'autumn transition' => ['2026-10-25T21:37:24+02:00'],
            'first repeated hour' => ['2026-10-25T03:30:00+03:00'],
            'second repeated hour' => ['2026-10-25T03:30:00+02:00'],
        ];
    }

    private function preparation(array $settings = [], array $methods = [], array $model = [], array $parameters = []): array
    {
        $service = $this->service();
        $service->shouldReceive('encounter')->andReturn(new Encounter());
        $service->shouldReceive('prescribingOptions')->andReturn(['program' => ['medical_program_settings' => $settings], 'packages' => [['count' => 50, 'unit' => 'piece']], 'parameters' => $parameters, 'model' => $model]);
        $service->shouldReceive('authMethods')->andReturn($methods);
        $service->shouldReceive('dictionary')->with('device_unit')->andReturn(['piece' => 'Штука']);
        $data = ['uuid' => $this->requestId, 'encounter_uuid' => (string) Str::uuid(), 'device_id' => 'test',
            'device_code_type' => 'CLASSIFICATION_TYPE', 'device_code_system' => 'assistive_devices', 'program_id' => '',
            'quantity' => null, 'quantity_code' => 'piece', 'started_at' => now()->addHour()->toIso8601String(),
            'ended_at' => now()->addDays(2)->toIso8601String(), 'phone_confirmed' => false, 'parameter_values' => [], 'inform_with' => ''];

        return [$service, $data];
    }

    public function test_reasons_use_submitted_patient_records_and_reject_a_record_cancelled_before_signing(): void
    {
        $concept = CodeableConcept::create(['text' => 'Перевірений стан']);
        $context = Identifier::create(['value' => (string) Str::uuid()]);
        $condition = \App\Models\MedicalEvents\Sql\Condition::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'primary_source' => true,
            'context_id' => $context->id, 'code_id' => $concept->id, 'clinical_status' => 'active',
            'verification_status' => 'confirmed', 'onset_date' => now(), 'ehealth_inserted_at' => now(),
        ]);
        $observation = \App\Models\MedicalEvents\Sql\Observation::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'primary_source' => true,
            'code_id' => $concept->id, 'status' => 'valid', 'issued' => now(), 'ehealth_inserted_at' => now(),
        ]);
        $report = \App\Models\MedicalEvents\Sql\DiagnosticReport::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $this->person->id, 'primary_source' => true,
            'code_id' => $context->id, 'recorded_by_id' => $context->id, 'status' => 'final', 'issued' => now(),
        ]);
        $foreign = $condition->replicate();
        $foreign->uuid = (string) Str::uuid();
        $foreign->personId = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE'])->id;
        $foreign->save();
        $unsent = $condition->replicate();
        $unsent->uuid = (string) Str::uuid();
        $unsent->ehealthInsertedAt = null;
        $unsent->save();
        $finishedEncounter = $this->encounter((string) Str::uuid(), now()->toIso8601String());
        $acceptedInPackage = $unsent->replicate();
        $acceptedInPackage->uuid = (string) Str::uuid();
        $acceptedInPackage->contextId = Identifier::create(['value' => $finishedEncounter->uuid])->id;
        $acceptedInPackage->save();
        $catalog = app(\App\Repositories\MedicalEvents\DeviceRequestRequestRepository::class)->reasonOptions($this->person);
        $this->assertEqualsCanonicalizing([$condition->uuid, $acceptedInPackage->uuid], array_keys($catalog['condition']));
        $this->assertStringContainsString('Перевірений стан', $catalog['condition'][$condition->uuid]);
        $this->assertSame([$observation->uuid], array_keys($catalog['observation']));
        $this->assertSame([$report->uuid], array_keys($catalog['diagnostic_report']));
        [$service, $data] = $this->preparation();
        $data['reason_reference'] = [['type' => 'condition', 'uuid' => $condition->uuid], ['type' => 'observation', 'uuid' => $observation->uuid], ['type' => 'diagnostic_report', 'uuid' => $report->uuid]];
        $prepared = $service->prepare($this->person, $this->legalEntity, $data);
        $this->assertSame([$condition->uuid, $observation->uuid, $report->uuid], array_column(array_column($prepared['payload']['reason'], 'identifier'), 'value'));
        $observation->update(['status' => 'entered_in_error']);
        try {
            $service->prepare($this->person, $this->legalEntity, $data);
            $this->fail('A cancelled reason must be rejected before signing.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason_reference.1.uuid', $exception->errors());
        }
    }

    public function test_a_reason_belonging_to_another_patient_is_rejected(): void
    {
        [$service, $data] = $this->preparation();
        $data['reason_reference'] = [['type' => 'condition', 'uuid' => (string) Str::uuid()]];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(__('device-requests.validation.reason_unavailable'));
        $service->prepare($this->person, $this->legalEntity, $data);
    }

    public function test_program_maximum_period_is_checked_before_prequalify(): void
    {
        [$service, $data] = $this->preparation(['request_max_period_day' => 1]);
        $this->expectException(ValidationException::class);
        $service->prepare($this->person, $this->legalEntity, $data);
    }

    public function test_program_requiring_care_plan_rejects_standalone_request(): void
    {
        [$service, $data] = $this->preparation(['care_plan_required' => true]);
        $this->expectException(ValidationException::class);
        $service->prepare($this->person, $this->legalEntity, $data);
    }

    public function test_unconfirmed_default_otp_shows_the_required_1677_instruction(): void
    {
        [$service, $data] = $this->preparation(methods: [['id' => (string) Str::uuid(), 'type' => 'OTP', 'phone_number' => '+380***1234']]);
        try {
            $service->prepare($this->person, $this->legalEntity, $data);
            $this->fail('Phone confirmation is required.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('1677', $exception->getMessage());
            $this->assertStringContainsString('Створення е-Запиту на медичний виріб неможливе', $exception->getMessage());
        }
    }

    public function test_removed_authentication_method_is_rejected(): void
    {
        [$service, $data] = $this->preparation();
        $data['inform_with'] = (string) Str::uuid();
        $this->expectException(ValidationException::class);
        $service->prepare($this->person, $this->legalEntity, $data);
    }

    public function test_request_prequalify_waits_for_async_verdict(): void
    {
        [$service, $data] = $this->preparation();
        $data['program_id'] = (string) Str::uuid();
        $data['quantity'] = 50;
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldReceive('prequalify')->once()->withArgs(fn (string $patientId, array $payload): bool =>
            $patientId === $this->person->uuid
            && data_get($payload, 'programs.0.identifier.value') === $data['program_id']
            && data_get($payload, 'device_request.quantity.value') === 50)
            ->andReturn($this->response(['links' => [['href' => '/api/Jobs/123']]]));
        $this->instance(DeviceRequestApi::class, $api);
        $resolver = Mockery::mock(\App\Services\MedicalEvents\EHealthJobResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn([['status' => 'VALID']]);
        $resolver->shouldReceive('assertPrequalifyValid')->once()->with([['status' => 'VALID']]);
        $this->instance(\App\Services\MedicalEvents\EHealthJobResolver::class, $resolver);
        $prepared = $service->prepare($this->person, $this->legalEntity, $data);
        $this->assertSame(50, $prepared['payload']['quantity']['value']);
        $this->assertSame($data['program_id'], $prepared['payload']['program']['identifier']['value']);
        $this->assertSame('order', $prepared['payload']['intent']);
        $this->assertSame($data['encounter_uuid'], $prepared['payload']['encounter']['identifier']['value']);
    }

    public static function requestsWithoutProgram(): array
    {
        return [
            'null program, type' => [['program_id' => null], false],
            'empty program, type' => [['program_id' => ''], false],
            'missing program, type' => [[], false],
            'null program, model' => [['program_id' => null], true],
            'empty program, model' => [['program_id' => ''], true],
            'missing program, model' => [[], true],
            'cleared quantity, type' => [['program_id' => '', 'quantity' => ''], false],
            'cleared quantity, model' => [['program_id' => '', 'quantity' => ''], true],
        ];
    }

    #[DataProvider('requestsWithoutProgram')]
    public function test_request_without_program_is_prepared_for_create_without_prequalify(array $programData, bool $byModel): void
    {
        [, $data] = $this->preparation();
        unset($data['program_id']);
        $data = array_replace($data, $programData);
        $modelId = (string) Str::uuid();
        if ($byModel) {
            $data['device_code_type'] = 'DEVICE_DEFINITION';
            $data['device_id'] = $modelId;
        }
        $service = $this->service();
        $service->shouldNotReceive('program');
        $service->shouldReceive('encounter')->once()->andReturn(new Encounter());
        $service->shouldReceive('authMethods')->once()->andReturn([]);
        $service->shouldReceive('permittedTypes')->once()->with(false)->andReturn(['assistive_devices|test' => []]);
        $service->shouldReceive('dictionary')->once()->with('device_request_code_parameter')->andReturn([]);
        $definitions = Mockery::mock(DeviceDefinition::class);
        if ($byModel) {
            $definitions->shouldReceive('getById')->once()->with($modelId)->andReturn($this->response([
                'id' => $modelId, 'is_active' => true, 'classification_types' => [['system' => 'assistive_devices', 'code' => 'test']],
            ]));
        }
        $definitions->shouldReceive('getMany')->once()->with([
            'classification_type_system' => 'assistive_devices', 'classification_type_code' => 'test',
            'medical_program_id' => null, 'is_active' => true, 'page' => 1,
        ])->andReturn($this->response([], paging: ['page_number' => 1, 'total_pages' => 1]));
        $this->instance(DeviceDefinition::class, $definitions);
        $configuration = Mockery::mock(Configuration::class);
        $configuration->shouldReceive('getDevices')->once()->andReturn($this->response([], paging: ['page_number' => 1, 'total_pages' => 1]));
        $configuration->shouldReceive('getDeviceParameters')->once()->andReturn($this->response([], paging: ['page_number' => 1, 'total_pages' => 1]));
        $this->instance(Configuration::class, $configuration);
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldNotReceive('prequalify');
        $this->instance(DeviceRequestApi::class, $api);
        $prepared = $service->prepare($this->person, $this->legalEntity, $data);
        $this->assertSame('', $prepared['data']['program_id']);
        $this->assertNull($prepared['data']['quantity']);
        $this->assertArrayNotHasKey('program', $prepared['payload']);
        $this->assertArrayNotHasKey('quantity', $prepared['payload']);
        $this->assertArrayHasKey($byModel ? 'code_reference' : 'code', $prepared['payload']);
    }

    public function test_request_without_program_preserves_optional_quantity_without_prequalify(): void
    {
        [$service, $data] = $this->preparation();
        $data['quantity'] = 50;
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldNotReceive('prequalify');
        $this->instance(DeviceRequestApi::class, $api);

        $prepared = $service->prepare($this->person, $this->legalEntity, $data);

        $this->assertArrayNotHasKey('program', $prepared['payload']);
        $this->assertSame(50, $prepared['payload']['quantity']['value']);
        $this->assertSame('piece', $prepared['payload']['quantity']['code']);
    }

    public function test_request_with_program_cannot_be_prepared_when_prequalify_rejects_it(): void
    {
        [$service, $data] = $this->preparation();
        $data['program_id'] = (string) Str::uuid();
        $data['quantity'] = 50;
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldReceive('prequalify')->once()->andReturn($this->response([
            ['status' => 'INVALID', 'rejection_reason' => 'Device is not available under this program'],
        ]));
        $this->instance(DeviceRequestApi::class, $api);

        try {
            $service->prepare($this->person, $this->legalEntity, $data);
            $this->fail('An invalid program prequalification must block preparation.');
        } catch (\App\Exceptions\EHealth\EHealthValidationException $exception) {
            $this->assertSame('Device is not available under this program', $exception->getDetails()['error']['message']);
        }
    }

    public function test_current_device_configs_produce_all_packaging_and_typed_parameters(): void
    {
        $programId = (string) Str::uuid();
        $service = $this->service();
        $service->shouldReceive('program')->andReturn(['id' => $programId, 'is_active' => true]);
        $service->shouldReceive('permittedTypes')->with(true)->andReturn(['types|test' => []]);
        $service->shouldReceive('permittedTypes')->with(false)->andReturn([]);
        $service->shouldReceive('dictionary')->with('device_request_code_parameter')->andReturn(['brakes' => 'Гальма', 'height' => 'Зріст', 'custom' => 'Інше']);
        $service->shouldReceive('dictionary')->with('height_values')->andReturn(['small' => 'Малий', 'large' => 'Великий']);
        $devices = Mockery::mock(DeviceDefinition::class);
        $devices->shouldReceive('getMany')->andReturn($this->response([
            ['id' => 'a', 'packaging' => ['packaging_count' => 30, 'packaging_unit' => 'piece'], 'program_devices' => [['device_request_allowed' => true, 'max_daily_count' => 5]]],
            ['id' => 'b', 'packaging' => ['packaging_count' => 50, 'packaging_unit' => 'piece'], 'program_devices' => [['device_request_allowed' => true, 'max_daily_count' => 10]]],
            ['id' => 'c', 'packaging' => ['packaging_count' => 100, 'packaging_unit' => 'piece'], 'program_devices' => [['device_request_allowed' => false]]],
        ], paging: ['page_number' => 1, 'total_pages' => 1]));
        $this->instance(DeviceDefinition::class, $devices);
        $config = Mockery::mock(Configuration::class);
        $config->shouldReceive('getDevices')->andReturn($this->response([['settings' => [
            'DEVICE_REQUIRED_PARAMETERS' => [['check' => [['code' => 'brakes', 'system' => 'device_request_code_parameter']]]],
            'DEVICE_PARAMETER_ALLOWED_VALUES' => [['condition' => ['code' => 'height', 'system' => 'device_request_code_parameter'], 'check' => ['small']]],
        ]]], paging: ['page_number' => 1, 'total_pages' => 1]));
        $config->shouldReceive('getDeviceParameters')->andReturn($this->response([
            ['code' => 'brakes', 'system' => 'device_request_code_parameter', 'settings' => ['DEVICE_PARAMETER_DATA_TYPE' => ['check' => 'boolean']]],
            ['code' => 'height', 'system' => 'device_request_code_parameter', 'settings' => ['DEVICE_PARAMETER_DATA_TYPE' => ['check' => 'codeable_concept'], 'DEVICE_PARAMETER_DICTIONARY' => ['check' => 'height_values']]],
        ], paging: ['page_number' => 1, 'total_pages' => 1]));
        $this->instance(Configuration::class, $config);
        $options = $service->prescribingOptions(['program_id' => $programId, 'device_code_type' => 'CLASSIFICATION_TYPE', 'device_id' => 'test', 'device_code_system' => 'types']);
        $this->assertSame([30, 50], array_column($options['packages'], 'count'));
        $this->assertSame([5, 10], array_column($options['packages'], 'maxDailyCount'));
        $this->assertTrue($options['parameters']['device_request_code_parameter|brakes']['required']);
        $this->assertSame('boolean', $options['parameters']['device_request_code_parameter|brakes']['type']);
        $this->assertSame(['small' => 'Малий'], $options['parameters']['device_request_code_parameter|height']['values']);
        $this->assertFalse($options['parameters']['device_request_code_parameter|custom']['required']);
    }

    public function test_activity_quantity_is_required_and_uses_the_activity_unit(): void
    {
        $planUuid = (string) Str::uuid();
        $activityUuid = (string) Str::uuid();
        foreach (['missing', 'wrongUnit', 'valid'] as $case) {
            [$service, $data] = $this->preparation();
            $period = ['start' => now()->toIso8601String(), 'end' => now()->addWeek()->toIso8601String()];
            $detail = ['quantity' => ['value' => 100, 'code' => $case === 'wrongUnit' ? 'different' : 'piece'], 'remaining_quantity' => ['value' => 100], 'product_reference' => ['identifier' => ['value' => $data['device_id']]], 'scheduled_period' => $period];
            $service->shouldReceive('carePlanContext')->once()->with($this->person, $planUuid, $activityUuid)->andReturn(['plan' => ['period' => $period], 'detail' => $detail]);
            $data['quantity'] = $case === 'missing' ? null : 50;
            try {
                $prepared = $service->prepare($this->person, $this->legalEntity, $data, $planUuid, $activityUuid);
                $this->assertSame('valid', $case, 'Missing quantity or a different unit must fail before signing.');
                $this->assertSame(50, $prepared['payload']['quantity']['value']);
                $this->assertSame('piece', $prepared['payload']['quantity']['code']);
            } catch (ValidationException $exception) {
                $this->assertNotSame('valid', $case);
                $this->assertArrayHasKey($case === 'missing' ? 'quantity' : 'quantity_code', $exception->errors());
                $this->assertStringNotContainsString('device-requests.validation.', $exception->getMessage());
            }
        }
    }

    public function test_model_property_requires_matching_value_and_preserves_the_native_property(): void
    {
        $key = 'synthetic|size';
        $native = ['value' => 12.5, 'code' => 'cm', 'system' => 'eHealth/ucum/units', 'unit' => 'см', 'comparator' => null];
        $property = ['type' => ['system' => 'synthetic', 'code' => 'size'], 'value_quantity' => $native];
        $model = ['properties' => [$property, array_replace($property, ['value_quantity' => array_replace($native, ['value' => 20])])]];
        $parameters = [$key => ['system' => 'synthetic', 'code' => 'size', 'type' => 'quantity', 'required' => false, 'allowed' => []]];
        foreach (['missing', 'different', 'valid', 'validInteger'] as $case) {
            [$service, $data] = $this->preparation(model: $model, parameters: $parameters);
            $data['device_code_type'] = 'DEVICE_DEFINITION';
            if ($case !== 'missing') {
                $data['parameter_values'][$key] = ['value' => $case === 'validInteger' ? '20' : ($case === 'valid' ? '12.5' : '13'), 'code' => 'cm'];
            }
            try {
                $prepared = $service->prepare($this->person, $this->legalEntity, $data);
                $this->assertContains($case, ['valid', 'validInteger'], 'The selected parameter must match a fresh model property.');
                $this->assertSame($case === 'validInteger' ? array_replace($native, ['value' => 20]) : $native, $prepared['payload']['parameter'][0]['value_quantity']);
            } catch (ValidationException $exception) {
                $this->assertNotContains($case, ['valid', 'validInteger']);
                $this->assertArrayHasKey('parameter_values.'.$key, $exception->errors());
                $this->assertStringNotContainsString('errors.ehealth.', $exception->getMessage());
            }
        }
    }
}
