<?php

declare(strict_types=1);

namespace Tests\Unit\EHealth;

use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthRateLimitedException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EHealthQueueRateLimitTest extends TestCase
{
    public function test_remote_job_payload_has_no_dispatch_deadline_and_can_run_after_downtime(): void
    {
        $job = new RemoteEHealthLinksProcessing(new EhealthLink(), new LegalEntity(), standalone: true);
        $payload = null;
        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$payload): void {
            $payload = $event->job->payload();
        });
        // Serialize through the public queue API without running the remote operation or touching the database.
        $handler = Mockery::mock(CallQueuedHandler::class);
        $handler->shouldReceive('call')->once();
        app()->instance(CallQueuedHandler::class, $handler);
        Queue::connection('sync')->push($job);
        $this->assertNull($payload['retryUntil']);
        $this->assertSame(0, $payload['maxTries']);
        $this->assertSame(3, $payload['maxExceptions']);
        $this->travel(2)->days();

        $queued = Mockery::mock(Job::class, \Illuminate\Contracts\Queue\Job::class);
        $queued->shouldReceive('payload')->andReturn($payload);
        $queued->shouldReceive('retryUntil')->andReturn($payload['retryUntil']);
        $queued->shouldReceive('maxTries')->andReturn($payload['maxTries']);
        $queued->shouldReceive('attempts')->andReturn(100);
        $queued->shouldNotReceive('fail');
        $queued->shouldReceive('isDeleted')->andReturn(false);
        $queued->shouldReceive('fire')->once();
        app('queue.worker')->process('database', $queued, new WorkerOptions(maxTries: 3));
    }

    public function test_releases_do_not_consume_exception_budget_but_three_real_errors_still_fail(): void
    {
        Cache::flush();
        $worker = app('queue.worker');
        $worker->setCache(Cache::store());
        $queued = Mockery::mock(Job::class, \Illuminate\Contracts\Queue\Job::class);
        $queued->shouldReceive('payload')->andReturn([]);
        $queued->shouldReceive('maxTries')->andReturn(0);
        $queued->shouldReceive('retryUntil')->andReturn(null);
        $queued->shouldReceive('uuid')->andReturn('synthetic-queue-job');
        $queued->shouldReceive('maxExceptions')->andReturn(3);
        $exception = new RuntimeException('Synthetic request failure');
        $failed = false;
        $queued->shouldReceive('fail')->once()->with($exception)->andReturnUsing(function () use (&$failed): void {
            $failed = true;
        });
        $queued->shouldReceive('hasFailed')->andReturnUsing(function () use (&$failed): bool {
            return $failed;
        });
        $queued->shouldReceive('isDeleted')->andReturn(false);
        $queued->shouldReceive('isReleased')->andReturn(true);
        $queued->shouldReceive('fire')->times(20);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $worker->process('database', $queued, new WorkerOptions(maxTries: 3));
        }
        $this->assertFalse($failed);
        $queued->shouldReceive('fire')->times(3)->andThrow($exception);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $worker->process('database', $queued, new WorkerOptions(maxTries: 3));
                $this->fail('Real exceptions must propagate.');
            } catch (RuntimeException $caught) {
                $this->assertSame($exception, $caught);
            }
            $this->assertSame($attempt === 3, $failed);
        }
    }

    public function test_429_releases_without_processing_or_chaining_and_can_later_succeed(): void
    {
        $job = new RateLimitJobHarness(new LegalEntity(), standalone: true);
        [$job, $batch] = $job->withFakeBatch();
        $batch->options['token'] = Crypt::encryptString('synthetic-token');
        $job->withFakeQueueInteractions();
        $job->failure = new EHealthResponseException(new Response(new PsrResponse(429, ['Retry-After' => '90'])));
        $this->runQuietly($job);
        $job->assertReleased(90);
        $this->assertSame(0, $job->processed);
        $this->assertSame(0, $job->nextLookups);

        $job->failure = null;
        $job->withFakeQueueInteractions();
        $this->runQuietly($job);
        $job->assertNotReleased();
        $this->assertSame(1, $job->processed);
        $this->assertSame(1, $job->nextLookups);
    }

    public function test_local_capacity_wait_releases_without_processing_or_chaining(): void
    {
        $job = new RateLimitJobHarness(new LegalEntity(), standalone: true);
        [$job, $batch] = $job->withFakeBatch();
        $batch->options['token'] = Crypt::encryptString('synthetic-token');
        $job->withFakeQueueInteractions();
        $job->failure = new EHealthRateLimitedException(61);
        $this->runQuietly($job);
        $job->assertReleased(61);
        $this->assertSame(0, $job->processed);
        $this->assertSame(0, $job->nextLookups);
    }

    public function test_non_throttling_errors_are_still_thrown(): void
    {
        $job = new RateLimitJobHarness(new LegalEntity(), standalone: true);
        [$job, $batch] = $job->withFakeBatch();
        $batch->options['token'] = Crypt::encryptString('synthetic-token');
        $job->failure = new EHealthResponseException(new Response(new PsrResponse(403)));
        $this->expectException(EHealthResponseException::class);
        $this->runQuietly($job);
    }

    public static function pendingPolls(): array
    {
        return ['initial' => [1, 5], 'second' => [2, 10], 'capped' => [10, 300], 'long running' => [100, 300]];
    }

    #[DataProvider('pendingPolls')]
    public function test_pending_poll_keeps_the_remote_outcome_unconfirmed(int $attempts, int $delay): void
    {
        $link = new EhealthLink(['status' => 'PENDING']);
        $job = new PendingRemoteJobHarness($link, new LegalEntity(), standalone: true);
        $queued = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queued->shouldReceive('attempts')->andReturn($attempts);
        $queued->shouldReceive('release')->once()->with($delay);
        $job->setJob($queued);
        $response = new EHealthResponse(new PsrResponse(200), static fn (): array => ['status' => 'pending']);
        $job->apply($response);
        $this->assertSame('PENDING', $link->status);
        $this->assertNull($job->next());
    }

    private function runQuietly(EHealthJob $job): void
    {
        ob_start();
        try {
            $job->handle();
        } finally {
            ob_end_clean();
        }
    }
}

class RateLimitJobHarness extends EHealthJob
{
    public EHealthResponseException|EHealthRateLimitedException|null $failure = null;
    public int $processed = 0;
    public int $nextLookups = 0;

    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return null;
    }

    protected function processResponse(?EHealthResponse $response): void
    {
        $this->processed++;
    }

    protected function getNextEntityJob(): ?EHealthJob
    {
        $this->nextLookups++;

        return null;
    }

    protected function setEntityStatus(JobStatus $status): void
    {
    }

    protected function getAdditionalMiddleware(): array
    {
        return [];
    }
}

class PendingRemoteJobHarness extends RemoteEHealthLinksProcessing
{
    public function apply(EHealthResponse $response): void
    {
        $this->processResponse($response);
    }

    public function next(): ?EHealthJob
    {
        return $this->getNextEntityJob();
    }
}
