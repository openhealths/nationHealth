<?php

declare(strict_types=1);

namespace Tests\Feature\DeviceRequest;

use App\Classes\eHealth\Api\Patient\DeviceRequest as DeviceRequestApi;
use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Services\MedicalEvents\EHealthJobResolver;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Support\NativeClinicalFixtures as Native;
use Tests\TestCase;

/** Regression coverage for the older care-plan DeviceRequest delegation paths. */
class ReferralDeviceResponseBoundaryTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;

    private const string PATIENT = '8f0460f9-0d42-4644-8ee6-b182198dc50b';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_full_native_device_detail_is_retained_before_referral_mapping(): void
    {
        $resource = Native::deviceRequest(Native::UUID, self::PATIENT);
        $this->fakeApi($resource);

        $this->assertSame($resource, app(ReferralRequestLifecycleService::class)
            ->fetchRemoteReferral(self::PATIENT, Native::UUID, 'device_request'));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('device_request_requests', 0);
        Queue::assertNothingPushed();
    }

    public function test_malformed_native_device_detail_is_rejected_before_mapping(): void
    {
        $resource = Native::deviceRequest(Native::UUID, self::PATIENT);
        unset($resource['requester']['identifier']['type']);
        $this->fakeApi($resource);

        try {
            app(ReferralRequestLifecycleService::class)->fetchRemoteReferral(self::PATIENT, Native::UUID, 'device_request');
            $this->fail('Incomplete native requester data must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('device_request_requests', 0);
            Queue::assertNothingPushed();
        }
    }

    public function test_malformed_device_detail_cannot_promote_an_existing_draft(): void
    {
        $context = $this->compositionFixture();
        $plan = new CarePlan(['person_id' => $context['person']->id]);
        $plan->setRelation('person', $context['person']);
        $draft = DeviceRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $context['person']->id,
            'employee_id' => $context['employee']->id, 'status' => 'draft', 'device_id' => '30221',
        ]);
        $original = $draft->fresh()->getRawOriginal();
        $resource = Native::deviceRequest($draft->uuid, $context['person']->uuid);
        unset($resource['requester_legal_entity']);
        $this->fakeApi($resource);

        $this->assertFalse(app(ReferralRequestLifecycleService::class)
            ->trySyncDraftFromEHealth($plan, null, $draft, 'device_request'));
        $this->assertSame($original, $draft->fresh()->getRawOriginal());
        $this->assertDatabaseCount('device_request_requests', 1);
        Queue::assertNothingPushed();
    }

    public function test_a_complete_native_device_detail_synchronizes_the_existing_draft(): void
    {
        $context = $this->compositionFixture();
        $plan = new CarePlan(['person_id' => $context['person']->id]);
        $plan->setRelation('person', $context['person']);
        $plan->setRelation('encounter', null);
        $draft = DeviceRequestRequest::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $context['person']->id,
            'employee_id' => $context['employee']->id, 'status' => 'draft', 'device_id' => '30221',
        ]);
        $resource = Native::deviceRequest($draft->uuid, $context['person']->uuid);
        $this->fakeApi($resource);

        $this->assertTrue(app(ReferralRequestLifecycleService::class)
            ->trySyncDraftFromEHealth($plan, null, $draft, 'device_request'));
        $this->assertSame('active', $draft->fresh()->status);
        $this->assertSame($resource['requisition'], $draft->fresh()->requestNumber);
        $this->assertSame('50.00', $draft->fresh()->quantity);
        $this->assertSame($context['person']->id, $draft->fresh()->personId);
        $this->assertDatabaseCount('device_request_requests', 1);
        Queue::assertNothingPushed();
    }

    public static function signedOperations(): array
    {
        return ['create' => [false, 'POST', ''], 'mark in error' => [true, 'PATCH', '/'.Native::UUID.'/actions/mark_in_error']];
    }

    public function test_legacy_cancel_delegates_once_and_preserves_the_documented_validator(): void
    {
        $receipt = ['status' => 'pending', 'links' => [['entity' => 'job', 'href' => '/Jobs/native-job']]];
        $this->fakeApi($receipt, 202);

        $this->assertSame($receipt, EHealth::deviceRequest()->cancel(
            self::PATIENT,
            Native::UUID,
            ['signed_data' => 'synthetic-signature']
        )->validate());
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/api/patients/'.self::PATIENT.'/device_requests/'.Native::UUID.'/actions/mark_in_error'));
    }

    public function test_legacy_cancel_rejects_malformed_receipts_through_the_same_validator(): void
    {
        $this->fakeApi(['status' => 'pending', 'links' => [['entity' => 'job', 'href' => 'https://foreign.test/Jobs/native-job']]], 202);
        $this->expectException(ValidationException::class);
        EHealth::deviceRequest()->cancel(self::PATIENT, Native::UUID, ['signed_data' => 'synthetic-signature'])->validate();
    }

    #[DataProvider('signedOperations')]
    public function test_the_documented_signed_operation_validates_its_receipt_before_resolving(bool $cancel, string $method, string $suffix): void
    {
        $receipt = ['status' => 'pending', 'eta' => '2026-11-11T10:00:00Z', 'links' => [['entity' => 'job', 'href' => '/Jobs/native-job']]];
        $this->fakeApi($receipt, 202);
        $resolver = $this->mock(EHealthJobResolver::class);
        $resolver->shouldReceive('resolve')->once()->with($receipt)->andReturn(['status' => 'processed']);
        $service = app(ReferralRequestLifecycleService::class);

        $result = $cancel
            ? $service->submitSignedCancel('device_request', self::PATIENT, Native::UUID, ['signed_data' => 'synthetic-signature'])
            : $service->submitSignedCreate('device_request', self::PATIENT, 'synthetic-signature');

        $this->assertSame(['status' => 'processed'], $result);
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => $request->method() === $method
            && str_ends_with($request->url(), '/api/patients/'.self::PATIENT.'/device_requests'.$suffix)
            && $request->data()['signed_data'] === 'synthetic-signature');
        $this->assertDatabaseCount('device_request_requests', 0);
        Queue::assertNothingPushed();
    }

    public static function invalidReceipts(): array
    {
        $invalid = [
            'unknown status' => ['status' => 'invented', 'links' => [['entity' => 'job', 'href' => '/Jobs/native-job']]],
            'wrong link entity' => ['status' => 'pending', 'links' => [['entity' => 'device_request', 'href' => '/Jobs/native-job']]],
            'foreign href' => ['status' => 'pending', 'links' => [['entity' => 'job', 'href' => 'https://foreign.test/Jobs/native-job']]],
            'missing job link' => ['status' => 'pending'],
        ];
        $cases = [];
        foreach ([false, true] as $cancel) {
            foreach ($invalid as $name => $receipt) {
                $cases[($cancel ? 'mark in error ' : 'create ').$name] = [$cancel, $receipt];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidReceipts')]
    public function test_a_malformed_receipt_cannot_reach_the_resolver_or_local_persistence(bool $cancel, array $receipt): void
    {
        $this->fakeApi($receipt, 202);
        $this->mock(EHealthJobResolver::class)->shouldNotReceive('resolve');
        $service = app(ReferralRequestLifecycleService::class);

        try {
            if ($cancel) {
                $service->submitSignedCancel('device_request', self::PATIENT, Native::UUID, ['signed_data' => 'synthetic-signature']);
            } else {
                $service->submitSignedCreate('device_request', self::PATIENT, 'synthetic-signature');
            }
            $this->fail('Malformed job receipts must not reach the resolver.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('device_request_requests', 0);
            Queue::assertNothingPushed();
        }
    }

    private function fakeApi(array $data, int $status = 200): void
    {
        $factory = Http::getFacadeRoot();
        Http::fake(['ehealth.test/*' => Http::response(json_encode(['data' => $data], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $status)]);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api = new DeviceRequestApi($factory);
        $api->stub($stubs)->preventStrayRequests()->withToken('synthetic-contract-test');
        $this->instance(DeviceRequestApi::class, $api);
    }
}
