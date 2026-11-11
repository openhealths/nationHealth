<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\EHealth\Api;

use App\Classes\eHealth\Api\Job as JobApi;
use App\Classes\eHealth\Api\Patient\Encounter as EncounterApi;
use App\Classes\eHealth\EHealth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EncounterCancellationContractTest extends TestCase
{
    private const string PATIENT = 'e4b04d5a-4dfe-442a-969b-96049e325521';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        Http::preventStrayRequests();
    }

    public function test_optional_job_link_is_not_required_by_the_accepted_receipt_contract(): void
    {
        $this->fakeApi(EncounterApi::class, ['data' => ['status' => 'pending']], 202);
        $this->assertSame(['status' => 'pending'], EHealth::encounter()->cancel(self::PATIENT, ['signed_data' => 'signed-test-fixture'])->validate());
        Http::assertSent(static fn ($request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/api/patients/'.self::PATIENT.'/encounter_package'));
    }

    #[DataProvider('invalidReceipts')]
    public function test_a_malformed_receipt_cannot_be_used_to_track_cancellation(array $data): void
    {
        $this->fakeApi(EncounterApi::class, ['data' => $data], 202);
        $this->expectException(ValidationException::class);
        EHealth::encounter()->cancel(self::PATIENT, ['signed_data' => 'signed-test-fixture'])->validate();
    }

    public static function invalidReceipts(): array
    {
        return [
            [[]],
            [['status' => null]],
            [['status' => ['pending']]],
            [['status' => 'pending', 'links' => [['href' => '/Jobs/abc']]]],
            [['status' => 'pending', 'links' => [['entity' => 'job']]]],
            [['status' => 'pending', 'links' => null]],
        ];
    }

    public function test_official_capitalized_job_link_is_canonicalized_and_validated(): void
    {
        $this->fakeApi(JobApi::class, ['data' => ['status' => 'processed', 'status_code' => 200]], 200);
        $this->assertSame('processed', EHealth::job()->getDetailsByHref('/Jobs/NBXk9EyErUZv1RhXgyvgg')->validate()['status']);
        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/api/jobs/NBXk9EyErUZv1RhXgyvgg'));
    }

    public function test_an_invalid_job_detail_is_rejected_before_a_completion_decision(): void
    {
        $this->fakeApi(JobApi::class, ['data' => ['status_code' => 200]], 200);
        $this->expectException(ValidationException::class);
        EHealth::job()->getDetailsByHref('/Jobs/abc')->validate();
    }

    private function fakeApi(string $class, array $body, int $status): void
    {
        $factory = Http::getFacadeRoot();
        Http::fake(['ehealth.test/*' => Http::response($body, $status)]);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api = new $class($factory);
        $api->stub($stubs)->preventStrayRequests()->withToken('cancellation-contract-test');
        $this->instance($class, $api);
    }
}
