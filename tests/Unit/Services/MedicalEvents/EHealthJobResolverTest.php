<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthJobTimeoutException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Services\MedicalEvents\EHealthJobResolver;
use Mockery;
use Tests\TestCase;

/**
 * Every caller pairs job resolution with a local database write, so the resolver must
 * raise instead of returning an unresolved or failed job — otherwise the local record
 * is marked as accepted while eHealth rejected it or never finished.
 */
class EHealthJobResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ehealth.jobs.interval_seconds', 0);
        config()->set('ehealth.jobs.max_attempts', 3);
    }

    public function test_a_response_without_a_job_link_is_returned_untouched(): void
    {
        $response = ['id' => 'abc', 'status' => 'active'];

        $this->assertSame($response, (new EHealthJobResolver())->resolve($response));
    }

    public function test_it_polls_until_the_job_leaves_the_pending_state(): void
    {
        $this->fakeJobApi([
            ['status' => 'pending'],
            ['status' => 'processing'],
            ['status' => 'processed', 'id' => 'created-uuid'],
        ]);

        $result = (new EHealthJobResolver())->resolve($this->jobResponse());

        $this->assertSame('processed', $result['status']);
        $this->assertSame('created-uuid', $result['id']);
    }

    public function test_it_raises_when_the_job_status_is_unknown(): void
    {
        $this->fakeJobApi([
            ['status' => 'wat'],
        ]);

        $this->expectException(\App\Exceptions\EHealth\EHealthValidationException::class);

        (new EHealthJobResolver())->resolve($this->jobResponse());
    }

    public function test_it_keeps_polling_accepted_jobs_until_they_time_out(): void
    {
        $this->fakeJobApi([
            ['status' => 'accepted'],
            ['status' => 'accepted'],
            ['status' => 'accepted'],
        ]);

        $this->expectException(EHealthJobTimeoutException::class);

        (new EHealthJobResolver())->resolve($this->jobResponse());
    }

    public function test_it_polls_a_bare_job_id(): void
    {
        $this->fakeJobApi([
            ['status' => 'processed', 'id' => 'from-job-id'],
        ]);

        $result = (new EHealthJobResolver())->resolve(['job_id' => 'job-123']);

        $this->assertSame('from-job-id', $result['id']);
    }

    public function test_it_raises_when_the_job_is_still_pending_after_the_last_attempt(): void
    {
        $this->fakeJobApi([
            ['status' => 'pending'],
            ['status' => 'pending'],
            ['status' => 'pending'],
        ]);

        try {
            (new EHealthJobResolver())->resolve($this->jobResponse());
            $this->fail('An unresolved job must not be reported as success.');
        } catch (EHealthJobTimeoutException $exception) {
            $this->assertSame('job-123', $exception->jobId);
            $this->assertSame(3, $exception->attempts);
            $this->assertSame('pending', $exception->lastStatus);
        }
    }

    public function test_it_raises_when_the_job_failed(): void
    {
        $this->fakeJobApi([
            ['status' => 'failed', 'error' => ['message' => 'Medication is not active']],
        ]);

        $this->expectException(EHealthValidationException::class);

        (new EHealthJobResolver())->resolve($this->jobResponse());
    }

    public function test_a_failed_job_carries_its_ehealth_error_through(): void
    {
        $this->fakeJobApi([
            ['status' => 'error', 'error' => ['invalid' => [['rules' => ['x']]]]],
        ]);

        try {
            (new EHealthJobResolver())->resolve($this->jobResponse());
            $this->fail('A failed job must raise.');
        } catch (EHealthValidationException $exception) {
            $this->assertArrayHasKey('invalid', $exception->getDetails()['error']);
        }
    }

    public function test_assert_prequalify_valid_rejects_an_invalid_program(): void
    {
        $this->expectException(EHealthValidationException::class);

        (new EHealthJobResolver())->assertPrequalifyValid([
            'data' => [
                ['status' => 'VALID'],
                ['status' => 'INVALID', 'rejection_reason' => 'Programme exhausted'],
            ],
        ]);
    }

    public function test_assert_prequalify_valid_accepts_valid_programs(): void
    {
        (new EHealthJobResolver())->assertPrequalifyValid([
            'data' => [['status' => 'VALID']],
        ]);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return array<string, mixed>
     */
    private function jobResponse(): array
    {
        return ['links' => [['href' => '/api/jobs/job-123']]];
    }

    /**
     * @param  list<array<string, mixed>>  $statuses
     */
    private function fakeJobApi(array $statuses): void
    {
        $jobApi = Mockery::mock(Job::class);

        foreach ($statuses as $status) {
            $response = Mockery::mock(EHealthResponse::class);
            $response->shouldReceive('getData')->andReturn($status);

            $jobApi->shouldReceive('getDetails')->once()->with('job-123')->andReturn($response);
        }

        $this->instance(Job::class, $jobApi);
    }
}
