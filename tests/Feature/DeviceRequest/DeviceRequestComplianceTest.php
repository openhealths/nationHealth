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
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
        $this->person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $this->legalEntity = new LegalEntity(['uuid' => (string) Str::uuid()]);
        $this->requestId = (string) Str::uuid();
    }

    private function response(array $data, array $urgent = [], array $paging = []): EHealthResponse
    {
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

    public function test_plan_details_preserve_remote_references_and_status_reason(): void
    {
        $record = [
            'requisition' => 'PLAN-1234', 'status' => 'cancelled', 'category' => ['coding' => [['system' => 'eHealth/care_plan_categories', 'code' => 'devices']]],
            'title' => '<script>unsafe</script>', 'period' => ['start' => '2026-10-10'],
            'encounter' => ['identifier' => ['value' => 'encounter-id']], 'supporting_info' => [['identifier' => ['value' => 'episode-id']]],
            'addresses' => [['identifier' => ['value' => 'condition-id']]], 'description' => 'Description',
            'status_reason' => ['coding' => [['code' => 'other', 'system' => 'eHealth/care_plan_cancel_reasons']], 'text' => 'Confirmed reason'], 'note' => 'Note',
        ];
        $plan = new \App\Models\CarePlan(['uuid' => (string) Str::uuid(), 'status' => 'cancelled']);
        $plan->setRelation('person', $this->person);
        \Illuminate\Support\Facades\Gate::shouldReceive('authorize')->once()->with('view', $plan)->andReturn(\Illuminate\Auth\Access\Response::allow());
        $service = Mockery::mock(\App\Services\MedicalEvents\CarePlanLifecycleService::class);
        $service->shouldReceive('getDetails')->once()->with($this->person->uuid, $plan->uuid)->andReturn($record);
        $this->instance(\App\Services\MedicalEvents\CarePlanLifecycleService::class, $service);
        $component = new \App\Livewire\CarePlan\CarePlanShow();
        $component->carePlan = $plan;
        $component->refreshDisplayedDetails();
        $this->assertSame($record, $component->remotePlanDetails);
        $html = view('livewire.care-plan.remote-details', compact('record'))->render();
        foreach (['PLAN-1234', 'encounter-id', 'episode-id', 'condition-id', 'Confirmed reason', __('care-plan.no_end_date'), '&lt;script&gt;'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_incomplete_paging_cannot_silently_change_rules(): void
    {
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->collectPages(fn () => $this->response([['id' => 1]]));
    }

    public static function blockedStates(): array
    {
        return [['terminated', 'scheduled'], ['cancelled', 'scheduled'], ['completed', 'scheduled'], ['active', 'cancelled'], ['active', 'completed']];
    }

    private function bindPlan(string $planStatus = 'active', string $activityStatus = 'scheduled', ?string $end = null): void
    {
        $plan = Mockery::mock(CarePlan::class);
        $plan->shouldReceive('getDetails')->andReturn($this->response(['status' => $planStatus, 'period' => ['start' => now()->subDay()->toIso8601String(), 'end' => $end]]));
        $activity = Mockery::mock(CarePlanActivity::class);
        $activity->shouldReceive('getDetails')->andReturn($this->response(['detail' => ['kind' => 'device_request', 'status' => $activityStatus, 'scheduled_period' => ['start' => now()->subDay()->toIso8601String(), 'end' => $end]]]));
        $this->instance(CarePlan::class, $plan);
        $this->instance(CarePlanActivity::class, $activity);
    }

    #[DataProvider('blockedStates')]
    public function test_terminal_plan_or_activity_blocks_prescribing(string $planStatus, string $activityStatus): void
    {
        $this->bindPlan($planStatus, $activityStatus);
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->carePlanContext($this->person, 'plan', 'activity');
    }

    public function test_optional_end_is_supported(): void
    {
        $this->bindPlan();
        $context = app(DeviceRequestLifecycleService::class)->carePlanContext($this->person, 'plan', 'activity');
        $this->assertSame('device_request', $context['detail']['kind']);
    }

    public function test_expired_period_blocks_prescribing(): void
    {
        $this->bindPlan(end: now()->subHour()->toIso8601String());
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->carePlanContext($this->person, 'plan', 'activity');
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
        $encounter->period()->create(['start' => $end, 'end' => $end]);

        return $encounter;
    }

    public function test_only_own_encounter_finished_today_can_be_selected(): void
    {
        $employee = new Employee(['uuid' => (string) Str::uuid()]);
        $encounter = $this->encounter($employee->uuid, now()->toIso8601String());
        $this->assertSame($encounter->id, app(DeviceRequestLifecycleService::class)->encounter($this->person, $employee, $encounter->uuid)->id);
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->encounter($this->person, new Employee(['uuid' => (string) Str::uuid()]), $encounter->uuid);
    }

    public function test_yesterdays_encounter_cannot_be_selected(): void
    {
        $employee = new Employee(['uuid' => (string) Str::uuid()]);
        $encounter = $this->encounter($employee->uuid, now()->subDay()->toIso8601String());
        $this->expectException(ValidationException::class);
        app(DeviceRequestLifecycleService::class)->encounter($this->person, $employee, $encounter->uuid);
    }

    private function preparation(array $settings = [], array $methods = []): array
    {
        $service = $this->service();
        $service->shouldReceive('encounter')->andReturn(new Encounter());
        $service->shouldReceive('prescribingOptions')->andReturn(['program' => ['medical_program_settings' => $settings], 'packages' => [['count' => 50, 'unit' => 'piece']], 'parameters' => []]);
        $service->shouldReceive('authMethods')->andReturn($methods);
        $data = ['uuid' => $this->requestId, 'encounter_uuid' => (string) Str::uuid(), 'device_id' => 'test',
            'device_code_type' => 'CLASSIFICATION_TYPE', 'device_code_system' => 'assistive_devices', 'program_id' => '',
            'quantity' => null, 'quantity_code' => 'piece', 'started_at' => now()->addHour()->toIso8601String(),
            'ended_at' => now()->addDays(2)->toIso8601String(), 'phone_confirmed' => false, 'parameter_values' => [], 'inform_with' => ''];

        return [$service, $data];
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
        $api = Mockery::mock(DeviceRequestApi::class);
        $api->shouldReceive('prequalify')->once()->andReturn($this->response(['links' => [['href' => '/api/Jobs/123']]]));
        $this->instance(DeviceRequestApi::class, $api);
        $resolver = Mockery::mock(\App\Services\MedicalEvents\EHealthJobResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn([['status' => 'VALID']]);
        $resolver->shouldReceive('assertPrequalifyValid')->once()->with([['status' => 'VALID']]);
        $this->instance(\App\Services\MedicalEvents\EHealthJobResolver::class, $resolver);
        $prepared = $service->prepare($this->person, $this->legalEntity, $data);
        $this->assertArrayNotHasKey('quantity', $prepared['payload']);
        $this->assertSame('order', $prepared['payload']['intent']);
        $this->assertSame($data['encounter_uuid'], $prepared['payload']['encounter']['identifier']['value']);
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
}
