<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Classes\eHealth\Api\CarePlanActivity as CarePlanActivityApi;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Person\Person;
use App\Services\MedicalEvents\CarePlanActivityEHealthGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\NativeClinicalFixtures;
use Tests\TestCase;

class CarePlanActivityRegistrationBoundaryTest extends TestCase
{
    private const string PLAN = '8d08ce22-82dd-4ac4-bdb9-432fd5aad5dd';

    private const string ACTIVITY = '86cd9064-7be2-4596-a87e-bf989e4d388e';

    public function test_validated_activity_confirms_only_the_requested_registration(): void
    {
        $this->fakeActivity($this->activity());
        $this->assertRegistered();
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/care_plans/'.self::PLAN.'/activities/'.self::ACTIVITY));
    }

    #[DataProvider('malformedFields')]
    public function test_http_success_cannot_confirm_a_malformed_resource(string $path, mixed $value): void
    {
        $activity = $this->activity();
        data_set($activity, $path, $value);
        $this->fakeActivity($activity);
        $this->expectException(ValidationException::class);
        $this->assertRegistered();
    }

    public static function malformedFields(): array
    {
        return [
            'missing activity ID' => ['id', null],
            'missing plan reference' => ['care_plan', null],
            'missing author' => ['author', []],
            'missing status' => ['detail.status', null],
            'invalid product' => ['detail.product_reference.identifier.value', 'invalid'],
            'missing audit timestamp' => ['updated_at', null],
        ];
    }

    #[DataProvider('foreignResources')]
    public function test_another_valid_resource_does_not_confirm_this_activity(string $path): void
    {
        $activity = $this->activity();
        data_set($activity, $path, NativeClinicalFixtures::UUID);
        $this->fakeActivity($activity);
        $this->expectException(RuntimeException::class);
        $this->assertRegistered();
    }

    public static function foreignResources(): array
    {
        return [['id'], ['care_plan.identifier.value']];
    }

    private function assertRegistered(): void
    {
        $plan = new CarePlan(['uuid' => self::PLAN]);
        $person = new Person();
        $person->uuid = NativeClinicalFixtures::UUID;
        $plan->setRelation('person', $person);
        $guard = new CarePlanActivityEHealthGuard();
        $guard->assertRegisteredInEHealth($plan, new CarePlanActivity(['uuid' => self::ACTIVITY]));
    }

    private function fakeActivity(array $activity): void
    {
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        $factory = Http::getFacadeRoot();
        Http::fake(['ehealth.test/*' => Http::response(['data' => $activity])]);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api = new CarePlanActivityApi($factory);
        $api->stub($stubs)->preventStrayRequests();
        $this->instance(CarePlanActivityApi::class, $api);
    }

    private function activity(): array
    {
        return [
            'id' => self::ACTIVITY,
            'care_plan' => NativeClinicalFixtures::reference('care_plan', self::PLAN),
            'author' => [NativeClinicalFixtures::reference('employee')],
            'detail' => [
                'kind' => 'service_request', 'status' => 'scheduled', 'do_not_perform' => false,
                'product_reference' => NativeClinicalFixtures::reference('service'),
                'remaining_quantity_type' => 'for_request',
            ],
            'inserted_at' => '2026-10-20T09:00:00Z', 'updated_at' => '2026-10-20T09:00:00Z',
            'inserted_by' => NativeClinicalFixtures::UUID, 'updated_by' => NativeClinicalFixtures::UUID,
        ];
    }
}
