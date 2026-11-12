<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\Api\CarePlanActivity as ActivityApi;
use App\Core\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CarePlanResourceHarness;
use Tests\Support\CarePlanWorkflowHarness;
use Tests\TestCase;

class CarePlanTv39ContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ehealth.api.domain', 'https://ehealth.invalid');
        Http::preventStrayRequests();
    }

    private function fixture(string $name): array
    {
        $resource = Arr::toSnakeCase(json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR));
        $resource['inserted_at'] = $resource['updated_at'] = '2026-10-19T10:20:30Z';
        $resource['inserted_by'] = $resource['updated_by'] = '47ee824a-eb47-48ef-8d30-1109e3c89f0c';
        if ($name === 'plan') {
            unset($resource['period']['end']);
            $resource['supporting_info'] = [$resource['supporting_info'][1]];
            $resource['requisition'] = '1111-2222-3333-4444';
            $resource['subject'] = $resource['encounter'];
            $resource['managing_organization'] = $resource['encounter'] + ['display_value' => 'Synthetic clinic'];
            $resource['status_history'] = [['status' => 'new', 'inserted_at' => $resource['inserted_at'], 'inserted_by' => $resource['inserted_by']]];
        }

        return $resource;
    }

    private function api(string $class): CarePlanApi|ActivityApi
    {
        $factory = Http::getFacadeRoot();
        $api = new $class($factory);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs)->preventStrayRequests();
        $api->withHeaders(['Authorization' => 'Bearer synthetic']);

        return $api;
    }

    public static function resources(): array
    {
        return [['plan', CarePlanApi::class], ['activity', ActivityApi::class]];
    }

    #[DataProvider('resources')]
    public function test_full_resources_validate_before_preserving_native_signing_snapshot(string $kind, string $class): void
    {
        $native = $this->fixture($kind);
        Http::fake(['*' => Http::response(['data' => $native])]);
        $api = $this->api($class);
        $actual = $kind === 'plan' ? $api->getDetails('patient', $native['id'], preserveApiKeys: true)->validate() : $api->getDetails('patient', 'plan', $native['id'], preserveApiKeys: true)->validate();
        $this->assertSame($native, $actual);
    }

    public static function malformedFields(): array
    {
        return [
            ['plan', 'subject.identifier.value', 'not-a-uuid'],
            ['plan', 'author.identifier.type.coding.0.system', null],
            ['plan', 'period.start', 'invalid date'],
            ['plan', 'inserted_at', '2026-11-11'],
            ['activity', 'updated_at', '2026-11-11'],
            ['plan', 'status_history.0.inserted_by', null],
            ['plan', 'based_on.identifier.value', 'invalid'],
            ['activity', 'author.0.identifier.value', null],
            ['activity', 'care_plan.identifier.value', 'not-a-uuid'],
            ['activity', 'detail.quantity.value', 'not-a-number'],
            ['activity', 'detail.scheduled_period.end', 'bad date'],
            ['activity', 'detail.product_reference.identifier.value', 'bad-uuid'],
            ['activity', 'detail.reason_reference.0.identifier.value', null],
        ];
    }

    #[DataProvider('malformedFields')]
    public function test_malformed_full_responses_are_rejected_before_normalization(string $kind, string $field, mixed $value): void
    {
        $native = $this->fixture($kind);
        data_set($native, $field, $value);
        Http::fake(['*' => Http::response(['data' => $native])]);
        $this->expectException(ValidationException::class);
        $api = $this->api($kind === 'plan' ? CarePlanApi::class : ActivityApi::class);
        ($kind === 'plan' ? $api->getDetails('patient', $native['id'], preserveApiKeys: true) : $api->getDetails('patient', 'plan', $native['id'], preserveApiKeys: true))->validate();
    }

    public function test_masked_requisition_identity_is_required_and_returned_without_detail_reads(): void
    {
        $plan = $this->fixture('plan');
        $plan['identity'] = ['last_name' => 'Synthetic', 'first_name' => 'A', 'second_name' => 'B', 'gender' => 'FEMALE', 'age' => 42];
        Http::fake(['*' => Http::response(['data' => [$plan]])]);
        $result = $this->api(CarePlanApi::class)->getMany(['requisition' => $plan['requisition']])->validate();
        $this->assertSame(42, $result[0]['identity']['age']);
        $this->assertSame('A', $result[0]['identity']['firstName']);
    }

    public function test_masked_requisition_identity_with_missing_age_is_rejected(): void
    {
        $plan = $this->fixture('plan');
        $plan['identity'] = ['last_name' => 'Synthetic', 'first_name' => 'A', 'second_name' => 'B', 'gender' => 'FEMALE'];
        Http::fake(['*' => Http::response(['data' => [$plan]])]);
        $this->expectException(ValidationException::class);
        $this->api(CarePlanApi::class)->getMany()->validate();
    }

    public function test_plan_relationships_round_trip_and_explicit_clear_survive_draft_reopening(): void
    {
        $resource = Arr::toCamelCase($this->fixture('plan'));
        $resource['basedOn'] = $resource['partOf'] = $resource['encounter'];
        $resource['basedOn']['identifier']['type']['coding'][0]['code'] = 'care_plan';
        $resource['partOf']['identifier']['type']['coding'][0]['code'] = 'care_plan';
        $mapper = new CarePlanResourceHarness();
        $form = $mapper->fromFhir($resource);
        $this->assertSame($resource['encounter']['identifier']['value'], $form['basedOn']);
        $mapped = $mapper->toFhir($form, ['resource' => $resource]);
        $this->assertSame($resource['basedOn']['identifier']['value'], $mapped['basedOn']['identifier']['value']);
        $content = (new CarePlanWorkflowHarness())->buildCreateCarePlanContent($mapped);
        $this->assertSame($resource['basedOn']['identifier']['value'], $content['based_on']['identifier']['value']);
        $cleared = $mapper->toFhir(array_replace($form, ['basedOn' => null, 'partOf' => '']), ['resource' => $resource]);
        $this->assertArrayNotHasKey('basedOn', $cleared);
        $this->assertArrayNotHasKey('partOf', $cleared);
    }

    public static function serviceQuantities(): array
    {
        return [[0], [-1], [1.5], ['invalid']];
    }

    #[DataProvider('serviceQuantities')]
    public function test_service_quantity_must_be_a_positive_integer(mixed $quantity): void
    {
        $resource = Arr::toCamelCase($this->fixture('activity'));
        $resource['detail']['kind'] = 'service_request';
        $resource['detail']['quantity']['value'] = $quantity;
        $this->expectException(ValidationException::class);
        (new CarePlanWorkflowHarness())->buildCreateCarePlanActivityContent($resource);
    }

    public function test_medication_requires_program_and_quantity_at_signing(): void
    {
        $resource = Arr::toCamelCase($this->fixture('activity'));
        $this->expectException(ValidationException::class);
        (new CarePlanWorkflowHarness())->buildCreateCarePlanActivityContent($resource);
    }

    public function test_service_without_program_has_optional_period_and_accepts_integer_quantity(): void
    {
        $resource = Arr::toCamelCase($this->fixture('activity'));
        $resource['detail']['kind'] = 'service_request';
        $resource['detail']['quantity']['value'] = 2;
        unset($resource['detail']['scheduledPeriod']);
        $content = (new CarePlanWorkflowHarness())->buildCreateCarePlanActivityContent($resource);
        $this->assertSame(2, $content['detail']['quantity']['value']);
        $this->assertArrayNotHasKey('scheduled_period', $content['detail']);
    }

    public function test_partial_period_is_rejected_without_a_program(): void
    {
        $resource = Arr::toCamelCase($this->fixture('activity'));
        $resource['detail']['kind'] = 'service_request';
        $resource['detail']['quantity']['value'] = 2;
        unset($resource['detail']['scheduledPeriod']['end']);
        $this->expectException(ValidationException::class);
        (new CarePlanWorkflowHarness())->buildCreateCarePlanActivityContent($resource);
    }

    private function activityGuardHarness(array $catalog = [], ?array $program = null): object
    {
        return new class ($catalog, $program) extends CarePlanWorkflowHarness
        {

            public function __construct(private array $catalog, private ?array $program)
            {
                parent::__construct();
            }

            protected function fetchAllDeviceDefinitions(array $filters): array
            {
                return $this->catalog;
            }

            protected function resolveMedicalProgramPayload(?string $programId): ?array
            {
                return $this->program;
            }

            public function validateForSigning(array $resource): void
            {
                $this->assertMedicationActivityProgramForSigning($resource);
                $this->assertDeviceActivityQuantityForSigning($resource);
            }
        };
    }

    public function test_device_signing_accepts_other_pack_sizes_but_rejects_tampered_quantity(): void
    {
        $program = 'a3901c70-a1d5-4c96-8556-58a84d4d0a3c';
        $classification = ['system' => 'device/classification', 'code' => 'TEST'];
        $devices = [];
        foreach ([2, 3] as $count) {
            $devices[] = [
                'id' => (string) $count, 'is_active' => true,
                'classification_types' => [$classification],
                'packaging' => ['packaging_unit' => 'piece', 'packaging_count' => $count],
                'program_devices' => [['medical_program_id' => $program, 'care_plan_activity_allowed' => true, 'max_daily_count' => 3]],
            ];
        }
        $resource = ['detail' => [
            'kind' => 'device_request',
            'program' => ['identifier' => ['value' => $program]],
            'productReference' => ['identifier' => ['value' => '2']],
            'quantity' => ['value' => 3, 'code' => 'piece'],
            'scheduledPeriod' => ['start' => '2026-11-11', 'end' => '2026-11-12'],
        ]];
        $harness = $this->activityGuardHarness($devices);
        $harness->validateForSigning($resource);
        $this->assertTrue(true);
        $resource['detail']['quantity']['value'] = 5;
        $this->expectException(ValidationException::class);
        $harness->validateForSigning($resource);
    }

    public function test_medication_signing_rejects_a_device_program(): void
    {
        $resource = ['detail' => ['kind' => 'medication_request', 'program' => ['identifier' => ['value' => 'a3901c70-a1d5-4c96-8556-58a84d4d0a3c']]]];
        $this->expectException(ValidationException::class);
        $this->activityGuardHarness(program: ['type' => 'DEVICE'])->validateForSigning($resource);
    }

    public function test_medication_signing_accepts_a_medication_program(): void
    {
        $resource = ['detail' => ['kind' => 'medication_request', 'program' => ['identifier' => ['value' => 'a3901c70-a1d5-4c96-8556-58a84d4d0a3c']]]];
        $this->activityGuardHarness(program: ['type' => 'MEDICATION'])->validateForSigning($resource);
        $this->assertTrue(true);
    }

    public function test_device_options_include_all_pack_sizes_and_inclusive_day_cap(): void
    {
        $this->assertSame([2, 3, 4, 6], (new CarePlanWorkflowHarness())->buildDeviceQuantityOptions([2, 3, 2, 0], 3, '2026-11-11', '2026-11-12'));
        $this->assertSame([2, 3], (new CarePlanWorkflowHarness())->buildDeviceQuantityOptions([2, 3], 3, '2026-11-11', '2026-11-11'));
        $this->assertSame([], (new CarePlanWorkflowHarness())->buildDeviceQuantityOptions([2], 3, '2026-11-12', '2026-11-11'));
        $this->assertSame([], (new CarePlanWorkflowHarness())->buildDeviceQuantityOptions([2], 3, 'bad date', '2026-11-11'));
    }
}
