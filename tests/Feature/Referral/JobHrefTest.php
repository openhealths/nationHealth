<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Job;
use App\Services\MedicalEvents\EHealthJobResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobHrefTest extends TestCase
{
    public static function jobLinks(): array
    {
        return [
            'short ESOZ link' => ['/jobs/synthetic-job'],
            'API link' => ['/api/jobs/synthetic-job'],
            'absolute ESOZ link' => ['https://api-preprod.ehealth.gov.ua/jobs/synthetic-job'],
            'trailing slash' => ['/api/jobs/synthetic-job/'],
        ];
    }

    #[DataProvider('jobLinks')]
    public function testJobHrefAlwaysUsesTheConfiguredCanonicalApiEndpoint(string $href): void
    {
        config()->set('ehealth.api.domain', 'https://ehealth.invalid');
        Http::fake(['*' => Http::response(['data' => ['status' => 'processed']])]);
        $api = $this->api();

        $this->assertSame('processed', $api->getDetailsByHref($href)->validate()['status']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://ehealth.invalid/api/jobs/synthetic-job');
    }

    public function testUnrelatedHrefIsRejectedWithoutAnHttpRequest(): void
    {
        Http::fake();
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->api()->getDetailsByHref('/api/patients/private');
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function invalidJobResponses(): array
    {
        $payloads = [
            'missing status' => [],
            'non-string status' => ['status' => ['processed']],
            'invalid links' => ['status' => 'processed', 'links' => 'not-an-array'],
            'missing link href' => ['status' => 'processed', 'links' => [['entity' => 'service_request']]],
        ];
        $cases = [];
        foreach ($payloads as $name => $payload) {
            $cases[$name.' through ID'] = [$payload, false];
            $cases[$name.' through href'] = [$payload, true];
        }

        return $cases;
    }

    #[DataProvider('invalidJobResponses')]
    public function testBothJobLookupPathsRejectMalformedResponses(array $payload, bool $byHref): void
    {
        Http::fake(['*' => Http::response(['data' => $payload])]);
        $api = $this->api();
        $response = $byHref ? $api->getDetailsByHref('/jobs/synthetic-job') : $api->getDetails('synthetic-job');

        $this->expectException(ValidationException::class);
        $response->validate();
    }

    public function testResolverValidatesTheEnvelopeBeforeAcceptingAProcessedStatus(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            'status' => 'processed', 'links' => [['entity' => 'service_request']],
        ]])]);
        $this->instance(Job::class, $this->api());

        $this->expectException(ValidationException::class);
        (new EHealthJobResolver())->resolve([
            'links' => [['entity' => 'job', 'href' => '/api/jobs/synthetic-job']],
        ], maxAttempts: 1, intervalSeconds: 0);
    }

    private function api(): Job
    {
        $factory = Http::getFacadeRoot();
        $api = new Job($factory);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs)->preventStrayRequests();

        return $api;
    }
}
