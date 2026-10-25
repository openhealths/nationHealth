<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\Api\CarePlanActivity as ActivityApi;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Livewire\Concerns\MedicalEvents\Activity\VerifiesCarePlanActivityRegistration;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Person\Person;
use GuzzleHttp\Psr7\Response;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarePlanActivityRegistrationTest extends TestCase
{
    private function verify(CarePlan $plan, CarePlanActivity $activity): void
    {
        $component = new class
        {
            use VerifiesCarePlanActivityRegistration;

            public function verify(CarePlan $plan, CarePlanActivity $activity): void
            {
                $this->assertCarePlanActivityRegistered($plan, $activity);
            }
        };
        $component->verify($plan, $activity);
    }

    public static function responses(): iterable
    {
        yield 'registered' => [200, null];
        yield 'missing' => [404, \RuntimeException::class];
        yield 'unavailable' => [503, EHealthResponseException::class];
        yield 'forbidden' => [403, EHealthResponseException::class];
    }

    #[DataProvider('responses')]
    public function test_registration_distinguishes_missing_activity_from_transport_errors(int $status, ?string $exceptionClass): void
    {
        $response = new EHealthResponse(new Response($status, [], '{"error":{"message":"Test response"}}'));
        $api = Mockery::mock(ActivityApi::class);
        $call = $api->shouldReceive('getDetails')->once()->with('person-id', 'plan-id', 'activity-id');
        if ($status === 200) {
            $call->andReturn($response);
        } else {
            $call->andThrow(new EHealthResponseException($response));
        }
        $this->instance(ActivityApi::class, $api);
        $plan = new CarePlan(['uuid' => 'plan-id']);
        $plan->setRelation('person', (new Person())->forceFill(['uuid' => 'person-id']));
        if ($exceptionClass !== null) {
            $this->expectException($exceptionClass);
        }

        $this->verify($plan, new CarePlanActivity(['uuid' => 'activity-id']));
        $this->addToAssertionCount(1);
    }

    public function test_missing_identifiers_do_not_send_a_request(): void
    {
        $api = Mockery::mock(ActivityApi::class);
        $api->shouldReceive('getDetails')->never();
        $this->instance(ActivityApi::class, $api);
        $plan = new CarePlan(['uuid' => 'plan-id']);
        $plan->setRelation('person', new Person());
        $this->expectException(\RuntimeException::class);

        $this->verify($plan, new CarePlanActivity(['uuid' => 'activity-id']));
    }
}
